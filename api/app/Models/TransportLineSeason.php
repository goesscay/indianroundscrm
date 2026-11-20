<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class TransportLineSeason extends Model
{
    use StringId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'price' => 'float', 'line_id' => 'string'];
    }

    public function toApi(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'valid_from' => $this->valid_from?->toDateString(), 'valid_to' => $this->valid_to?->toDateString(), 'price' => $this->price,
            'dates_needed' => $this->valid_from === null || $this->valid_to === null];
    }
}
