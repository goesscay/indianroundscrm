<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use ApiModel;

    protected $table = 'journal_entries';
    protected $guarded = ['id'];
    protected $stringIds = ['source_id', 'reverses_id', 'created_by'];
    protected $casts = ['entry_date' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id')->orderBy('id');
    }

    public function toApi(bool $detail = false): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : collect();
        $a = $this->toArray();
        unset($a['lines']);
        $a['total'] = round((float) $lines->sum('debit'), 2);
        $a['reversed'] = $this->reversed_at !== null;
        $a['is_reversal'] = $this->reverses_id !== null;
        $a['editable_by_hand'] = $this->source_type === 'manual' && $this->reversed_at === null && $this->reverses_id === null;
        if ($detail) {
            $a['lines'] = $lines->map(fn ($l) => [
                'account_id' => (string) $l->account_id, 'account_code' => $l->account?->code, 'account_name' => $l->account?->name,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'memo' => $l->memo,
                'customer_id' => $l->customer_id ? (string) $l->customer_id : null,
            ])->values()->all();
        }

        return $a;
    }
}
