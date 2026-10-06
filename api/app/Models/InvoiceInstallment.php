<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceInstallment extends Model
{
    protected $table = 'invoice_installments';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['due_date' => 'date:Y-m-d', 'amount' => 'float'];
}
