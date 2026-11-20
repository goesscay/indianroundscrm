<?php

namespace App\Services;

use App\Models\QuoteSetting;
use App\Models\TransportProvider;
use App\Models\TransportRate;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use Illuminate\Support\Carbon;

/**
 * Transport on a premium quotation: the legs an option carries (flights and trains typed in; cabs, vans, buses and ferries priced from the
 * transport master by travel date and season), converted to rupees at the rates held on the quotation, with the margin on top.
 * Costs and margins are returned always; ProposalPricing strips them for people who may not see them.
 */
class TransportPricing
{
    public const TYPED = ['flight', 'train'];
    public const MASTER = ['cab', 'van', 'bus', 'ferry'];
    public const MODES = ['flight', 'train', 'bus', 'cab', 'van', 'ferry'];
    public const SCOPES = ['stay', 'daily'];

    /** @var array<int,TransportProvider|null> */
    private static array $providers = [];

    /** @var array<int,\Illuminate\Support\Collection> rates of a provider */
    private static array $rates = [];

    /** @var array<int,TransportVehicle|null> */
    private static array $vehicles = [];

    /** @var array<int,TransportRoute|null> */
    private static array $routes = [];

    public static function flush(): void
    {
        self::$providers = self::$rates = self::$vehicles = self::$routes = [];
    }

    private static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function int(mixed $v, int $min, int $max, ?int $default = null): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) $v));
    }

    private static function money(mixed $v): ?float
    {
        return $v === null || $v === '' || ! is_numeric($v) ? null : round(max(0.0, min(100000000.0, (float) $v)), 2);
    }

    private static function id(mixed $v): ?int
    {
        return is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : null;
    }

    /** The legs of one option, made safe and tidy. @return list<array<string,mixed>> */
    public static function cleanLegs(mixed $legs): array
    {
        $out = [];
        foreach (array_slice((array) $legs, 0, 40) as $i => $l) {
            $l = (array) $l;
            $mode = (string) ($l['mode'] ?? '');
            if (! in_array($mode, self::MODES, true)) {
                continue;
            }
            $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($l['currency'] ?? 'INR')));
            $out[] = [
                'id' => self::text($l['id'] ?? null, 40) ?: 'l'.($i + 1), 'mode' => $mode, 'day_offset' => self::int($l['day_offset'] ?? 0, 0, 89, 0), 'destination' => self::text($l['destination'] ?? null, 120),
                'provider_id' => self::id($l['provider_id'] ?? null), 'vehicle_id' => self::id($l['vehicle_id'] ?? null), 'route_id' => self::id($l['route_id'] ?? null),
                'qty' => self::int($l['qty'] ?? null, 1, 50), 'days' => self::int($l['days'] ?? 1, 1, 30, 1), 'legs' => self::int($l['legs'] ?? 1, 1, 2, 1),
                'title' => self::text($l['title'] ?? null, 200), 'from' => self::text($l['from'] ?? null, 120), 'to' => self::text($l['to'] ?? null, 120),
                'depart' => self::text($l['depart'] ?? null, 40), 'arrive' => self::text($l['arrive'] ?? null, 40), 'class' => self::text($l['class'] ?? null, 60), 'baggage' => self::text($l['baggage'] ?? null, 80),
                'reference' => self::text($l['reference'] ?? null, 80), 'fare_pp' => self::money($l['fare_pp'] ?? null), 'child_fare_pp' => self::money($l['child_fare_pp'] ?? null),
                'currency' => strlen($cur) === 3 ? $cur : 'INR', 'included' => ! array_key_exists('included', $l) || filter_var($l['included'], FILTER_VALIDATE_BOOL), 'note' => self::text($l['note'] ?? null, 300),
            ];
        }

        return $out;
    }

    /** The exchange rates held on the quotation, and how each destination's cabs are booked. @return array{fx:array<string,mixed>,cab_scope:array<string,string>} */
    public static function cleanSettings(mixed $t): array
    {
        $t = (array) $t;
        $fx = (array) ($t['fx'] ?? []);
        $rates = [];
        foreach ((array) ($fx['rates'] ?? []) as $code => $v) {
            $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code));
            if (strlen($code) === 3 && $code !== 'INR' && is_numeric($v) && (float) $v > 0) {
                $rates[$code] = round(min(1000000.0, (float) $v), 6);
            }
        }
        $date = (string) ($fx['date'] ?? '');
        $scope = [];
        foreach ((array) ($t['cab_scope'] ?? []) as $dest => $s) {
            if (is_string($dest) && trim($dest) !== '' && in_array($s, self::SCOPES, true)) {
                $scope[mb_substr(trim($dest), 0, 120)] = $s;
            }
        }

        return ['fx' => ['date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null, 'rates' => $rates, 'safety_pct' => round(max(0.0, min(25.0, (float) ($fx['safety_pct'] ?? 0))), 2)], 'cab_scope' => $scope];
    }

    /** The default exchange rates Operations keep (used to start each new quotation). @return array{date:?string,rates:array<string,float>} */
    public static function defaultFx(): array
    {
        $v = (array) QuoteSetting::read('transport_fx', []);

        return ['date' => $v['date'] ?? null, 'rates' => (array) ($v['rates'] ?? [])];
    }

    // ------------------------------------------------------------------ lookups (cached for one request)

    private static function provider(int $id): ?TransportProvider
    {
        return self::$providers[$id] ??= TransportProvider::find($id);
    }

    private static function vehicle(int $id): ?TransportVehicle
    {
        return self::$vehicles[$id] ??= TransportVehicle::find($id);
    }

    private static function route(int $id): ?TransportRoute
    {
        return self::$routes[$id] ??= TransportRoute::find($id);
    }

    /** The dated rate of this vehicle that covers the day: the route's own rate first, else the vehicle's rate for any route. */
    private static function rateFor(int $providerId, int $vehicleId, ?int $routeId, string $date): ?TransportRate
    {
        $all = self::$rates[$providerId] ??= TransportRate::where('provider_id', $providerId)->whereNotNull('start_date')->whereNotNull('end_date')->get();
        $cover = $all->filter(fn ($r) => (int) $r->vehicle_id === $vehicleId && $r->start_date->toDateString() <= $date && $r->end_date->toDateString() >= $date);

        return ($routeId ? $cover->first(fn ($r) => (int) $r->route_id === $routeId) : null) ?: $cover->first(fn ($r) => $r->route_id === null);
    }

    /** The margin on a leg: its provider's own, else the destination's, else the default. @return array{type:string,value:float} */
    private static function margin(?TransportProvider $p, ?string $destination): array
    {
        if ($p && $p->margin_type && $p->margin_value !== null) {
            return ['type' => $p->margin_type, 'value' => (float) $p->margin_value];
        }
        $m = HotelPricing::marginForDestination($destination);

        return ['type' => $m['type'], 'value' => (float) $m['value']];
    }

    private static function fxRate(string $currency, array $fx): ?float
    {
        if ($currency === 'INR') {
            return 1.0;
        }
        $r = $fx['rates'][$currency] ?? null;

        return $r ? (float) $r * (1 + ($fx['safety_pct'] ?? 0) / 100) : null;
    }

    private static function dateLabel(string $d): string
    {
        return Carbon::parse($d)->format('j M Y');
    }

    // ------------------------------------------------------------------ pricing

    /**
     * One option's legs priced for one group.
     *
     * @param  list<array<string,mixed>>  $legs
     * @param  array{fx:array<string,mixed>,cab_scope:array<string,string>}  $settings
     * @param  bool  $actual  the real group (a quantity typed by sales is used as it is); the group columns use at least the vehicles the group needs
     * @return array{selling:float,cost:float,margin_amount:float,by_mode:array<string,float>,legs:list<array<string,mixed>>,warnings:list<string>,fx_needed:list<string>}
     */
    public static function price(array $legs, array $trip, array $settings, int $adults, int $cb, int $cn, bool $actual): array
    {
        $persons = $adults + $cb + $cn;
        $start = $trip['start_date'] ?? null;
        $fx = $settings['fx'];
        $sell = $cost = $marginAmt = 0.0;
        $byMode = [];
        $rows = [];
        $warnings = [];
        $fxNeeded = [];

        foreach ($legs as $l) {
            $day = (int) $l['day_offset'] + 1;
            $dest = $l['destination'] ?: ($trip['destination'] ?: null);
            $row = ['id' => $l['id'], 'mode' => $l['mode'], 'day' => $day, 'included' => (bool) $l['included'], 'qty' => null, 'basis' => null, 'description' => '', 'selling' => 0.0, 'cost' => 0.0, 'margin_amount' => 0.0,
                'currency' => 'INR', 'rate_needed' => false, 'warnings' => []];
            $w = [];

            if (in_array($l['mode'], self::TYPED, true)) {
                $name = $l['mode'] === 'flight' ? 'Flight' : 'Train';
                $row['description'] = trim($name.': '.($l['title'] ?: trim(($l['from'] ?? '').' to '.($l['to'] ?? ''), ' ')), ' :');
                $row['currency'] = $l['currency'];
                if ($l['fare_pp'] === null) {
                    $w[] = "Day $day $name: enter the fare.";
                } elseif (($rate = self::fxRate($l['currency'], $fx)) === null) {
                    $fxNeeded[$l['currency']] = true;
                    $row['rate_needed'] = true;
                    $w[] = "Day $day $name: exchange rate needed for {$l['currency']}.";
                } else {
                    $raw = $l['fare_pp'] * $adults + ($l['child_fare_pp'] ?? $l['fare_pp']) * ($cb + $cn);
                    $c = round($raw * $rate, 2);
                    $m = self::margin(null, $dest);
                    $ma = $m['type'] === 'amount' ? $m['value'] * max(1, $persons) : $c * $m['value'] / 100;
                    $row = array_merge($row, ['cost' => $c, 'margin_amount' => round($ma, 2), 'selling' => round($c + $ma, 2), 'qty' => $persons, 'basis' => 'per_person']);
                }
            } else {
                $prov = $l['provider_id'] ? self::provider($l['provider_id']) : null;
                $veh = $l['vehicle_id'] ? self::vehicle($l['vehicle_id']) : null;
                $route = $l['route_id'] ? self::route($l['route_id']) : null;
                $label = ucfirst($l['mode']);
                if (! $l['provider_id'] || ! $l['vehicle_id']) {
                    $w[] = "Day $day $label: choose the service and vehicle.";
                    $row['rate_needed'] = true;
                } elseif (! $prov || ! $veh || (int) $veh->provider_id !== (int) $prov->id || ($l['route_id'] && (! $route || (int) $route->provider_id !== (int) $prov->id))) {
                    $w[] = "Day $day $label: the service, vehicle or route is no longer in the transport master.";
                    $row['rate_needed'] = true;
                } else {
                    $row['currency'] = $prov->currency;
                    $row['description'] = trim($veh->name.($route ? ': '.$route->title : ''));
                    $what = $veh->name.($route ? ' ('.$route->title.')' : '');
                    $auto = max(1, (int) ceil($persons / max(1, (int) $veh->seats)));
                    $qty = $actual ? ($l['qty'] ?? $auto) : max($l['qty'] ?? 0, $auto);
                    if (! $start) {
                        $w[] = 'Set the travel date so the transport rates can be found.';
                        $row['rate_needed'] = true;
                    } else {
                        $first = Carbon::parse($start)->addDays($l['day_offset'])->toDateString();
                        $r0 = self::rateFor($prov->id, $veh->id, $route?->id, $first);
                        if (! $r0) {
                            $w[] = "Rate needed: $what has no rate for ".self::dateLabel($first).' ('.$prov->name.').';
                            $row['rate_needed'] = true;
                        } else {
                            $row['basis'] = $r0->basis;
                            $row['qty'] = $r0->basis === 'per_person' ? $persons : $qty;
                            $raw = null;
                            if ($r0->basis === 'per_day') {
                                $raw = 0.0;
                                for ($k = 0; $k < $l['days']; $k++) {
                                    $d = Carbon::parse($first)->addDays($k)->toDateString();
                                    $rk = self::rateFor($prov->id, $veh->id, $route?->id, $d);
                                    if (! $rk) {
                                        $w[] = "Rate needed: $what has no rate for ".self::dateLabel($d).' ('.$prov->name.').';
                                        $row['rate_needed'] = true;
                                        $raw = null;
                                        break;
                                    }
                                    $raw += $rk->cost * $qty;
                                }
                            } elseif ($r0->basis === 'per_route') {
                                $raw = $r0->cost * $qty;
                            } elseif ($r0->basis === 'per_transfer') {
                                $raw = $r0->cost * $qty * $l['legs'];
                            } else {                                                              // per person: adults, children at the child rate when there is one, one way or return
                                $raw = ($r0->cost * $adults + ($r0->child_cost ?? $r0->cost) * ($cb + $cn)) * $l['legs'];
                            }
                            if ($raw !== null) {
                                $rate = self::fxRate($prov->currency, $fx);
                                if ($rate === null) {
                                    $fxNeeded[$prov->currency] = true;
                                    $row['rate_needed'] = true;
                                    $w[] = "Day $day $label: exchange rate needed for {$prov->currency}.";
                                } else {
                                    $c = round($raw * $rate, 2);
                                    $m = self::margin($prov, $dest);
                                    $ma = $m['type'] === 'amount' ? $m['value'] * max(1, $persons) : $c * $m['value'] / 100;
                                    $row = array_merge($row, ['cost' => $c, 'margin_amount' => round($ma, 2), 'selling' => round($c + $ma, 2)]);
                                }
                            }
                        }
                    }
                }
            }
            $row['warnings'] = $w;
            foreach ($w as $x) {
                $warnings[] = $x;
            }
            if ($row['included']) {
                $sell += $row['selling'];
                $cost += $row['cost'];
                $marginAmt += $row['margin_amount'];
                $byMode[$l['mode']] = round(($byMode[$l['mode']] ?? 0) + $row['selling'], 2);
            }
            $rows[] = $row;
        }

        return ['selling' => round($sell, 2), 'cost' => round($cost, 2), 'margin_amount' => round($marginAmt, 2), 'by_mode' => $byMode, 'legs' => $rows,
            'warnings' => array_values(array_unique($warnings)), 'fx_needed' => array_keys($fxNeeded)];
    }
}
