<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripTemplateItem extends Model
{
    public $timestamps = false;
    protected $table = 'trip_template_items';
    protected $guarded = ['id'];
    protected $casts = ['critical' => 'boolean'];
}
