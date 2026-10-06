<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLine extends Model
{
    protected $table = 'supplier_bill_lines';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
