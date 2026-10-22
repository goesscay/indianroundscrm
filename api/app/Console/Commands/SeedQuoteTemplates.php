<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\QuoteTemplate;
use Illuminate\Console\Command;

/** The ready-made Manali premium quotations. Safe to run again: a template that exists is left alone. Pictures are picked from the Manali library by caption. */
class SeedQuoteTemplates extends Command
{
    protected $signature = 'quotes:seed-templates';

    protected $description = 'Create the Manali premium quotation templates';

    private function pic(string $caption): ?int
    {
        $m = Media::whereNull('hotel_id')->where('destination', 'Manali')->where('caption', $caption)->first();

        return $m ? (int) $m->id : null;
    }

    /** @return list<array<string,mixed>> */
    private function templates(): array
    {
        $stay = fn (int $stars, int $nights, int $offset, string $meal = 'MAP') => ['meal_plan' => $meal, 'start_offset' => $offset, 'nights' => $nights, 'stars' => $stars];
        $day = fn (string $title, string $stayAt, array $bullets, ?string $pic) => ['title' => $title, 'stay' => $stayAt, 'bullets' => $bullets, 'media_id' => $pic ? $this->pic($pic) : null];

        return [
            [
                'name' => 'Manali Snow Escape, 4 nights 5 days by Volvo', 'tagline' => 'Delhi to Manali by Volvo, snow at Solang and Atal Tunnel, 3 nights in the hills',
                'payload' => [
                    'trip' => ['destination' => 'Manali', 'nights' => 4, 'group_sizes' => [2, 4, 6]], 'gst_rate' => 5, 'cover_media_id' => $this->pic('Snow peaks above the Beas valley'),
                    'days' => [
                        $day('Delhi to Manali (Volvo)', 'Overnight journey', ['Evening reporting at the Delhi pickup point', 'Board the Volvo coach to Manali', 'Overnight journey through Mandi and the Beas valley'], 'The Beas river'),
                        $day('Arrival in Manali and Mall Road', 'Manali', ['Arrive in Manali and transfer to the hotel', 'Check in and rest', 'Evening walk on Mall Road and a visit to the Hadimba Devi Temple'], 'Manali town below the snow line'),
                        $day('Solang Valley and Atal Tunnel', 'Manali', ['Drive to Solang Valley for snow activities (optional, at extra cost)', 'Continue through the Atal Tunnel to Sissu', 'Return to the hotel for dinner'], 'Snow fun at Solang'),
                        $day('Local sightseeing and return journey', 'Overnight journey', ['Vashisht hot springs and the Tibetan monastery', 'Shopping at the Mall Road and Old Manali', 'Evening boarding of the Volvo to Delhi'], 'Deodar pines and snow peaks'),
                        $day('Arrival in Delhi', '', ['Arrive in Delhi in the morning, with memories to keep'], null),
                    ],
                    'options' => [['name' => '3 Star', 'stays' => [$stay(3, 3, 1)]], ['name' => '4 Star', 'stays' => [$stay(4, 3, 1)]], ['name' => '5 Star', 'stays' => [$stay(5, 3, 1)]]],
                    'land' => ['label' => 'Delhi to Manali to Delhi Volvo and local sightseeing by cab', 'rows' => [['pax' => 2, 'cost_pp' => 3600], ['pax' => 4, 'cost_pp' => 3000], ['pax' => 6, 'cost_pp' => 2600]]],
                    'content' => ['inclusions' => ['3 nights hotel stay with breakfast and dinner', 'Delhi to Manali to Delhi Volvo tickets', 'Local sightseeing by cab as per the itinerary', 'All toll, parking and driver charges', 'Hotel taxes'],
                        'exclusions' => ['Snow activities, cable car and entry tickets', 'Lunch and any meals not mentioned'], 'notes' => [], 'terms' => [], 'closing' => []],
                ],
            ],
            [
                'name' => 'Manali Family Holiday, 6 nights 7 days by Volvo', 'tagline' => 'A relaxed family trip: Manali, Kullu and Naggar, with time to enjoy the snow',
                'payload' => [
                    'trip' => ['destination' => 'Manali', 'nights' => 6, 'group_sizes' => [2, 4, 6]], 'gst_rate' => 5, 'cover_media_id' => $this->pic('Deodar pines and snow peaks'),
                    'days' => [
                        $day('Delhi to Manali (Volvo)', 'Overnight journey', ['Evening reporting at the Delhi pickup point', 'Overnight Volvo journey to Manali'], 'The Beas river'),
                        $day('Arrival and rest', 'Manali', ['Arrive and check in', 'Relax, with an evening walk to the Mall Road'], 'Manali town below the snow line'),
                        $day('Manali local sightseeing', 'Manali', ['Hadimba Devi Temple and the Van Vihar park', 'Manu Temple and Old Manali cafes', 'Club House for family games'], 'The Pir Panjal range'),
                        $day('Solang Valley snow day', 'Manali', ['Full day at Solang Valley for snow activities (optional, at extra cost)', 'Hot tea and snacks by the slopes'], 'Snow fun at Solang'),
                        $day('Kullu and Naggar excursion', 'Manali', ['Raft or river views at Kullu', 'Naggar Castle and the art gallery', 'Shawl factory visit in Kullu'], 'Playing in the snow'),
                        $day('Free day and return journey', 'Overnight journey', ['Free time for shopping and cafes', 'Evening boarding of the Volvo to Delhi'], 'A winter trek'),
                        $day('Arrival in Delhi', '', ['Arrive in Delhi in the morning'], null),
                    ],
                    'options' => [['name' => '3 Star', 'stays' => [$stay(3, 5, 1, 'CP')]], ['name' => '4 Star', 'stays' => [$stay(4, 5, 1, 'MAP')]]],
                    'land' => ['label' => 'Delhi to Manali to Delhi Volvo and all sightseeing by cab', 'rows' => [['pax' => 2, 'cost_pp' => 4200, 'child_cost_pp' => 3200], ['pax' => 4, 'cost_pp' => 3400, 'child_cost_pp' => 2600], ['pax' => 6, 'cost_pp' => 3000, 'child_cost_pp' => 2300]]],
                    'content' => ['inclusions' => ['5 nights hotel stay with the chosen meal plan', 'Delhi to Manali to Delhi Volvo tickets', 'All sightseeing by cab as per the itinerary, including Kullu and Naggar', 'All toll, parking and driver charges', 'Hotel taxes'],
                        'exclusions' => ['Snow activities, river rafting and entry tickets', 'Lunch and any meals not mentioned'], 'notes' => [], 'terms' => [], 'closing' => []],
                ],
            ],
            [
                'name' => 'Manali Honeymoon Luxury, 5 nights 6 days by private cab', 'tagline' => 'A private, unhurried honeymoon with a luxury stay, a private cab and candlelight evenings',
                'payload' => [
                    'trip' => ['destination' => 'Manali', 'nights' => 5, 'group_sizes' => [2, 4]], 'gst_rate' => 5, 'cover_media_id' => $this->pic('Snow peaks above the Beas valley'),
                    'days' => [
                        $day('Arrival in Manali by private cab', 'Manali', ['Private cab pickup from Chandigarh or the Delhi drop point', 'Scenic drive along the Beas river with tea stops', 'Check in, welcome drink and an evening to relax'], 'The Beas river'),
                        $day('Leisure day and Mall Road', 'Manali', ['A slow morning at the hotel and spa (optional)', 'Hadimba Devi Temple and Old Manali', 'Candlelight dinner (on request, at extra cost)'], 'Manali town below the snow line'),
                        $day('Solang Valley and Atal Tunnel', 'Manali', ['Private cab to Solang Valley for snow activities (optional)', 'Atal Tunnel and Sissu with mountain views', 'Return for dinner'], 'Snow fun at Solang'),
                        $day('Naggar and Kullu', 'Manali', ['Naggar Castle and the Roerich gallery', 'Kullu shawl factory and river viewpoints', 'Photo stops on the way back'], 'The Pir Panjal range'),
                        $day('Free day for Manali', 'Manali', ['Free day for the places you loved, a cafe crawl or a trek', 'Shopping for woollens and local handicrafts'], 'A winter trek'),
                        $day('Departure', '', ['Check out and private cab drop to Chandigarh or Delhi'], null),
                    ],
                    'options' => [['name' => 'Premium (4 Star)', 'stays' => [$stay(4, 5, 0)]], ['name' => 'Luxury (5 Star)', 'stays' => [$stay(5, 5, 0)]]],
                    'land' => ['label' => 'Private cab for the whole trip with a driver, tolls and parking', 'rows' => [['pax' => 2, 'cost_pp' => 9500], ['pax' => 4, 'cost_pp' => 6200]]],
                    'content' => ['inclusions' => ['5 nights luxury stay with breakfast and dinner', 'Private cab for all transfers and sightseeing', 'Welcome drink and a honeymoon room decoration on arrival', 'All toll, parking and driver charges', 'Hotel taxes'],
                        'exclusions' => ['Candlelight dinner, spa and snow activities', 'Lunch and any meals not mentioned'], 'notes' => [], 'terms' => [], 'closing' => []],
                ],
            ],
        ];
    }

    public function handle(): int
    {
        foreach ($this->templates() as $i => $t) {
            if (QuoteTemplate::where('name', $t['name'])->exists()) {
                $this->line($t['name'].': already there');
                continue;
            }
            QuoteTemplate::create(['name' => $t['name'], 'destination' => 'Manali', 'tagline' => $t['tagline'], 'payload' => $t['payload'], 'active' => true, 'position' => $i + 1]);
            $this->info($t['name'].': added');
        }

        return self::SUCCESS;
    }
}
