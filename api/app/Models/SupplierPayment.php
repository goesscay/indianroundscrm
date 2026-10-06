<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use ApiModel;

    protected $table = 'supplier_payments';
    protected $guarded = ['id'];
    protected $stringIds = ['supplier_id', 'bill_id', 'bank_account_id', 'created_by'];
    protected $casts = ['amount' => 'float', 'paid_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'bill_id');
    }

    public function toApi(): array
    {
        $a = $this->toArray();
        unset($a['supplier'], $a['bill']);
        $a['supplier'] = $this->relationLoaded('supplier') && $this->supplier ? ['id' => (string) $this->supplier->id, 'name' => $this->supplier->name] : null;
        $a['bill'] = $this->relationLoaded('bill') && $this->bill ? ['id' => (string) $this->bill->id, 'bill_no' => $this->bill->bill_no, 'supplier_ref' => $this->bill->supplier_ref] : null;

        return $a;
    }
}
