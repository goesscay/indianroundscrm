<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuote extends Model
{
    use StringId;

    protected $table = 'supplier_quotes';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float', 'flight_included' => 'boolean', 'transfer_included' => 'boolean',
            'valid_until' => 'date:Y-m-d', 'version' => 'integer',
        ];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(SupplierReply::class, 'reply_id');
    }
}
