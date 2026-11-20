<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    use StringId;

    public const KINDS = ['sightseeing', 'transfer', 'intercity'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'provider_id' => 'string', 'from_place_id' => 'string', 'to_place_id' => 'string'];
    }
}
