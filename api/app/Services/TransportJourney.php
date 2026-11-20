<?php

namespace App\Services;

use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use Illuminate\Support\Carbon;

/**
 * What the customer sees of the transport on a premium quotation: a block per destination with a line per leg (date, mode, description, "Included"),
 * a summary and total, the inclusions and notes that follow from the legs, and the currency note. It is built from the quoted option and carries
 * no provider name, vendor cost or margin, so it is the same for the PDF and the screen.
 */
class TransportJourney
{
    public const GENERAL = ['flight' => 'Flight', 'train' => 'Train', 'bus' => 'Bus', 'cab' => 'Private cab', 'van' => 'Private tempo traveller / van', 'ferry' => 'Ferry'];

    /** @var array<int,TransportVehicle|null> */
    private static array $vehicles = [];

    /** @var array<int,TransportRoute|null> */
    private static array $routes = [];

    private static function vehicle(?int $id): ?TransportVehicle
    {
        return $id ? (self::$vehicles[$id] ??= TransportVehicle::find($id)) : null;
    }

    private static function route(?int $id): ?TransportRoute
    {
        return $id ? (self::$routes[$id] ??= TransportRoute::find($id)) : null;
    }

    private static function days(int $from, int $count): string
    {
        return $count > 1 ? 'Days '.$from.' to '.($from + $count - 1) : 'Day '.$from;
    }

    /** "Volvo seat" -> "Volvo bus"; the vehicle's own name for everything else. */
    private static function named(string $mode, ?TransportVehicle $v): string
    {
        if (! $v) {
            return self::GENERAL[$mode];
        }
        $n = trim(preg_replace('/\s+seats?$/i', '', $v->name));

        return match ($mode) {
            'cab' => 'Private '.$n,
            'bus' => stripos($n, 'bus') === false ? $n.' bus' : $n,
            default => $n,
        };
    }

    /**
     * @param  array<string,mixed>  $p  the cleaned proposal
     * @param  array<string,mixed>  $computed  ProposalPricing::compute() output (costs and margins are not used)
     * @return array<string,mixed>|null null when the quoted option has no transport
     */
    public static function build(array $p, array $computed): ?array
    {
        self::$vehicles = self::$routes = [];
        $sel = (int) $p['selected_option'];
        $legs = $p['options'][$sel]['legs'] ?? [];
        $roadRows = array_values(array_filter($computed['options'][$sel]['actual']['road']['rows'] ?? [], fn ($r) => ! $r['rate_needed']));
        if (! $legs && ! $roadRows) {
            return null;
        }
        $t = $p['transport'];
        $named = ($t['wording'] ?? 'named') === 'named';
        $trip = $p['trip'];
        $persons = $trip['adults'] + $trip['children_bed'] + $trip['children_no_bed'];
        $start = $trip['start_date'] ? Carbon::parse($trip['start_date']) : null;
        $priced = collect($computed['options'][$sel]['actual']['transport']['legs'] ?? [])->keyBy('id');

        usort($legs, fn ($a, $b) => $a['day_offset'] <=> $b['day_offset']);
        $blocks = [];
        $summary = [];
        $byDay = [];
        $inc = [];
        $exc = [];
        $foreign = [];
        $typed = $master = false;

        foreach ($legs as $l) {
            $pl = $priced->get($l['id']);
            $isTyped = in_array($l['mode'], TransportPricing::TYPED, true);
            if ($isTyped ? $l['fare_pp'] === null : (! $pl || $pl['rate_needed'])) {
                continue;                                                // nothing is shown that could not be priced
            }
            $day = $l['day_offset'] + 1;
            $date = $start?->copy()->addDays($l['day_offset']);
            $included = (bool) $l['included'];
            $dest = $l['destination'] ?: ($trip['destination'] ?: 'Your trip');
            $label = self::GENERAL[$l['mode']];
            $title = $detail = '';
            $rt = null;

            if ($isTyped) {
                $typed = true;
                $path = trim(($l['from'] ?? '').($l['from'] && $l['to'] ? ' to ' : '').($l['to'] ?? ''));
                $title = trim($l['title'] ?: ($path ?: $label));
                $parts = array_filter([$l['title'] && $path ? $path : null, $l['depart'] ? 'departs '.$l['depart'] : null, $l['arrive'] ? 'arrives '.$l['arrive'] : null, $l['class'], $l['baggage'] ? 'baggage '.$l['baggage'] : null]);
                $detail = implode(', ', $parts);
                $inc[] = $label.': '.($path ?: $title).($l['title'] && $path ? ' ('.$l['title'].')' : '');
                if ($l['currency'] !== 'INR') {
                    $foreign[$l['currency']] = true;
                }
            } else {
                $master = true;
                $veh = self::vehicle($l['vehicle_id']);
                $rt = self::route($l['route_id']);
                $label = $named ? self::named($l['mode'], $veh) : self::GENERAL[$l['mode']];
                $perPerson = ($pl['basis'] ?? null) === 'per_person';
                $return = $l['legs'] === 2;
                $title = $rt?->title ?: $label;
                $bits = [];
                if ($rt?->duration) {
                    $bits[] = $rt->duration;                                    // the route title is already the line's title
                }
                if ($perPerson) {
                    $bits[] = $persons.' '.($persons === 1 ? 'person' : 'persons').($return ? ', return' : ', one way');
                } else {
                    $qty = (int) ($pl['qty'] ?? 1);
                    $bits[] = $qty > 1 ? $qty.' vehicles' : 'private vehicle';
                    if (($pl['basis'] ?? null) === 'per_transfer' && $return) {
                        $bits[] = 'return';
                    }
                    if (in_array($l['mode'], ['cab', 'van'], true) && $l['days'] > 1) {
                        $bits[] = $l['days'].' days';
                    }
                }
                $detail = implode(', ', $bits);
                if ($perPerson) {
                    $inc[] = $label.' seats'.($rt ? ' '.$rt->title : '').' for all travellers'.($return ? ' (return)' : '');
                } elseif (in_array($l['mode'], ['cab', 'van'], true)) {
                    $inc[] = $label.' for '.self::days($day, $l['days']).($rt ? ' ('.$rt->title.')' : '');
                    if ($rt) {
                        $exc[] = 'Any route or sightseeing beyond the one listed.';
                    }
                } else {
                    $inc[] = $label.($rt ? ': '.$rt->title : '');
                }
                foreach (preg_split('/\r\n|\r|\n/', (string) $rt?->inclusions) ?: [] as $x) {
                    if (trim($x) !== '') {
                        $inc[] = trim($x);
                    }
                }
                if (($pl['currency'] ?? 'INR') !== 'INR') {
                    $foreign[$pl['currency']] = true;
                }
            }

            $line = ['day' => $day, 'date' => $date?->toDateString(), 'mode' => $l['mode'], 'label' => $label, 'title' => $title, 'detail' => $detail, 'included' => $included, 'tag' => $included ? 'Included' : 'For information'];
            $blocks[$dest][] = $line;
            $byDay[$day][] = $line;
            $summary[] = ['service' => $label, 'details' => trim($title.($detail && $detail !== $title ? ' · '.$detail : ''), ' ·'), 'included' => $included];
        }

        // the Transport card's road plan: the same lines, from the rate list
        foreach ($roadRows as $r) {
            $master = true;
            [$veh, $route] = ($r['car_name'] ?? null) ? [trim((string) $r['car_name']), trim((string) $r['name'])] : self::splitName($r['name']);       // a rate with a car name keeps it apart; an older one carries both in one name
            $label = $named ? match ($r['type']) {
                'cab' => 'Private '.$veh,
                'bus' => stripos($veh, 'bus') === false ? $veh.' bus' : $veh,
                default => $veh,
            } : self::GENERAL[$r['type']];
            $path = $r['from_name'] && $r['to_name'] ? $r['from_name'].' to '.$r['to_name'] : '';
            $title = $route ?: ($path ?: $label);
            $bits = [];
            $perPerson = $r['charged'] === 'per_person';
            $return = $r['way'] === 'return';
            if ($perPerson) {
                $bits[] = $r['persons'].' '.($r['persons'] === 1 ? 'person' : 'persons').($return ? ', return' : ', one way');
            } else {
                $bits[] = (int) $r['qty'] > 1 ? $r['qty'].' vehicles' : 'private vehicle';
                if ($r['charged'] === 'per_trip' && $return) {
                    $bits[] = 'return';
                }
                if ($r['charged'] === 'per_day' && $r['days'] > 1) {
                    $bits[] = $r['days'].' days';
                }
            }
            $detail = implode(', ', $bits);
            $dest = $r['destination'] ?: ($trip['destination'] ?: 'Your trip');
            if ($perPerson) {
                $inc[] = $label.' seats'.($path ? ' '.$path : '').' for all travellers'.($return ? ' (return)' : '');
            } elseif ($r['charged'] === 'per_day' && in_array($r['type'], ['cab', 'van'], true)) {
                $inc[] = $label.' for '.self::days($r['day'], $r['days']).($route ? ' ('.$route.')' : '');
                if ($route) {
                    $exc[] = 'Any route or sightseeing beyond the one listed.';
                }
            } else {
                $inc[] = $label.($route ? ': '.$route : ($path ? ': '.$path : ''));
            }
            if ($r['currency'] !== 'INR') {
                $foreign[$r['currency']] = true;
            }
            $line = ['day' => $r['day'], 'date' => $r['date'], 'mode' => $r['type'], 'label' => $label, 'title' => $title, 'detail' => $detail, 'included' => true, 'tag' => 'Included'];
            $blocks[$dest][] = $line;
            $byDay[$r['day']][] = $line;
            $summary[] = ['service' => $label, 'details' => trim($title.($detail && $detail !== $title ? ' · '.$detail : ''), ' ·'), 'included' => true];
        }
        if (! $blocks) {
            return null;
        }

        $sell = (float) ($computed['options'][$sel]['actual']['transport']['selling'] ?? 0) + (float) ($computed['options'][$sel]['actual']['road']['selling'] ?? 0);
        $gst = (float) $p['gst_rate'];
        $display = ($t['tax_display'] ?? 'with_gst') === 'before_gst' ? 'before_gst' : 'with_gst';
        $notes = [];
        if ($typed) {
            $notes[] = 'Flight and train fares and timings are indicative and are confirmed at the time of booking.';
        }
        if ($master) {
            $notes[] = 'Transport rates are valid for the travel dates in this quotation.';
        }
        $rates = $t['fx']['rates'] ?? [];
        foreach (array_keys($foreign) as $cur) {
            if (isset($rates[$cur])) {
                $when = $t['fx']['date'] ? ' as of '.Carbon::parse($t['fx']['date'])->format('j M Y') : '';
                $notes[] = sprintf('Transport priced in %s is converted at ₹%s per %s%s, fixed for this quotation.', $cur, rtrim(rtrim(number_format((float) $rates[$cur], 4, '.', ''), '0'), '.'), $cur, $when);
            }
        }
        if ($display === 'before_gst') {
            $notes[] = 'GST of '.rtrim(rtrim(number_format($gst, 2, '.', ''), '0'), '.').'% is extra on the transport price.';
        }

        return [
            'blocks' => array_map(function ($dest, $lines) {
                usort($lines, fn ($a, $b) => $a['day'] <=> $b['day']);

                return ['destination' => $dest, 'lines' => $lines];
            }, array_keys($blocks), array_values($blocks)),
            'by_day' => $byDay, 'summary' => $summary, 'selling' => round($sell, 2), 'gst_rate' => $gst, 'tax_display' => $display,
            'total_incl_gst' => round($sell * (1 + $gst / 100)), 'inclusions' => self::unique($inc), 'exclusions' => self::unique($exc), 'notes' => $notes,
        ];
    }

    /** "Ertiga, 6 seats: local sightseeing" into the vehicle ("Ertiga") and the route ("local sightseeing"). @return array{0:string,1:string} */
    private static function splitName(string $name): array
    {
        $parts = explode(':', $name, 2);
        $veh = trim((string) preg_replace('/,?\s*\d+\s*(seats?|seater).*$/i', '', trim($parts[0])));

        return [$veh !== '' ? $veh : trim($parts[0]), trim($parts[1] ?? '')];
    }

    /** @param list<string> $items @return list<string> */
    private static function unique(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $x) {
            $k = mb_strtolower(trim($x));
            if ($k !== '' && ! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = trim($x);
            }
        }

        return $out;
    }

    /** The quotation's own lines plus the transport ones, without repeating a line already there. @param list<string> $own @param list<string> $extra @return list<string> */
    public static function merge(array $own, array $extra): array
    {
        return self::unique(array_merge($own, $extra));
    }
}
