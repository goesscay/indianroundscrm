<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReplyAttachment extends Model
{
    use StringId;

    protected $table = 'supplier_reply_attachments';
    protected $guarded = ['id'];
    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['skipped' => 'boolean', 'size' => 'integer', 'reply_id' => 'string'];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(SupplierReply::class, 'reply_id');
    }

    public function toApi(): array
    {
        return [
            'id' => (string) $this->id, 'filename' => $this->filename, 'content_type' => $this->content_type,
            'size' => (int) $this->size, 'skipped' => (bool) $this->skipped, 'skip_reason' => $this->skip_reason,
        ];
    }
}
