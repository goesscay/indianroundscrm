<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class Master extends Model
{
    use StringId;

    protected $table = 'master';

    public $timestamps = false;

    protected $fillable = ['category', 'value'];

    protected $attributes = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->created_at ??= now());
    }
}
