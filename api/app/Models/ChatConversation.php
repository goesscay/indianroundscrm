<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    use StringId;

    protected $fillable = ['type', 'name', 'direct_key', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return ['created_by' => 'string', 'last_message_at' => 'datetime'];
    }

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'conversation_id', 'user_id');
    }
}
