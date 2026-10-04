<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class LeadNote extends Model
{
    use ApiModel;

    protected $table = 'lead_notes';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['created_by_id'];
    protected $casts = ['created_at' => 'datetime'];
}
