<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One transport rate: what it is, where, what it costs and how it is charged. */
class TransportLine extends Model
{
    use StringId;

    public const TYPES = ['cab' => 'Cab', 'van' => 'Tempo or van', 'bus' => 'Bus', 'ferry' => 'Ferry'];
    public const CHARGED = ['per_day' => 'Per day', 'per_trip' => 'Per trip', 'per_person' => 'Per person'];
    public const WAYS = ['one_way' => 'One way', 'return' => 'Return'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'price' => 'float', 'hours_per_day' => 'float', 'extra_hour' => 'float', 'seats' => 'integer', 'km_included' => 'integer',
            'active' => 'boolean', 'destination_id' => 'string', 'supplier_id' => 'string'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TransportDestination::class, 'destination_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(TransportSupplier::class, 'supplier_id');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(TransportLineSeason::class, 'line_id')->orderBy('valid_from')->orderBy('id');
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id, 'type' => $this->type, 'car_name' => $this->car_name, 'name' => $this->name, 'from_name' => $this->from_name, 'to_name' => $this->to_name, 'way' => $this->way,
            'charged' => $this->charged, 'price' => $this->price, 'currency' => $this->currency, 'season_name' => $this->season_name,
            'valid_from' => $this->valid_from?->toDateString(), 'valid_to' => $this->valid_to?->toDateString(), 'seats' => $this->seats, 'hours_per_day' => $this->hours_per_day,
            'km_included' => $this->km_included, 'extra_hour' => $this->extra_hour, 'note' => $this->note, 'active' => (bool) $this->active,
            'needs_price' => $this->price === null || $this->price <= 0,
            'destination' => $this->destination ? ['id' => (string) $this->destination->id, 'name' => $this->destination->name, 'country' => $this->destination->country, 'currency' => $this->destination->currency] : null,
            'supplier' => $this->supplier?->name,
            'seasons' => $this->seasons->map(fn ($s) => $s->toApi())->values()->all(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
