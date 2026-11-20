<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportDestination extends Model
{
    use StringId;

    protected $guarded = ['id'];
}
