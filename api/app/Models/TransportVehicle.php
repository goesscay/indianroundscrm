<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportVehicle extends Model
{
    use StringId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'seats' => 'integer', 'provider_id' => 'string'];
    }
}
