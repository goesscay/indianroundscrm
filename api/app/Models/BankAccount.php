<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use ApiModel;

    public const KINDS = ['bank', 'cash', 'card', 'wallet'];

    protected $table = 'bank_accounts';
    protected $guarded = ['id'];
    protected $stringIds = ['account_id'];
    protected $casts = ['is_default' => 'boolean', 'active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
