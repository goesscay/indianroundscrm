<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class ChatReaction extends Model
{
    use StringId;

    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'user_id', 'emoji', 'created_at'];

    protected function casts(): array
    {
        return ['message_id' => 'string', 'user_id' => 'string'];
    }
}
