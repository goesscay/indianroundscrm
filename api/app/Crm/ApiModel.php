<?php

namespace App\Crm;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Serialization shared by the models: ISO-8601 timestamps and string ids,
 * matching the JSON shapes of the original FastAPI app.
 * Models may declare `protected $stringIds = [...]` for integer FKs exposed as strings.
 */
trait ApiModel
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->toIso8601String();
    }

    public function toArray(): array
    {
        $a = parent::toArray();
        if (array_key_exists('id', $a) && $a['id'] !== null) {
            $a['id'] = (string) $a['id'];
        }
        foreach ($this->stringIds ?? [] as $k) {
            if (array_key_exists($k, $a) && $a[$k] !== null) {
                $a[$k] = (string) $a[$k];
            }
        }
        return $a;
    }
}
