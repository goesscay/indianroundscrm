<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripTemplate extends Model
{
    public const TYPES = ['domestic', 'international', 'package'];

    protected $table = 'trip_templates';
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(TripTemplateItem::class, 'template_id')->orderBy('position')->orderBy('id');
    }

    public function toApi(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'trip_type' => $this->trip_type, 'active' => (bool) $this->active,
            'items' => $this->items->map(fn ($i) => ['id' => (string) $i->id, 'category' => $i->category, 'title' => $i->title,
                'due_days_before' => $i->due_days_before, 'critical' => (bool) $i->critical])->values()->all()];
    }
}
