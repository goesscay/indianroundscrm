<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\Media;
use Illuminate\Support\Carbon;

/**
 * The premium proposal: cleaning what the builder sends, working out the price per person for each group size and each hotel option,
 * and the quote lines for the option the customer is looking at. Costs and margins are returned only when asked for (Operations).
 */
class ProposalPricing
{
    private static function lines(mixed $v, int $max = 60, int $len = 800): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            $x = trim((string) $x);
            if ($x !== '') {
                $out[] = mb_substr($x, 0, $len);
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    private static function money(mixed $v): float
    {
        return round(max(0.0, min(100000000.0, (float) $v)), 2);
    }

    /** Whatever the screen sent, made safe and tidy. */
    public static function clean(array $p): array
    {
        $t = (array) ($p['trip'] ?? []);
        $start = (string) ($t['start_date'] ?? '');
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && strtotime($start) ? $start : null;
        $sizes = collect((array) ($t['group_sizes'] ?? [2, 4, 6]))->map(fn ($n) => (int) $n)->filter(fn ($n) => $n >= 1 && $n <= 60)->unique()->sort()->values()->take(6)->all();
        $trip = [
            'destination' => mb_substr(trim((string) ($t['destination'] ?? '')), 0, 120), 'start_date' => $start,
            'nights' => max(0, min(90, (int) ($t['nights'] ?? 0))), 'adults' => max(1, min(100, (int) ($t['adults'] ?? 2))),
            'children_bed' => max(0, min(50, (int) ($t['children_bed'] ?? 0))), 'children_no_bed' => max(0, min(50, (int) ($t['children_no_bed'] ?? 0))),
            'group_sizes' => $sizes ?: [2, 4, 6],
        ];

        $mediaIds = [];
        $mediaId = function ($v) use (&$mediaIds) {
            $id = is_scalar($v) && ctype_digit((string) $v) ? (int) $v : null;
            if ($id) {
                $mediaIds[$id] = true;
            }

            return $id;
        };
        $days = [];
        foreach (array_slice((array) ($p['days'] ?? []), 0, 40) as $d) {
            $d = (array) $d;
            $days[] = ['title' => mb_substr(trim((string) ($d['title'] ?? '')), 0, 200), 'stay' => mb_substr(trim((string) ($d['stay'] ?? '')), 0, 120),
                'bullets' => self::lines($d['bullets'] ?? [], 20, 600), 'media_id' => $mediaId($d['media_id'] ?? null)];
        }
        $days = array_values(array_filter($days, fn ($d) => $d['title'] !== '' || $d['bullets']));

        $options = [];
        foreach (array_slice((array) ($p['options'] ?? []), 0, 4) as $i => $o) {
            $o = (array) $o;
            $stays = [];
            foreach (array_slice((array) ($o['stays'] ?? []), 0, 8) as $s) {
                $s = (array) $s;
                $meal = (string) ($s['meal_plan'] ?? 'CP');
                $stays[] = [
                    'hotel_id' => ctype_digit((string) ($s['hotel_id'] ?? '')) ? (int) $s['hotel_id'] : null, 'room_id' => ctype_digit((string) ($s['room_id'] ?? '')) ? (int) $s['room_id'] : null,
                    'meal_plan' => isset(HotelRate::MEALS[$meal]) ? $meal : 'CP', 'start_offset' => max(0, min(89, (int) ($s['start_offset'] ?? 0))), 'nights' => max(1, min(60, (int) ($s['nights'] ?? 1))),
                ];
            }
            $options[] = ['name' => mb_substr(trim((string) ($o['name'] ?? '')) ?: 'Option '.($i + 1), 0, 80), 'stays' => array_values(array_filter($stays, fn ($s) => $s['hotel_id'] && $s['room_id'])),
                'legs' => TransportPricing::cleanLegs($o['legs'] ?? [])];
        }
        if (! $options) {
            $options[] = ['name' => 'Option 1', 'stays' => [], 'legs' => []];
        }

        $rows = [];
        foreach (array_slice((array) (($p['land']['rows'] ?? [])), 0, 10) as $r) {
            $r = (array) $r;
            $rows[] = ['pax' => max(1, min(100, (int) ($r['pax'] ?? 1))), 'cost_pp' => self::money($r['cost_pp'] ?? 0), 'child_cost_pp' => isset($r['child_cost_pp']) && $r['child_cost_pp'] !== '' && $r['child_cost_pp'] !== null ? self::money($r['child_cost_pp']) : null];
        }
        usort($rows, fn ($a, $b) => $a['pax'] <=> $b['pax']);

        $c = (array) ($p['content'] ?? []);
        $canc = (array) ($c['cancellation'] ?? []);
        $tiers = [];
        foreach (array_slice((array) ($canc['tiers'] ?? []), 0, 12) as $x) {
            $x = (array) $x;
            if (trim((string) ($x['label'] ?? '')) !== '') {
                $tiers[] = ['label' => mb_substr(trim((string) $x['label']), 0, 160), 'percent' => max(0.0, min(100.0, (float) ($x['percent'] ?? 0)))];
            }
        }
        $content = [
            'inclusions' => self::lines($c['inclusions'] ?? []), 'exclusions' => self::lines($c['exclusions'] ?? []), 'notes' => self::lines($c['notes'] ?? []),
            'terms' => self::lines($c['terms'] ?? []), 'closing' => self::lines($c['closing'] ?? [], 6, 200), 'cancellation' => ['tiers' => $tiers, 'items' => self::lines($canc['items'] ?? [], 20)],
        ];

        // only pictures that exist
        $valid = $mediaIds ? Media::whereIn('id', array_keys($mediaIds))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        foreach ($days as &$d) {
            if ($d['media_id'] && ! in_array($d['media_id'], $valid, true)) {
                $d['media_id'] = null;
            }
        }
        unset($d);
        $cover = $mediaId($p['cover_media_id'] ?? null);
        if ($cover && ! Media::whereKey($cover)->exists()) {
            $cover = null;
        }

        return [
            'trip' => $trip, 'days' => $days, 'options' => $options, 'land' => ['label' => mb_substr(trim((string) ($p['land']['label'] ?? '')), 0, 200), 'rows' => $rows],
            'gst_rate' => max(0.0, min(100.0, (float) ($p['gst_rate'] ?? 5))), 'selected_option' => max(0, min(count($options) - 1, (int) ($p['selected_option'] ?? 0))),
            'content' => $content, 'cover_media_id' => $cover, 'transport' => TransportPricing::cleanSettings($p['transport'] ?? []),
            'reach' => TransportReach::clean($p['reach'] ?? []),                // flights and trains as suggestions: details only, never priced
        ];
    }

    /** The land package row for a group: the smallest row that covers the group, else the biggest. */
    private static function landRow(array $rows, int $pax): ?array
    {
        foreach ($rows as $r) {
            if ($r['pax'] >= $pax) {
                return $r;
            }
        }

        return $rows ? $rows[count($rows) - 1] : null;
    }

    /** @return array<string,mixed> one group priced for one option */
    private static function price(array $trip, array $land, array $stays, array $legs, array $transport, int $adults, int $cb, int $cn, bool $actual = false): array
    {
        $rows = [];
        $sell = $cost = $marginAmt = 0.0;
        $warnings = [];
        $start = $trip['start_date'] ?? null;
        foreach ($stays as $s) {
            /** @var HotelRoom|null $room */
            $room = $s['room'];
            if (! $room) {
                continue;
            }
            if (! $start) {
                $warnings[] = 'Set the travel date so the hotel rates can be found.';
                continue;
            }
            $checkIn = Carbon::parse($start)->addDays($s['start_offset'])->toDateString();
            $e = HotelPricing::estimate($room, $s['meal_plan'], $checkIn, $s['nights'], $adults, $cb, $cn);
            $rows[] = ['hotel_id' => (string) $room->hotel_id, 'hotel' => $room->hotel->name, 'room_id' => (string) $room->id, 'room' => $room->name, 'meal_plan' => $s['meal_plan'],
                'check_in' => $checkIn, 'nights' => $s['nights'], 'selling' => $e['selling'], 'cost' => $e['cost'], 'margin_amount' => $e['margin_amount'], 'rooms' => $e['rooms']];
            $sell += $e['selling'];
            $cost += $e['cost'];
            $marginAmt += $e['margin_amount'];
            foreach ($e['warnings'] as $w) {
                $warnings[] = $room->hotel->name.': '.$w;
            }
        }
        $persons = $adults + $cb + $cn;
        $row = self::landRow($land['rows'], $adults);
        $landCost = $row ? $row['cost_pp'] * $adults + ($cb + $cn) * ($row['child_cost_pp'] ?? $row['cost_pp']) : 0.0;
        $m = HotelPricing::marginForDestination($trip['destination'] ?: null);
        $landMargin = $m['type'] === 'amount' ? $m['value'] * max(1, $persons) : $landCost * $m['value'] / 100;
        $landSell = round($landCost + $landMargin, 2);
        $tr = TransportPricing::price($legs, $trip, $transport, $adults, $cb, $cn, $actual);
        $total = round($sell + $landSell + $tr['selling'], 2);

        return [
            'persons' => $persons, 'adults' => $adults, 'stays' => $rows, 'land_selling' => $landSell, 'transport' => $tr, 'hotel_selling' => round($sell, 2),
            'total' => $total, 'per_person' => $persons > 0 ? (float) ceil($total / $persons) : 0.0,
            'cost' => round($cost + $landCost + $tr['cost'], 2), 'margin_amount' => round($marginAmt + $landMargin + $tr['margin_amount'], 2), 'warnings' => array_merge($warnings, $tr['warnings']),
        ];
    }

    /**
     * @return array{options:list<array<string,mixed>>,selected:int,gst_rate:float,warnings:list<string>}
     */
    public static function compute(array $p, bool $withCost): array
    {
        TransportPricing::flush();                              // rates may have changed since the last quotation was priced in this process
        $trip = $p['trip'];
        $warnings = [];
        $out = [];
        foreach ($p['options'] as $o) {
            $stays = [];
            foreach ($o['stays'] as $s) {
                $room = HotelRoom::with(['hotel', 'rates'])->find($s['room_id']);
                if (! $room || (int) $room->hotel_id !== (int) $s['hotel_id']) {
                    $warnings[] = 'A hotel or room in '.$o['name'].' is no longer in the master.';
                    continue;
                }
                $stays[] = $s + ['room' => $room];
            }
            $tiers = [];
            foreach ($trip['group_sizes'] as $g) {
                $r = self::price($trip, $p['land'], $stays, $o['legs'], $p['transport'], $g, 0, 0);
                $tiers[] = $r + ['pax' => $g];
            }
            $actual = self::price($trip, $p['land'], $stays, $o['legs'], $p['transport'], $trip['adults'], $trip['children_bed'], $trip['children_no_bed'], true);
            foreach (array_merge($tiers, [$actual]) as $r) {
                foreach ($r['warnings'] as $w) {
                    $warnings[] = $o['name'].': '.$w;
                }
            }
            $out[] = ['name' => $o['name'], 'tiers' => $tiers, 'actual' => $actual];
        }
        $out = array_map(function ($o) use ($withCost) {
            $strip = function (array $r) use ($withCost) {
                unset($r['warnings'], $r['transport']['warnings']);
                if (! $withCost) {
                    unset($r['cost'], $r['margin_amount']);
                    unset($r['transport']['cost'], $r['transport']['margin_amount']);
                    $r['transport']['legs'] = array_map(function ($l) {
                        unset($l['cost'], $l['margin_amount']);

                        return $l;
                    }, $r['transport']['legs']);
                    $r['stays'] = array_map(function ($s) {
                        unset($s['cost'], $s['margin_amount']);

                        return $s;
                    }, $r['stays']);
                }

                return $r;
            };

            return ['name' => $o['name'], 'tiers' => array_map($strip, $o['tiers']), 'actual' => $strip($o['actual'])];
        }, $out);

        return ['options' => $out, 'selected' => $p['selected_option'], 'gst_rate' => $p['gst_rate'], 'warnings' => array_values(array_unique($warnings))];
    }

    /** The quote lines for the option being offered, priced for the actual group: one per hotel stay, and the land package. */
    public static function quoteLines(array $p, array $computed): array
    {
        $opt = $computed['options'][$p['selected_option']] ?? null;
        if (! $opt) {
            return [];
        }
        $lines = [];
        foreach ($opt['actual']['stays'] as $s) {
            $lines[] = ['kind' => 'hotel', 'description' => sprintf('%s: %s, %s, %d night%s', $s['hotel'], $s['room'], strtolower(HotelRate::MEALS[$s['meal_plan']]), $s['nights'], $s['nights'] === 1 ? '' : 's'),
                'qty' => 1, 'unit_price' => $s['selling'], 'discount' => 0, 'tax_rate' => $p['gst_rate']];
        }
        foreach ($opt['actual']['transport']['legs'] as $l) {
            if ($l['included'] && $l['selling'] > 0) {
                $lines[] = ['kind' => $l['mode'] === 'flight' ? 'flight' : 'transfer', 'description' => sprintf('%s (Day %d)', $l['description'] ?: ucfirst($l['mode']), $l['day']), 'qty' => 1,
                    'unit_price' => $l['selling'], 'discount' => 0, 'tax_rate' => $p['gst_rate']];
            }
        }
        if ($opt['actual']['land_selling'] > 0) {
            $lines[] = ['kind' => 'package', 'description' => $p['land']['label'] ?: 'Land package: transfers and sightseeing', 'qty' => 1,
                'unit_price' => $opt['actual']['land_selling'], 'discount' => 0, 'tax_rate' => $p['gst_rate']];
        }

        return $lines;
    }

    /** What the older quote page and PDF show: the itinerary and the text sections as plain lines. @return array<string,mixed> */
    public static function legacyFields(array $p, ?array $computed = null): array
    {
        $c = $p['content'];
        $j = $computed ? TransportJourney::build($p, $computed) : null;
        if ($j) {
            $c['inclusions'] = TransportJourney::merge($c['inclusions'], $j['inclusions']);
            $c['exclusions'] = TransportJourney::merge($c['exclusions'], $j['exclusions']);
            $c['notes'] = TransportJourney::merge($c['notes'], $j['notes']);
        }
        $canc = array_merge(array_map(fn ($t) => $t['label'].': '.rtrim(rtrim(number_format($t['percent'], 2, '.', ''), '0'), '.').'% cancellation charge', $c['cancellation']['tiers']), $c['cancellation']['items']);

        return [
            'itinerary' => array_map(fn ($d) => ['title' => $d['title'], 'description' => implode("\n", $d['bullets'])], $p['days']),
            'inclusions' => implode("\n", $c['inclusions']), 'exclusions' => implode("\n", $c['exclusions']),
            'terms' => implode("\n", array_merge($canc ? ['Cancellation policy'] : [], $canc, $c['notes'] ? ['', 'Notes'] : [], $c['notes'], $c['terms'] ? ['', 'Terms and conditions'] : [], $c['terms'])),
        ];
    }
}
