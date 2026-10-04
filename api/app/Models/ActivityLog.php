<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use StringId;

    protected $table = 'activity_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'timestamp' => 'datetime',
            'user_id' => 'string',
        ];
    }
}
