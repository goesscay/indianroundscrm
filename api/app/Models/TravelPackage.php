<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'source_price' => 'float', 'source_unlocked' => 'boolean', 'source_synced_at' => 'datetime', 'source_removed_at' => 'datetime',
    ];

    /** What the website decides on a package that comes from it (until the team unlocks it). */
    public const WEBSITE_FIELDS = ['name', 'code', 'destination', 'category', 'nights', 'days', 'summary', 'inclusions', 'exclusions', 'terms',
        'itinerary', 'image_url', 'website_url', 'highlights'];

    /** @return list<string> the fields the team cannot edit here: they follow the website until the package is unlocked */
    public function lockedFields(): array
    {
        if ($this->source !== 'website' || $this->source_unlocked) {
            return [];
        }

        return array_merge(self::WEBSITE_FIELDS, $this->source_price !== null ? ['adult_price'] : []);
    }

    public function departures(): HasMany
    {
        return $this->hasMany(PackageDeparture::class, 'package_id')->orderBy('start_date');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'package_user', 'package_id', 'user_id');
    }

    /** Operations and Admin see every package; anyone else sees the packages assigned to them. */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if ($u->hasAnyPermission('packages.view_all', 'packages.manage')) {
            return $q;
        }

        return $q->whereIn('packages.id', DB::table('package_user')->where('user_id', $u->id)->select('package_id'));
    }

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
        unset($a['seasons'], $a['itinerary_days'], $a['departures'], $a['assignees'], $a['source_hash']);
        $a['locked'] = $this->lockedFields();
        if ($detail) {
            $a['departures'] = $this->departures->map(fn ($d) => ['id' => (string) $d->id, 'start_date' => $d->start_date?->format('Y-m-d'), 'end_date' => $d->end_date?->format('Y-m-d'), 'seats_left' => $d->seats_left, 'status' => $d->status])->values()->all();
            $a['seasons'] = $this->seasons->map(fn ($s) => collect($s->toArray())->except('package_id')->all())->values()->all();
            $a['itinerary'] = $this->itineraryDays->map(fn ($d) => ['day_no' => $d->day_no, 'title' => $d->title, 'description' => $d->description])->values()->all();
        }

        return $a;
    }
}
