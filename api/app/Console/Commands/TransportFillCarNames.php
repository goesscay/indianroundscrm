<?php

namespace App\Console\Commands;

use App\Models\TransportLine;
use Illuminate\Console\Command;

/**
 * Operations -> Transport: rates entered before the Car name field keep "Ertiga: Local sightseeing" in one name. This proposes the split
 * (car name before the colon, route service after it) and, only with --apply, saves it. Rates that already have a car name, or have no colon, are left alone.
 * Prices, seasons and ids never change, so quotations are unaffected.
 */
class TransportFillCarNames extends Command
{
    protected $signature = 'transport:fill-car-names {--apply : Save the proposed split (without it nothing is changed)}';

    protected $description = 'Propose (and with --apply, save) Car name and Route service for rates that still have the old combined name';

    /** @return array{0:string,1:string}|null car name and route service */
    public static function split(string $name): ?array
    {
        $pos = strpos($name, ': ');
        if ($pos === false) {
            return null;
        }
        $car = trim(substr($name, 0, $pos));
        $car = trim(preg_replace('/,\s*\d+\s*seats?$/i', '', $car) ?? $car);            // "Ertiga, 6 seats" -> "Ertiga": the seats are their own field
        $route = trim(substr($name, $pos + 2));
        if ($car === '' || $route === '' || mb_strlen($car) > 120) {
            return null;
        }

        return [$car, $route];
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        foreach (TransportLine::with('destination')->whereNull('car_name')->orderBy('destination_id')->orderBy('type')->orderBy('name')->get() as $l) {
            $s = self::split($l->name);
            if (! $s) {
                continue;
            }
            $rows[] = [$l->id, $l->destination?->name, $l->type, $l->name, $s[0], $s[1]];
            if ($apply) {
                $l->update(['car_name' => $s[0], 'name' => $s[1]]);
            }
        }
        $rows ? $this->table(['id', 'destination', 'type', 'old name', 'car name', 'route service'], $rows) : $this->info('Nothing to split.');
        $this->info($apply ? count($rows).' rate(s) updated.' : count($rows).' rate(s) would change. Run again with --apply to save.');

        return self::SUCCESS;
    }
}
