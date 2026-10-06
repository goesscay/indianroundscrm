<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReply extends Model
{
    protected $table = 'customer_replies';
    protected $guarded = ['id'];
    protected $casts = ['received_at' => 'datetime', 'notified_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id,
            'from_email' => $this->from_email, 'from_name' => $this->from_name,
            'subject' => $this->subject, 'preview' => $this->body_preview, 'body' => $this->body_text,
            'received_at' => $this->received_at?->utc()->toIso8601String(),
            'matched_by' => $this->matched_by,
            'customer' => $this->customer ? ['id' => (string) $this->customer->id, 'name' => $this->customer->name] : null,
            'deal' => $this->deal ? ['id' => (string) $this->deal->id, 'deal_no' => $this->deal->deal_no, 'name' => $this->deal->name] : null,
            'quote' => $this->quote ? ['id' => (string) $this->quote->id, 'quote_no' => $this->quote->quote_no] : null,
            'invoice' => $this->invoice ? ['id' => (string) $this->invoice->id, 'invoice_no' => $this->invoice->invoice_no] : null,
        ];
    }
}
