<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelRate extends Model
{
    use StringId;

    public const MEALS = ['EP' => 'Room only', 'CP' => 'Breakfast', 'MAP' => 'Breakfast and dinner', 'AP' => 'All meals'];
    public const PRICES = ['single', 'double', 'triple', 'extra_adult', 'child_with_bed', 'child_no_bed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'room_id' => 'string',
            'single' => 'float', 'double' => 'float', 'triple' => 'float', 'extra_adult' => 'float', 'child_with_bed' => 'float', 'child_no_bed' => 'float'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HotelRoom::class, 'room_id');
    }
}
