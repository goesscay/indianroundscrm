<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupplierGroup extends Model
{
    use StringId;

    protected $fillable = ['name', 'destination'];

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_group_members', 'supplier_group_id', 'supplier_id');
    }
}
