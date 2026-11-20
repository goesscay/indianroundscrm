<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportPlace extends Model
{
    use StringId;

    public const KINDS = ['city', 'airport', 'station', 'bus_stand', 'harbour', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
