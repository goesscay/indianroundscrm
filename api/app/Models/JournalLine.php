<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $table = 'journal_lines';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['debit' => 'float', 'credit' => 'float'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
