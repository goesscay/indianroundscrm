<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class QuoteContentBlock extends Model
{
    use StringId;

    public const KINDS = ['inclusions', 'exclusions', 'cancellation', 'notes', 'terms', 'closing'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'tiers' => 'array', 'is_default' => 'boolean', 'active' => 'boolean', 'position' => 'integer'];
    }
}
