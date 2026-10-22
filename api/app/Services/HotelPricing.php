<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\QuoteSetting;
use Illuminate\Support\Carbon;

/**
 * Prices a stay from the vendor rates: night by night (a night is priced from the rate row whose season covers it),
 * for the rooms the adults need, plus children, then the margin. Cost and margin are for Operations; the selling figure is what the quotation shows.
 */
class HotelPricing
{
    /** The margin for something that is not a hotel (the land package): the destination\'s, else the default. @return array{type:string,value:float,source:string} */
    public static function marginForDestination(?string $destination): array
    {
        $dest = (array) QuoteSetting::read('margin_destinations', []);
        if ($destination !== null && isset($dest[$destination]['type'], $dest[$destination]['value'])) {
            return ['type' => (string) $dest[$destination]['type'], 'value' => (float) $dest[$destination]['value'], 'source' => 'destination'];
        }
        $d = (array) QuoteSetting::read('margin_default', ['type' => 'percent', 'value' => 0]);

        return ['type' => (string) ($d['type'] ?? 'percent'), 'value' => (float) ($d['value'] ?? 0), 'source' => 'default'];
    }

    /** The margin that applies to a hotel: its own, else its destination's, else the default. @return array{type:string,value:float,source:string} */
    public static function margin(Hotel $h): array
    {
        if ($h->margin_type && $h->margin_value !== null) {
            return ['type' => $h->margin_type, 'value' => (float) $h->margin_value, 'source' => 'hotel'];
        }
        $dest = (array) QuoteSetting::read('margin_destinations', []);
        if (isset($dest[$h->destination]['type'], $dest[$h->destination]['value'])) {
            return ['type' => (string) $dest[$h->destination]['type'], 'value' => (float) $dest[$h->destination]['value'], 'source' => 'destination'];
        }
        $d = (array) QuoteSetting::read('margin_default', ['type' => 'percent', 'value' => 0]);

        return ['type' => (string) ($d['type'] ?? 'percent'), 'value' => (float) ($d['value'] ?? 0), 'source' => 'default'];
    }

    /** Which room types the adults fill. 5 adults in doubles: two doubles and one single; a room that sleeps 3 takes the odd adult as a triple. @return list<string> */
    public static function roomPlan(HotelRoom $room, int $adults): array
    {
        if ($adults <= 0) {
            return [];
        }
        $per = max(1, min(2, (int) $room->max_adults));
        $plan = array_fill(0, intdiv($adults, $per), $per === 2 ? 'double' : 'single');
        $odd = $adults % $per;
        if ($odd === 1) {
            if ($room->max_adults >= 3 && $plan) {
                $plan[count($plan) - 1] = 'triple';
            } else {
                $plan[] = 'single';
            }
        }

        return $plan;
    }

    /**
     * @return array{nights:list<array<string,mixed>>,rooms:list<string>,cost:float,margin:array<string,mixed>,margin_amount:float,selling:float,per_person:float,persons:int,warnings:list<string>}
     */
    public static function estimate(HotelRoom $room, string $meal, string $checkIn, int $nights, int $adults, int $childWithBed = 0, int $childNoBed = 0): array
    {
        $room->loadMissing(['rates', 'hotel']);
        $rates = $room->rates->where('meal_plan', $meal);
        $plan = self::roomPlan($room, $adults);
        $warnings = [];
        $log = [];
        $cost = 0.0;
        $start = Carbon::parse($checkIn);
        for ($n = 0; $n < $nights; $n++) {
            $date = $start->copy()->addDays($n)->toDateString();
            /** @var HotelRate|null $r */
            $r = $rates->first(fn (HotelRate $x) => $x->start_date->toDateString() <= $date && $x->end_date->toDateString() >= $date);
            if (! $r) {
                $warnings[] = "No $meal rate for $date.";
                $log[] = ['date' => $date, 'cost' => null, 'season' => null];
                continue;
            }
            $night = 0.0;
            foreach ($plan as $type) {
                $price = $r->{$type} ?? ($type === 'single' ? $r->double : null);
                if ($price === null) {
                    $warnings[] = "No $type rate for $date.";
                    continue;
                }
                $night += $price;
            }
            if ($childWithBed > 0) {
                $price = $r->child_with_bed ?? $r->extra_adult;
                if ($price === null) {
                    $warnings[] = "No child-with-bed rate for $date.";
                } else {
                    $night += $price * $childWithBed;
                }
            }
            if ($childNoBed > 0) {
                $night += ($r->child_no_bed ?? 0.0) * $childNoBed;
            }
            $cost += $night;
            $log[] = ['date' => $date, 'cost' => round($night, 2), 'season' => $r->season];
        }
        $persons = $adults + $childWithBed + $childNoBed;
        $m = self::margin($room->hotel);
        $marginAmount = $m['type'] === 'amount' ? $m['value'] * max(1, $persons) : $cost * $m['value'] / 100;
        $selling = $cost + $marginAmount;

        return [
            'nights' => $log, 'rooms' => $plan, 'cost' => round($cost, 2), 'margin' => $m, 'margin_amount' => round($marginAmount, 2),
            'selling' => round($selling, 2), 'per_person' => $persons > 0 ? round($selling / $persons, 2) : 0.0, 'persons' => $persons,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
