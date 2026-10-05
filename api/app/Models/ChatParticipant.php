<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Model
{
    use StringId;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'user_id', 'last_read_message_id'];

    protected function casts(): array
    {
        return ['conversation_id' => 'string', 'user_id' => 'string', 'last_read_message_id' => 'string'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
