<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use StringId;

    protected $table = 'media';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean', 'hotel_id' => 'string', 'width' => 'integer', 'height' => 'integer', 'size' => 'integer', 'position' => 'integer'];
    }

    /** What the screens need: the id, where it belongs, and the two image addresses (the full picture and a small one). */
    public function toApi(): array
    {
        return [
            'id' => (string) $this->id, 'hotel_id' => $this->hotel_id, 'destination' => $this->destination, 'kind' => $this->kind, 'caption' => $this->caption,
            'is_cover' => (bool) $this->is_cover, 'position' => (int) $this->position, 'width' => $this->width, 'height' => $this->height, 'size' => (int) $this->size,
            'url' => '/api/media/'.$this->id.'/file', 'thumb_url' => '/api/media/'.$this->id.'/file?size=thumb', 'original_name' => $this->original_name,
        ];
    }
}
