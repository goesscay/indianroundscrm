<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use ApiModel;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];
    public const TYPE_LABELS = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];

    protected $table = 'accounts';
    protected $guarded = ['id'];
    protected $casts = ['is_system' => 'boolean', 'active' => 'boolean'];

    /** Assets and expenses grow with debits; the rest with credits. */
    public function debitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    /** Balance in the account's own direction (positive = normal side). */
    public function balanceOf(float $debit, float $credit): float
    {
        return round($this->debitNormal() ? $debit - $credit : $credit - $debit, 2);
    }
}
