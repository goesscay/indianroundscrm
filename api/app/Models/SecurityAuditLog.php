<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class SecurityAuditLog extends Model
{
    use StringId;

    protected $table = 'security_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['extra' => 'array', 'timestamp' => 'datetime', 'user_id' => 'string'];
    }
}
