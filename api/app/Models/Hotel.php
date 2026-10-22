<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    use StringId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amenities' => 'array', 'active' => 'boolean', 'margin_value' => 'float', 'google_rating' => 'float', 'stars' => 'integer', 'google_reviews' => 'integer'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HotelRoom::class)->orderBy('name');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Media::class)->orderByDesc('is_cover')->orderBy('position')->orderBy('id');
    }
}
