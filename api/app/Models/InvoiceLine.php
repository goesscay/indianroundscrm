<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $table = 'invoice_lines';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['qty' => 'float', 'unit_price' => 'float', 'discount' => 'float', 'tax_rate' => 'float', 'amount' => 'float', 'tax' => 'float'];

    public function toApi(): array
    {
        return [
            'kind' => $this->kind, 'description' => $this->description, 'qty' => $this->qty, 'unit_price' => $this->unit_price,
            'discount' => $this->discount, 'tax_rate' => $this->tax_rate, 'amount' => $this->amount, 'tax' => $this->tax,
            'total' => round($this->amount + $this->tax, 2),
        ];
    }
}
