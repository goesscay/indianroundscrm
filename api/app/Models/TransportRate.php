<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportRate extends Model
{
    use StringId;

    /** How a rate is charged: per vehicle per day, per transfer, per route package, or per person. */
    public const BASES = ['per_day' => 'Per vehicle, per day', 'per_transfer' => 'Per vehicle, per transfer', 'per_route' => 'Per route package', 'per_person' => 'Per person'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'cost' => 'float', 'child_cost' => 'float', 'provider_id' => 'string', 'vehicle_id' => 'string', 'route_id' => 'string'];
    }
}
