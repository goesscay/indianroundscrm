<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelRoom extends Model
{
    use StringId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'max_adults' => 'integer', 'hotel_id' => 'string'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(HotelRate::class, 'room_id')->orderBy('meal_plan')->orderBy('start_date');
    }
}
