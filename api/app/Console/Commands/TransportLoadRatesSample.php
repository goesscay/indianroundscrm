<?php

namespace App\Console\Commands;

use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\TransportSupplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the client's Manali rate sheet ("Manali Sightseeing Cab & Volvo Bus Rates": Off Season cabs and tempo travellers per day, Volvo seats per person
 * by season) into the Transport rate list, and a small Singapore (SGD) sample. Safe to run again: it updates what is there.
 * The Manali season dates are not in the sheet, so those prices apply all year (Off Season) or are kept without dates (Mid and On Season).
 */
class TransportLoadRatesSample extends Command
{
    protected $signature = 'transport:load-rates-sample';

    protected $description = 'Load the Manali rate sheet and a Singapore sample into the transport rate list';

    public function handle(): int
    {
        DB::transaction(function () {
            $this->manali();
            $this->singapore();
        });
        $this->info('Transport rate list loaded: '.TransportLine::count().' rates.');

        return self::SUCCESS;
    }

    private function dest(string $name, string $country, string $currency): TransportDestination
    {
        return TransportDestination::firstOrCreate(['name' => $name], ['country' => $country, 'currency' => $currency]);
    }

    private function addLine(TransportDestination $d, string $supplier, array $a): TransportLine
    {
        $sup = TransportSupplier::firstOrCreate(['name' => $supplier]);
        $line = TransportLine::where('destination_id', $d->id)->where('type', $a['type'])->where('name', $a['name'])->where('supplier_id', $sup->id)->where(fn ($q) => isset($a['car_name']) ? $q->where('car_name', $a['car_name'])->orWhereNull('car_name') : $q)->first()
            ?? (isset($a['car_name']) ? TransportLine::where('destination_id', $d->id)->where('type', $a['type'])->where('name', $a['car_name'].': '.$a['name'])->where('supplier_id', $sup->id)->first() : null);      // a line loaded before the car name existed
        $data = $a + ['currency' => $d->currency, 'destination_id' => $d->id, 'supplier_id' => $sup->id];
        $line ? $line->fill($data)->save() : $line = TransportLine::create($data);

        return $line;
    }

    private function manali(): void
    {
        $d = $this->dest('Manali', 'India', 'INR');
        $taxi = 'Manali Taxi Service';
        $note = 'Pickup and drop at Manali Volvo Bus Stand included.';
        // vehicle => [type, seats (proposed, to be confirmed by the client), local, + Kullu-Naggar, + Kullu-Manikaran]
        foreach ([
            'Alto' => ['cab', 4, 1800, 2800, 3000], 'Swift Dzire' => ['cab', 4, 2200, 3000, 3500], 'Ertiga' => ['cab', 6, null, 5000, 5500], 'Sumo' => ['cab', 7, 3000, 5000, 5500],
            'Tempo Traveller (14 seater)' => ['van', 14, null, 9000, 10500], 'Tempo Traveller (17 seater)' => ['van', 17, null, 9500, 12000],
        ] as $vehicle => [$type, $seats, $local, $naggar, $manikaran]) {
            foreach (['Local Manali, Solang Valley' => $local, 'Local Manali, Solang Valley, Kullu-Naggar' => $naggar, 'Local Manali, Solang Valley, Kullu-Manikaran' => $manikaran] as $route => $price) {
                if ($price !== null) {
                    $this->addLine($d, $taxi, ['type' => $type, 'car_name' => $vehicle, 'name' => $route, 'charged' => 'per_day', 'price' => $price, 'season_name' => 'Off Season', 'seats' => $seats, 'note' => $note]);
                }
            }
        }
        foreach (['Delhi', 'Chandigarh'] as $from) {
            $l = $this->addLine($d, 'Volvo desk', ['type' => 'bus', 'car_name' => 'Volvo', 'name' => "$from to Manali", 'from_name' => $from, 'to_name' => 'Manali', 'way' => 'one_way', 'charged' => 'per_person', 'price' => 2000, 'season_name' => 'Off Season',
                'note' => 'Pickup and drop both ways. Confirm with the client whether the rate is per leg or for the return trip.']);
            foreach (['Mid Season' => 2500, 'On Season' => 3000] as $name => $price) {
                $s = $l->seasons()->where('name', $name)->first();
                $s ? $s->fill(['price' => $price])->save() : $l->seasons()->create(['name' => $name, 'price' => $price]);       // dates still to be given by the client
            }
        }
    }

    private function singapore(): void
    {
        $d = $this->dest('Singapore', 'Singapore', 'SGD');
        $sup = 'Lion City Cabs (Sample)';
        $note = 'Sample rate to show a second country and currency working. Not a real vendor rate.';
        foreach ([['Sedan', 4, 55, 320], ['MPV', 6, 75, 420], ['Minibus (13 seater)', 13, 110, 650]] as [$vehicle, $seats, $transfer, $day]) {
            $this->addLine($d, $sup, ['type' => $vehicle === 'Sedan' ? 'cab' : ($seats > 6 ? 'van' : 'cab'), 'car_name' => $vehicle, 'name' => 'Changi Airport to city hotel', 'from_name' => 'Changi Airport', 'to_name' => 'City hotel', 'charged' => 'per_trip', 'price' => $transfer, 'seats' => $seats, 'note' => $note]);
            $this->addLine($d, $sup, ['type' => $seats > 6 ? 'van' : 'cab', 'car_name' => $vehicle, 'name' => 'Singapore city tour (8 hours)', 'charged' => 'per_day', 'price' => $day, 'seats' => $seats, 'hours_per_day' => 8, 'note' => $note]);
        }
    }
}
