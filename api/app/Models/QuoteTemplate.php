<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class QuoteTemplate extends Model
{
    use StringId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'active' => 'boolean', 'position' => 'integer'];
    }
}
