<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripTask extends Model
{
    public const STATUSES = ['pending', 'in_progress', 'done', 'na'];
    public const CATEGORIES = ['payments', 'documents', 'transport', 'stay', 'visa_insurance', 'activities', 'forex_connectivity', 'pre_departure', 'post_trip', 'other'];

    protected $table = 'trip_tasks';
    protected $guarded = ['id'];
    protected $casts = ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'critical' => 'boolean'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'in_progress'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(now('Asia/Kolkata')->startOfDay());
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id, 'deal_id' => (string) $this->deal_id, 'category' => $this->category, 'title' => $this->title, 'notes' => $this->notes,
            'due_date' => $this->due_date?->format('Y-m-d'), 'status' => $this->status, 'critical' => (bool) $this->critical,
            'overdue' => $this->isOverdue(),
            'assigned_to' => $this->assignee ? ['id' => (string) $this->assignee->id, 'name' => $this->assignee->name] : null,
            'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            'position' => (int) $this->position,
        ];
    }
}
