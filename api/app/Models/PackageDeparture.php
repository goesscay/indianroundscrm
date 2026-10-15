<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageDeparture extends Model
{
    protected $table = 'package_departures';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'seats_left' => 'integer'];
    }
}
