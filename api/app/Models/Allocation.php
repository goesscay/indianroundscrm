<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class Allocation extends Model
{
    use StringId;

    protected $fillable = ['destination', 'consultant_id'];

    protected function casts(): array
    {
        return ['consultant_id' => 'string'];
    }

    public function consultant()
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }
}
