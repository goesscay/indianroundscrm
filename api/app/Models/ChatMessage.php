<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use StringId;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'sender_id', 'body', 'deleted_at'];

    protected function casts(): array
    {
        return ['conversation_id' => 'string', 'sender_id' => 'string', 'deleted_at' => 'datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
