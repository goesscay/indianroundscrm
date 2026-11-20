<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportProvider extends Model
{
    use StringId;

    public const MODES = ['cab', 'van', 'bus', 'ferry'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modes' => 'array', 'active' => 'boolean', 'margin_value' => 'float'];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(TransportVehicle::class, 'provider_id')->orderBy('mode')->orderBy('seats')->orderBy('name');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class, 'provider_id')->orderBy('kind')->orderBy('title');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TransportRate::class, 'provider_id')->orderBy('vehicle_id')->orderBy('route_id')->orderBy('start_date');
    }
}
