<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class ContactEvent extends Model
{
    use ApiModel;

    protected $table = 'contact_events';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['user_id'];
    protected $casts = ['timestamp' => 'datetime'];
}
