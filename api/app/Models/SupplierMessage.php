<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class SupplierMessage extends Model
{
    use StringId;

    public const UPDATED_AT = null;

    protected $table = 'supplier_messages';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['supplier_read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
