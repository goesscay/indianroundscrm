<?php

namespace App\Services;

use App\Models\TransportLine;
use Illuminate\Support\Carbon;

/**
 * Road transport on a premium quotation (the Transport card's Road transport tab), priced from the Operations rate list.
 * One plan per quotation: a vehicle for the stay or a vehicle for each day, plus added transfers, buses and ferries.
 * The rate is found by destination, vehicle and travel date (seasons inside the line); foreign prices convert at the quotation's exchange rates;
 * the destination margin is added. Flights and trains are never here: they carry no price.
 */
class RoadPricing
{
    /** What each add button offers from the rate list. */
    public const KINDS = ['transfer' => ['cab', 'van'], 'bus' => ['bus'], 'ferry' => ['ferry'], 'vehicle' => ['cab', 'van']];
    public const MODES = ['stay', 'daily'];

    /** @var array<int,TransportLine|null> */
    private static array $lines = [];

    public static function flush(): void
    {
        self::$lines = [];
    }

    private static function id(mixed $v): ?int
    {
        return is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function int(mixed $v, int $min, int $max, ?int $default = null): ?int
    {
        return $v === null || $v === '' || ! is_numeric($v) ? $default : max($min, min($max, (int) $v));
    }

    /** The road plan as the screen sent it, made safe. It always has one stay block, so the screen has somewhere to start. @return array<string,mixed> */
    public static function clean(mixed $r, ?string $tripDestination = null): array
    {
        $r = (array) $r;
        $stays = [];
        foreach (array_slice((array) ($r['stays'] ?? []), 0, 6) as $s) {
            $s = (array) $s;
            $stays[] = ['destination' => trim(mb_substr((string) ($s['destination'] ?? ''), 0, 120)) ?: ($tripDestination ?: null), 'line_id' => self::id($s['line_id'] ?? null),
                'from_day' => self::int($s['from_day'] ?? 1, 1, 90, 1), 'days' => self::int($s['days'] ?? null, 1, 90), 'qty' => self::int($s['qty'] ?? null, 1, 50)];
        }
        if (! $stays) {
            $stays[] = ['destination' => $tripDestination ?: null, 'line_id' => null, 'from_day' => 1, 'days' => null, 'qty' => null];
        }
        $daily = [];
        foreach (array_slice((array) ($r['daily'] ?? []), 0, 90) as $d) {
            $d = (array) $d;
            $daily[] = ['day' => self::int($d['day'] ?? 1, 1, 90, 1), 'line_id' => self::id($d['line_id'] ?? null), 'qty' => self::int($d['qty'] ?? null, 1, 50)];
        }
        $extras = [];
        foreach (array_slice((array) ($r['extras'] ?? []), 0, 20) as $i => $x) {
            $x = (array) $x;
            $kind = (string) ($x['kind'] ?? '');
            if (! isset(self::KINDS[$kind])) {
                continue;
            }
            $extras[] = ['id' => trim(mb_substr((string) ($x['id'] ?? ''), 0, 40)) ?: 'x'.($i + 1), 'kind' => $kind, 'line_id' => self::id($x['line_id'] ?? null), 'qty' => self::int($x['qty'] ?? null, 1, 50),
                'day' => self::int($x['day'] ?? 1, 1, 90, 1), 'way' => ($x['way'] ?? 'one_way') === 'return' ? 'return' : 'one_way'];
        }

        return ['mode' => in_array($r['mode'] ?? '', self::MODES, true) ? $r['mode'] : 'stay', 'stays' => $stays, 'daily' => $daily, 'extras' => $extras];
    }

    private static function line(int $id): ?TransportLine
    {
        return self::$lines[$id] ??= TransportLine::with(['destination', 'seasons'])->find($id);
    }

    /**
     * The price of one unit on a day: the dated season that covers it, else the line's all-year price. A line whose only price is dated, or a day
     * outside every season, has no rate. Without a travel date only a line with no dates at all can be priced.
     *
     * @return array{0:?float,1:?string} price and the season's name
     */
    public static function unitPrice(TransportLine $l, ?string $date): array
    {
        $periods = [];
        if ($l->price !== null && $l->price > 0) {
            $periods[] = ['from' => $l->valid_from?->toDateString(), 'to' => $l->valid_to?->toDateString(), 'price' => (float) $l->price, 'name' => $l->season_name];
        }
        foreach ($l->seasons as $s) {
            if ($s->valid_from && $s->valid_to) {                                   // a season without dates is kept but not used yet
                $periods[] = ['from' => $s->valid_from->toDateString(), 'to' => $s->valid_to->toDateString(), 'price' => (float) $s->price, 'name' => $s->name];
            }
        }
        $dated = array_values(array_filter($periods, fn ($p) => $p['from'] && $p['to']));
        $open = array_values(array_filter($periods, fn ($p) => ! $p['from'] && ! $p['to']));
        if ($date === null) {
            return $dated ? [null, null] : (($open[0] ?? null) ? [$open[0]['price'], $open[0]['name']] : [null, null]);
        }
        foreach ($dated as $p) {
            if ($p['from'] <= $date && $p['to'] >= $date) {
                return [$p['price'], $p['name']];
            }
        }

        return $open ? [$open[0]['price'], $open[0]['name']] : [null, null];
    }

    private static function label(string $d): string
    {
        return Carbon::parse($d)->format('j M Y');
    }

    /**
     * Price the road plan for one group.
     *
     * @param  array<string,mixed>  $trip  the cleaned trip (start_date, nights, destination)
     * @param  array<string,mixed>  $road  the cleaned road plan
     * @param  array<string,mixed>  $fx  the quotation's exchange rates
     * @param  bool  $actual  the real group: a quantity typed by sales is used as it is; the group columns use at least the vehicles the group needs
     * @return array{selling:float,cost:float,margin_amount:float,by_mode:array<string,float>,rows:list<array<string,mixed>>,warnings:list<string>,fx_needed:list<string>}
     */
    public static function price(array $trip, int $itineraryDays, array $road, array $fx, int $adults, int $cb, int $cn, bool $actual): array
    {
        $persons = $adults + $cb + $cn;
        $start = $trip['start_date'] ?? null;
        $dayCount = $itineraryDays > 0 ? $itineraryDays : ((int) ($trip['nights'] ?? 0) + 1);
        $dateOf = fn (int $day) => $start ? Carbon::parse($start)->addDays($day - 1)->toDateString() : null;
        $rows = [];
        $warnings = [];
        $fxNeeded = [];
        $byMode = [];
        $sell = $cost = $marginAmt = 0.0;

        // one priced row for a rate line over some days
        $make = function (string $source, ?int $lineId, int $fromDay, int $days, ?int $qtyIn, string $way, string $key) use (&$rows, &$warnings, &$fxNeeded, &$byMode, &$sell, &$cost, &$marginAmt, $persons, $adults, $actual, $dateOf, $start, $fx): void {
            $l = $lineId ? self::line($lineId) : null;
            if (! $l) {
                if ($lineId) {
                    $warnings[] = "Day $fromDay: the transport rate is no longer in the rate list.";
                }

                return;
            }
            $row = ['key' => $key, 'source' => $source, 'line_id' => (string) $l->id, 'type' => $l->type, 'name' => $l->name, 'destination' => $l->destination?->name, 'from_name' => $l->from_name, 'to_name' => $l->to_name,
                'charged' => $l->charged, 'way' => $way, 'day' => $fromDay, 'days' => $days, 'date' => $dateOf($fromDay), 'persons' => $persons, 'qty' => null, 'unit_selling' => null, 'selling' => 0.0, 'cost' => 0.0,
                'margin_amount' => 0.0, 'currency' => $l->currency, 'rate_needed' => false, 'warnings' => []];
            $w = [];
            $auto = $l->seats ? max(1, (int) ceil($persons / $l->seats)) : 1;
            $units = $l->charged === 'per_person' ? $persons : ($actual ? ($qtyIn ?? $auto) : max($qtyIn ?? 0, $auto));
            $legs = $way === 'return' ? ($l->way === 'return' ? 1 : 2) : 1;                 // a return journey on a one-way price is paid twice
            $raw = null;
            if ($l->charged === 'per_day') {
                $raw = 0.0;
                for ($k = 0; $k < $days; $k++) {
                    $date = $dateOf($fromDay + $k);
                    [$p] = self::unitPrice($l, $date);
                    if ($p === null) {
                        $w[] = $date ? 'Rate needed: '.$l->name.' has no rate for '.self::label($date).'.' : 'Set the travel date so the transport rates can be found.';
                        $raw = null;
                        break;
                    }
                    $raw += $p * $units;
                }
            } else {
                [$p] = self::unitPrice($l, $dateOf($fromDay));
                if ($p === null) {
                    $w[] = $dateOf($fromDay) ? 'Rate needed: '.$l->name.' has no rate for '.self::label($dateOf($fromDay)).'.' : 'Set the travel date so the transport rates can be found.';
                } else {
                    $raw = $p * $units * $legs;
                }
            }
            $row['qty'] = $units;
            if ($raw === null) {
                $row['rate_needed'] = true;
            } else {
                $rate = TransportPricing::fxRate($l->currency, $fx);
                if ($rate === null) {
                    $fxNeeded[$l->currency] = true;
                    $row['rate_needed'] = true;
                    $w[] = 'Exchange rate needed for '.$l->currency.' ('.$l->name.').';
                } else {
                    $c = round($raw * $rate, 2);
                    $m = HotelPricing::marginForDestination($l->destination?->name);
                    $ma = $m['type'] === 'amount' ? $m['value'] * max(1, $persons) : $c * $m['value'] / 100;
                    $selling = round($c + $ma, 2);
                    $divisor = $l->charged === 'per_day' ? max(1, $units * $days) : max(1, $units * $legs);
                    $row = array_merge($row, ['cost' => $c, 'margin_amount' => round($ma, 2), 'selling' => $selling, 'unit_selling' => round($selling / $divisor, 2)]);
                    $sell += $selling;
                    $cost += $c;
                    $marginAmt += $ma;
                    $byMode[$l->type] = round(($byMode[$l->type] ?? 0) + $selling, 2);
                }
            }
            $row['warnings'] = $w;
            foreach ($w as $x) {
                $warnings[] = $x;
            }
            $rows[] = $row;
        };

        if ($road['mode'] === 'stay') {
            foreach ($road['stays'] as $i => $s) {
                if ($s['line_id']) {
                    $days = $s['days'] ?? max(1, $dayCount - $s['from_day'] + 1);
                    $make('stay', $s['line_id'], $s['from_day'], $days, $s['qty'], 'one_way', 'stay'.$i);
                }
            }
        } else {
            foreach ($road['daily'] as $i => $d) {
                if ($d['line_id']) {
                    $make('daily', $d['line_id'], $d['day'], 1, $d['qty'], 'one_way', 'day'.$d['day'].'-'.$i);
                }
            }
        }
        foreach ($road['extras'] as $x) {
            if ($x['line_id']) {
                $make('extra', $x['line_id'], $x['day'], 1, $x['qty'], $x['way'], $x['id']);
            }
        }
        usort($rows, fn ($a, $b) => $a['day'] <=> $b['day']);

        return ['selling' => round($sell, 2), 'cost' => round($cost, 2), 'margin_amount' => round($marginAmt, 2), 'by_mode' => $byMode, 'rows' => $rows,
            'warnings' => array_values(array_unique($warnings)), 'fx_needed' => array_keys($fxNeeded)];
    }
}
