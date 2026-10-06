<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    use StringId;

    public const TYPES = ['task', 'call', 'meeting', 'email', 'follow_up'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $table = 'activities';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'completed_by' => 'string',
            'assigned_to' => 'string',
            'created_by' => 'string',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id', 'lead_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function toApi(): array
    {
        $u = fn ($rel) => $rel ? ['id' => (string) $rel->id, 'name' => $rel->name] : null;
        $done = $this->completed_at !== null;

        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'details' => $this->details,
            'priority' => $this->priority,
            'due_at' => $this->due_at?->utc()->toIso8601String(),
            'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            'done' => $done,
            'overdue' => ! $done && $this->due_at !== null && $this->due_at->lt(now()),
            'assigned_to' => $u($this->assignee),
            'created_by' => $u($this->creator),
            'completed_by' => $u($this->completer),
            'lead' => $this->lead ? ['lead_id' => $this->lead->lead_id, 'customer_name' => $this->lead->customer_name] : null,
            'customer' => $this->customer ? ['id' => (string) $this->customer->id, 'name' => $this->customer->name] : null,
            'deal' => $this->deal ? ['id' => (string) $this->deal->id, 'name' => $this->deal->name] : null,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
