<?php

namespace App\Console\Commands;

use App\Models\TransportSchedule;
use Illuminate\Console\Command;

/**
 * Loads the sample flights and trains from the design (Delhi to Manali by air and rail) so the list, the quotation suggestions and the customer view
 * can be tried. They are samples, not real schedules: each note says so. Operations replace them with the real list. Safe to run again.
 */
class TransportLoadSchedulesSample extends Command
{
    protected $signature = 'transport:load-schedules-sample';

    protected $description = 'Load sample flights and trains (details only) into the transport lists';

    private const SAMPLE = 'Sample schedule: confirm before use.';

    public function handle(): int
    {
        $all = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $rows = [
            ['flight', 'Air India AI 123', 'Delhi', 'DEL', 'Kullu-Manali', 'KUU', 'Manali', '09:10', '10:35', $all, 'Economy', 'The airport is about 40 km from Manali. We can arrange a cab.'],
            ['flight', 'IndiGo 6E 456', 'Delhi', 'DEL', 'Chandigarh', 'IXC', 'Manali', '07:00', '08:05', $all, 'Economy', 'Then a 7 to 8 hour road transfer to Manali.'],
            ['flight', 'IndiGo 6E 789', 'Chandigarh', 'IXC', 'Delhi', 'DEL', 'Manali', '18:30', '19:35', $all, 'Economy', 'The way back from Manali: about 7 to 8 hours by road to Chandigarh first.'],
            ['train', 'Shatabdi 12011', 'New Delhi', 'NDLS', 'Kalka', 'KLK', 'Manali, Shimla', '07:40', '11:45', array_values(array_diff($all, ['sun'])), 'CC, EC', 'Then a road transfer to Manali.'],
            ['train', 'Himalayan Queen 14095', 'Kalka', 'KLK', 'Shimla', 'SML', null, '05:10', '10:30', $all, '2S, CC', null],
            ['train', 'Vande Bharat 22447', 'New Delhi', 'NDLS', 'Amb Andaura', 'ANDI', 'Manali', '15:00', '19:50', array_values(array_diff($all, ['fri'])), 'CC, EC', 'Then a road transfer to Manali.'],
        ];
        foreach ($rows as [$kind, $title, $from, $fc, $to, $tc, $serves, $dep, $arr, $days, $classes, $note]) {
            TransportSchedule::updateOrCreate(['kind' => $kind, 'title' => $title, 'from_name' => $from, 'to_name' => $to],
                ['from_code' => $fc, 'to_code' => $tc, 'serves' => $serves, 'departs' => $dep, 'arrives' => $arr, 'days' => $days, 'classes' => $classes,
                    'note' => trim(($note ? $note.' ' : '').self::SAMPLE), 'active' => true]);
        }
        $this->info('Sample flights and trains loaded: '.TransportSchedule::count().' in the lists.');

        return self::SUCCESS;
    }
}
