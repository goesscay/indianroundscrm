<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DestinationCity extends Model
{
    protected $fillable = ['destination'];

    public function items()
    {
        return $this->hasMany(DestinationCityItem::class)->orderBy('position');
    }

    /** @return list<string> */
    public function cityNames(): array
    {
        return $this->items->pluck('city')->all();
    }

    /** @param list<string> $cities */
    public function replaceCities(array $cities): void
    {
        $this->items()->delete();
        foreach (array_values($cities) as $i => $city) {
            $this->items()->create(['city' => $city, 'position' => $i]);
        }
        $this->touch();
        $this->unsetRelation('items');
    }
}
