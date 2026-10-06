<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use ApiModel;

    public const MODES = ['upi', 'bank_transfer', 'card', 'cash', 'cheque', 'other'];

    protected $table = 'receipts';
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id', 'invoice_id', 'deal_id', 'created_by'];
    protected $casts = ['amount' => 'float', 'received_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toApi(): array
    {
        $a = $this->toArray();
        unset($a['customer'], $a['invoice'], $a['creator']);
        $a['customer'] = $this->relationLoaded('customer') && $this->customer ? $this->customer->brief() : null;
        $a['invoice'] = $this->relationLoaded('invoice') && $this->invoice ? ['id' => (string) $this->invoice->id, 'invoice_no' => $this->invoice->invoice_no, 'title' => $this->invoice->title] : null;
        $a['created_by_name'] = $this->relationLoaded('creator') ? $this->creator?->name : null;

        return $a;
    }
}
