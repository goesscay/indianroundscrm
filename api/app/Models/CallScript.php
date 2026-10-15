<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class CallScript extends Model
{
    use StringId;

    protected $table = 'call_scripts';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['user_id' => 'string', 'updated_at' => 'datetime'];
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id,
            'scope' => $this->user_id === null ? 'team' : 'mine',
            'destination' => $this->destination,
            'title' => $this->title,
            'body' => $this->body,
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
