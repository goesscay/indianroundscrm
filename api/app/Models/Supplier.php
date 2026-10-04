<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use StringId;

    protected $fillable = ['name', 'email', 'phone', 'destination', 'country'];
}
