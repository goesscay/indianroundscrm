<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    use ApiModel;

    protected $table = 'credit_notes';
    protected $guarded = ['id'];
    protected $stringIds = ['invoice_id', 'customer_id', 'created_by'];
    protected $casts = ['amount' => 'float', 'taxable_part' => 'float', 'tax_part' => 'float', 'issue_date' => 'date:Y-m-d'];

    public function toApi(): array
    {
        return ['id' => (string) $this->id, 'credit_note_no' => $this->credit_note_no, 'issue_date' => $this->issue_date->format('Y-m-d'),
            'amount' => $this->amount, 'taxable_part' => $this->taxable_part, 'tax_part' => $this->tax_part, 'reason' => $this->reason];
    }
}
