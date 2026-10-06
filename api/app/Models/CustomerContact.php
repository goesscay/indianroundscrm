<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class CustomerContact extends Model
{
    use ApiModel;

    protected $table = 'customer_contacts';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id'];
    protected $casts = ['is_primary' => 'boolean'];
}
