<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class CrmNote extends Model
{
    use ApiModel;

    protected $table = 'crm_notes';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['entity_id', 'created_by_id'];
    protected $casts = ['created_at' => 'datetime'];
}
