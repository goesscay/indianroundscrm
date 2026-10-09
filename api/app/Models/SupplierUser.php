<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person at a supplier who may sign in to the supplier portal. Not an ERP user. */
class SupplierUser extends Model
{
    use StringId;

    protected $table = 'supplier_users';
    protected $fillable = ['supplier_id', 'name', 'email', 'active', 'last_login_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_login_at' => 'datetime', 'supplier_id' => 'string'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
