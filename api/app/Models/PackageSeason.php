<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class PackageSeason extends Model
{
    use ApiModel;

    protected $table = 'package_seasons';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['package_id'];
    protected $casts = [
        'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
        'adult_price' => 'float', 'child_price' => 'float', 'adult_cost' => 'float', 'child_cost' => 'float',
    ];
}
