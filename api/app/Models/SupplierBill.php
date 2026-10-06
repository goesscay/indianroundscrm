<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    use ApiModel;

    protected $table = 'supplier_bills';
    protected $guarded = ['id'];
    protected $stringIds = ['supplier_id', 'deal_id', 'created_by'];
    protected $casts = [
        'bill_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'total' => 'float', 'paid_total' => 'float', 'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class, 'bill_id')->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'bill_id')->orderBy('paid_date')->orderBy('id');
    }

    public function balance(): float
    {
        return $this->status === 'cancelled' ? 0.0 : max(0.0, round($this->total - $this->paid_total, 2));
    }

    /** open | partial | paid | overdue | cancelled */
    public function paymentStatus(): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }
        if ($this->balance() <= 0.005) {
            return 'paid';
        }
        if ($this->due_date && $this->due_date->lt(now('Asia/Kolkata')->startOfDay())) {
            return 'overdue';
        }

        return $this->paid_total > 0 ? 'partial' : 'open';
    }

    public function toApi(bool $detail = false): array
    {
        $a = $this->toArray();
        unset($a['supplier'], $a['deal'], $a['lines'], $a['payments']);
        $a['payment_status'] = $this->paymentStatus();
        $a['balance'] = $this->balance();
        $a['supplier'] = $this->supplier ? ['id' => (string) $this->supplier->id, 'name' => $this->supplier->name] : null;
        $a['deal'] = $this->deal ? ['id' => (string) $this->deal->id, 'deal_no' => $this->deal->deal_no, 'name' => $this->deal->name] : null;
        if ($detail) {
            $a['lines'] = $this->lines->map(fn ($l) => [
                'description' => $l->description, 'amount' => (float) $l->amount, 'account_id' => (string) $l->account_id,
                'account' => $l->account ? $l->account->code.' · '.$l->account->name : null, 'deal_id' => $l->deal_id ? (string) $l->deal_id : null,
            ])->values()->all();
            $a['payments'] = $this->payments->map(fn ($p) => $p->toApi())->values()->all();
        }

        return $a;
    }
}
