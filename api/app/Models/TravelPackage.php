<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelPackage extends Model
{
    use ApiModel;

    public const CATEGORIES = ['domestic', 'international', 'honeymoon', 'group', 'pilgrimage', 'adventure', 'other'];

    protected $table = 'packages';
    protected $guarded = ['id'];
    protected $stringIds = ['created_by'];
    protected $casts = [
        'active' => 'boolean', 'nights' => 'integer', 'days' => 'integer',
        'adult_price' => 'float', 'child_price' => 'float', 'adult_cost' => 'float', 'child_cost' => 'float', 'gst_rate' => 'float',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(PackageSeason::class, 'package_id')->orderBy('start_date');
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(PackageDay::class, 'package_id')->orderBy('day_no');
    }

    /** Rates for a travel date: the matching season when there is one, otherwise the package base rates. */
    public function ratesFor(?string $date): array
    {
        $season = null;
        if ($date) {
            $season = $this->seasons->first(fn (PackageSeason $s) => $s->start_date->format('Y-m-d') <= $date && $s->end_date->format('Y-m-d') >= $date);
        }
        $src = $season ?? $this;

        return [
            'season' => $season?->name, 'adult_price' => (float) $src->adult_price, 'child_price' => (float) $src->child_price,
            'adult_cost' => (float) $src->adult_cost, 'child_cost' => (float) $src->child_cost, 'gst_rate' => (float) $this->gst_rate,
        ];
    }

    public function toApi(bool $detail = false): array
    {
        $a = $this->toArray();
        unset($a['seasons'], $a['itinerary_days']);
        if ($detail) {
            $a['seasons'] = $this->seasons->map(fn ($s) => collect($s->toArray())->except('package_id')->all())->values()->all();
            $a['itinerary'] = $this->itineraryDays->map(fn ($d) => ['day_no' => $d->day_no, 'title' => $d->title, 'description' => $d->description])->values()->all();
        }

        return $a;
    }
}
