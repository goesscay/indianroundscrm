<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageDay extends Model
{
    protected $table = 'package_days';
    public $timestamps = false;
    protected $guarded = ['id'];
}
