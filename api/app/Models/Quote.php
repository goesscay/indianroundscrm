<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use ApiModel;

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'superseded'];

    protected $table = 'quotes';
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id', 'deal_id', 'package_id', 'owner_id', 'created_by', 'root_id'];
    protected $casts = [
        'issue_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'travel_date' => 'date:Y-m-d',
        'sent_at' => 'datetime', 'accepted_at' => 'datetime',
        'adults' => 'integer', 'children' => 'integer', 'duration_days' => 'integer', 'version' => 'integer',
        'tcs_rate' => 'float', 'subtotal' => 'float', 'discount_total' => 'float', 'tax_total' => 'float', 'tcs_total' => 'float', 'total' => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(QuoteEmail::class)->orderByDesc('id');
    }

    /** Status shown to people: a sent quote past its validity date reads "expired". */
    public function displayStatus(): string
    {
        if ($this->status === 'sent' && $this->valid_until && $this->valid_until->lt(now()->startOfDay())) {
            return 'expired';
        }

        return $this->status;
    }

    public function itineraryArray(): array
    {
        $d = json_decode((string) $this->itinerary, true);

        return is_array($d) ? $d : [];
    }

    public function toApi(bool $detail = false): array
    {
        $a = $this->toArray();
        unset($a['customer'], $a['deal'], $a['owner'], $a['lines'], $a['emails'], $a['itinerary']);
        $a['display_status'] = $this->displayStatus();
        $a['customer'] = $this->customer ? $this->customer->brief() : null;
        $a['deal'] = $this->deal ? ['id' => (string) $this->deal->id, 'deal_no' => $this->deal->deal_no, 'name' => $this->deal->name] : null;
        $a['owner'] = $this->owner ? ['id' => (string) $this->owner->id, 'name' => $this->owner->name] : null;
        if ($detail) {
            $a['lines'] = $this->lines->map(fn ($l) => $l->toApi())->values()->all();
            $a['itinerary'] = $this->itineraryArray();
            $a['emails'] = $this->emails->map(fn ($e) => [
                'to' => $e->to_list, 'subject' => $e->subject, 'status' => $e->status, 'error' => $e->error,
                'with_pdf' => $e->with_pdf, 'created_at' => $e->created_at?->utc()->toIso8601String(),
            ])->values()->all();
        }

        return $a;
    }
}
