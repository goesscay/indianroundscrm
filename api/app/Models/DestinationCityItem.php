<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DestinationCityItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['destination_city_id', 'city', 'position'];
}
