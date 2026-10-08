<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use ApiModel;

    public const STAGES = ['enquiry', 'quoted', 'negotiation', 'won', 'lost'];
    public const TRIP_STATUSES = ['confirmed', 'docs_collected', 'travelling', 'completed'];
    public const PROBABILITY = ['enquiry' => 10, 'quoted' => 40, 'negotiation' => 70, 'won' => 100, 'lost' => 0];

    protected $table = 'deals';
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id', 'owner_id', 'created_by'];
    protected $casts = [
        'selling_amount' => 'float',
        'supplier_cost' => 'float',
        'probability' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'duration_days' => 'integer',
        'travel_date' => 'date:Y-m-d',
        'expected_close' => 'date:Y-m-d',
        'closed_at' => 'datetime',
        'trip_confirmed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['won', 'lost'], true);
    }

    public function toApi(): array
    {
        $a = $this->toArray();
        unset($a['customer'], $a['owner']);
        $a['customer'] = $this->customer ? $this->customer->brief() : null;
        $a['owner'] = $this->owner ? ['id' => (string) $this->owner->id, 'name' => $this->owner->name] : null;
        $a['margin'] = round((float) $this->selling_amount - (float) $this->supplier_cost, 2);
        $a['weighted'] = round((float) $this->selling_amount * ((int) $this->probability) / 100, 2);
        $a['overdue'] = $this->isOpen() && $this->expected_close !== null && $this->expected_close->lt(now()->startOfDay());

        return $a;
    }
}
