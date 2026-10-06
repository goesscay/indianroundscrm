<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyEntry extends Model
{
    use ApiModel;

    protected $table = 'money_entries';
    protected $guarded = ['id'];
    protected $stringIds = ['account_id', 'bank_account_id', 'created_by'];
    protected $casts = ['amount' => 'float', 'entry_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toApi(): array
    {
        $a = $this->toArray();
        unset($a['account']);
        $a['account'] = $this->account ? ['id' => (string) $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name] : null;

        return $a;
    }
}
