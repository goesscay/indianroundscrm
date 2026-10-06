<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use ApiModel;

    protected $table = 'invoices';
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id', 'deal_id', 'quote_id', 'owner_id', 'created_by'];
    protected $casts = [
        'issue_date' => 'date:Y-m-d', 'travel_date' => 'date:Y-m-d', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime',
        'adults' => 'integer', 'children' => 'integer',
        'tcs_rate' => 'float', 'subtotal' => 'float', 'discount_total' => 'float', 'tax_total' => 'float', 'cgst_total' => 'float',
        'sgst_total' => 'float', 'igst_total' => 'float', 'tcs_total' => 'float', 'total' => 'float', 'paid_total' => 'float', 'credited_total' => 'float',
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
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(InvoiceInstallment::class)->orderBy('position');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('received_date')->orderBy('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    /** What the customer still owes: invoice total less credit notes less money received (never below 0). */
    public function balance(): float
    {
        return max(0.0, round($this->total - $this->credited_total - $this->paid_total, 2));
    }

    /** Money received beyond what is owed (after a credit note), which can be refunded. */
    public function refundable(): float
    {
        return $this->status === 'issued' ? max(0.0, round($this->paid_total - ($this->total - $this->credited_total), 2)) : 0.0;
    }

    /** Net money received allocated first-in-first-out to the instalments: [{id,label,due_date,amount,paid,status}]. */
    public function scheduleStatus(): array
    {
        $left = max(0.0, $this->paid_total);
        $today = Carbon::now('Asia/Kolkata')->startOfDay();
        $out = [];
        foreach ($this->installments as $i) {
            $paid = min($i->amount, $left);
            $left -= $paid;
            $due = Carbon::parse($i->due_date->format('Y-m-d'), 'Asia/Kolkata');
            $status = $paid + 0.005 >= $i->amount ? 'paid' : ($due->lt($today) ? 'overdue' : ($paid > 0 ? 'partial' : 'due'));
            $out[] = ['label' => $i->label, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => $i->amount, 'paid' => round($paid, 2), 'status' => $status];
        }

        return $out;
    }

    /** draft | cancelled | credited | paid | overdue | partial | unpaid */
    public function paymentStatus(): string
    {
        if ($this->status !== 'issued') {
            return $this->status;
        }
        if ($this->credited_total + 0.005 >= $this->total && $this->total > 0) {
            return 'credited';
        }
        if ($this->balance() <= 0.005) {
            return 'paid';
        }
        foreach ($this->scheduleStatus() as $s) {
            if ($s['status'] === 'overdue') {
                return 'overdue';
            }
        }
        if ($this->installments->isEmpty() && $this->issue_date && $this->issue_date->lt(now()->subDays(7)->startOfDay())) {
            return 'overdue';
        }

        return $this->paid_total > 0 ? 'partial' : 'unpaid';
    }

    public function toApi(bool $detail = false): array
    {
        $a = $this->toArray();
        unset($a['customer'], $a['deal'], $a['owner'], $a['lines'], $a['installments'], $a['receipts'], $a['credit_notes']);
        $a['payment_status'] = $this->paymentStatus();
        $a['balance'] = $this->balance();
        $a['refundable'] = $this->refundable();
        $a['customer'] = $this->customer ? $this->customer->brief() : null;
        $a['deal'] = $this->deal ? ['id' => (string) $this->deal->id, 'deal_no' => $this->deal->deal_no, 'name' => $this->deal->name] : null;
        $a['owner'] = $this->owner ? ['id' => (string) $this->owner->id, 'name' => $this->owner->name] : null;
        $a['next_due'] = collect($this->scheduleStatus())->first(fn ($s) => $s['status'] !== 'paid')['due_date'] ?? null;
        if ($detail) {
            $a['lines'] = $this->lines->map(fn ($l) => $l->toApi())->values()->all();
            $a['installments'] = $this->scheduleStatus();
            $a['receipts'] = $this->receipts->map(fn ($r) => $r->toApi())->values()->all();
            $a['credit_notes'] = $this->creditNotes->map(fn ($c) => $c->toApi())->values()->all();
        }

        return $a;
    }
}
