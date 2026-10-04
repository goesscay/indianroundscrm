<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateLog extends Model
{
    protected $table = 'duplicate_log';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['timestamp' => 'datetime'];
}
