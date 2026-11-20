<?php

namespace App\Console\Commands;

use App\Models\TransportPlace;
use App\Models\TransportProvider;
use App\Models\TransportRate;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the client's Manali rate sheet ("Manali Sightseeing Cab & Volvo Bus Rates", Off Season cabs, Volvo Off/Mid/On) and a small Singapore (SGD)
 * proof so a second country can be seen working. Safe to run again: it updates what is there.
 * The Manali season dates are not in the sheet, so those rates are loaded without dates and shown as "dates needed" until Operations enter them.
 */
class TransportLoadSample extends Command
{
    protected $signature = 'transport:load-sample';

    protected $description = 'Load the Manali rate sheet and a Singapore sample into the transport master';

    public function handle(): int
    {
        DB::transaction(function () {
            $this->places();
            $this->manali();
            $this->singapore();
        });
        $this->info('Transport master loaded: '.TransportProvider::count().' providers, '.TransportRate::count().' rates.');

        return self::SUCCESS;
    }

    private function places(): void
    {
        foreach ([
            ['IN', 'Delhi', 'city', null, null, 'Asia/Kolkata'], ['IN', 'Indira Gandhi International Airport', 'airport', 'Delhi', 'DEL', 'Asia/Kolkata'],
            ['IN', 'Chandigarh', 'city', null, null, 'Asia/Kolkata'], ['IN', 'Manali', 'city', null, null, 'Asia/Kolkata'],
            ['IN', 'Manali Volvo Bus Stand', 'bus_stand', 'Manali', null, 'Asia/Kolkata'],
            ['SG', 'Singapore', 'city', null, null, 'Asia/Singapore'], ['SG', 'Changi Airport', 'airport', 'Singapore', 'SIN', 'Asia/Singapore'],
            ['SG', 'Marina Bay', 'other', 'Singapore', null, 'Asia/Singapore'],
        ] as [$cc, $name, $kind, $city, $code, $tz]) {
            TransportPlace::updateOrCreate(['country_code' => $cc, 'name' => $name], ['kind' => $kind, 'city' => $city, 'code' => $code, 'timezone' => $tz]);
        }
    }

    private function provider(array $a): TransportProvider
    {
        return TransportProvider::updateOrCreate(['name' => $a['name'], 'destination' => $a['destination']], $a);
    }

    private function vehicle(TransportProvider $p, string $name, string $mode, int $seats): TransportVehicle
    {
        return TransportVehicle::updateOrCreate(['provider_id' => $p->id, 'name' => $name], ['mode' => $mode, 'seats' => $seats]);
    }

    private function route(TransportProvider $p, string $title, string $kind, ?string $inclusions = null, ?int $from = null, ?int $to = null): TransportRoute
    {
        return TransportRoute::updateOrCreate(['provider_id' => $p->id, 'title' => $title], ['kind' => $kind, 'inclusions' => $inclusions, 'from_place_id' => $from, 'to_place_id' => $to]);
    }

    private function rate(TransportProvider $p, TransportVehicle $v, ?TransportRoute $r, string $basis, string $season, float $cost, ?string $start = null, ?string $end = null): void
    {
        TransportRate::updateOrCreate(
            ['provider_id' => $p->id, 'vehicle_id' => $v->id, 'route_id' => $r?->id, 'basis' => $basis, 'season' => $season],
            ['cost' => $cost, 'currency' => $p->currency, 'start_date' => $start, 'end_date' => $end],
        );
    }

    private function manali(): void
    {
        $pickup = 'Pickup and drop at Manali Volvo Bus Stand included.';
        $taxi = $this->provider(['name' => 'Manali Taxi Service', 'country_code' => 'IN', 'destination' => 'Manali', 'city' => 'Manali', 'modes' => ['cab', 'van'], 'currency' => 'INR',
            'notes' => 'Rates from the client sheet "Manali Sightseeing Cab & Volvo Bus Rates" (Off Season). Season dates and Mid/On Season rates are still to be given.']);
        $local = $this->route($taxi, 'Local Manali, Solang Valley', 'sightseeing', $pickup);
        $naggar = $this->route($taxi, 'Local Manali, Solang Valley, Kullu-Naggar', 'sightseeing', $pickup);
        $manikaran = $this->route($taxi, 'Local Manali, Solang Valley, Kullu-Manikaran', 'sightseeing', $pickup);

        // vehicle => [mode, seats (proposed, to be confirmed by the client), local, naggar, manikaran]
        foreach ([
            'Alto' => ['cab', 4, 1800, 2800, 3000], 'Swift Dzire' => ['cab', 4, 2200, 3000, 3500], 'Ertiga' => ['cab', 6, null, 5000, 5500], 'Sumo' => ['cab', 7, 3000, 5000, 5500],
            'Tempo Traveller (14 seater)' => ['van', 14, null, 9000, 10500], 'Tempo Traveller (17 seater)' => ['van', 17, null, 9500, 12000],
        ] as $name => [$mode, $seats, $l, $n, $m]) {
            $v = $this->vehicle($taxi, $name, $mode, $seats);
            foreach ([[$local, $l], [$naggar, $n], [$manikaran, $m]] as [$route, $cost]) {
                if ($cost !== null) {
                    $this->rate($taxi, $v, $route, 'per_day', 'Off Season', $cost);
                }
            }
        }

        $volvo = $this->provider(['name' => 'Volvo Bus: Delhi / Chandigarh - Manali', 'country_code' => 'IN', 'destination' => 'Manali', 'city' => 'Manali', 'modes' => ['bus'], 'currency' => 'INR',
            'notes' => 'Per person. Pickup Delhi to Manali and Manali to Delhi; Chandigarh to Manali and Manali to Chandigarh. Confirm with the client whether the rate is per leg or return.']);
        $seat = $this->vehicle($volvo, 'Volvo seat', 'bus', 1);
        $id = fn (string $n) => TransportPlace::where('name', $n)->value('id');
        foreach ([['Delhi - Manali (Volvo)', 'Delhi'], ['Chandigarh - Manali (Volvo)', 'Chandigarh']] as [$title, $from]) {
            $route = $this->route($volvo, $title, 'intercity', $pickup, $id($from), $id('Manali'));
            foreach (['Off Season' => 2000, 'Mid Season' => 2500, 'On Season' => 3000] as $season => $cost) {
                $this->rate($volvo, $seat, $route, 'per_person', $season, $cost);
            }
        }
    }

    private function singapore(): void
    {
        $p = $this->provider(['name' => 'Lion City Cabs (Sample)', 'country_code' => 'SG', 'destination' => 'Singapore', 'city' => 'Singapore', 'modes' => ['cab', 'van'], 'currency' => 'SGD',
            'notes' => 'Sample data to show a second country and currency working. Not a real vendor rate; replace with the real rate sheet.']);
        $id = fn (string $n) => TransportPlace::where('name', $n)->value('id');
        $airport = $this->route($p, 'Changi Airport to city hotel', 'transfer', 'Meet and greet at arrivals.', $id('Changi Airport'), $id('Singapore'));
        $tour = $this->route($p, 'Singapore city tour (8 hours)', 'sightseeing', null, $id('Singapore'), $id('Singapore'));
        foreach ([['Sedan', 'cab', 4, 55, 320], ['MPV', 'van', 6, 75, 420], ['Minibus (13 seater)', 'van', 13, 110, 650]] as [$name, $mode, $seats, $transfer, $day]) {
            $v = $this->vehicle($p, $name, $mode, $seats);
            $this->rate($p, $v, $airport, 'per_transfer', 'All year', $transfer, '2026-01-01', '2027-12-31');
            $this->rate($p, $v, $tour, 'per_day', 'All year', $day, '2026-01-01', '2027-12-31');
        }
    }
}
