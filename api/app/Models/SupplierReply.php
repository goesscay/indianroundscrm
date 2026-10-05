<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReply extends Model
{
    use StringId;

    public const STATUSES = ['matched', 'unmatched', 'auto_reply', 'bounce', 'ignored'];

    protected $table = 'supplier_replies';
    protected $guarded = ['id'];
    protected $hidden = ['body_text'];

    protected function casts(): array
    {
        return [
            'to_emails' => 'array', 'cc_emails' => 'array',
            'received_at' => 'datetime', 'handled_at' => 'datetime', 'notified_at' => 'datetime',
            'supplier_id' => 'string', 'lead_supplier_id' => 'string', 'mail_history_id' => 'string',
            'handled_by' => 'string', 'activity_id' => 'string',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id', 'lead_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function leadSupplier(): BelongsTo
    {
        return $this->belongsTo(LeadSupplier::class, 'lead_supplier_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupplierReplyAttachment::class, 'reply_id')->orderBy('id');
    }

    /** API shape. $readIds = reply ids the caller has UNREAD (null => treat as read). */
    public function toApi(array $unreadIds = []): array
    {
        $ls = $this->leadSupplier;
        $sup = $this->supplier;
        $supplier = $ls
            ? ['id' => (string) $ls->supplier_id, 'name' => $ls->supplier_name, 'email' => $ls->supplier_email]
            : ($sup ? ['id' => (string) $sup->id, 'name' => $sup->name, 'email' => $sup->email] : null);

        return [
            'id' => (string) $this->id,
            'lead_id' => $this->lead_id,
            'customer_name' => $this->lead?->customer_name,
            'supplier' => $supplier,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'subject' => $this->subject,
            'body_text' => $this->body_text,
            'preview' => $this->body_preview,
            'received_at' => $this->received_at?->utc()->toIso8601String(),
            'status' => $this->status,
            'matched_by' => $this->matched_by,
            'read' => ! in_array((int) $this->id, $unreadIds, true),
            'attachments' => $this->attachments->map(fn ($a) => $a->toApi())->values()->all(),
        ];
    }
}
