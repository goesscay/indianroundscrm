<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailHistory extends Model
{
    use ApiModel;

    protected $table = 'mail_history';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['recipients'];
    protected $casts = ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'webhook_last_at' => 'datetime'];

    public function recipients(): HasMany
    {
        return $this->hasMany(MailRecipient::class, 'mail_history_id');
    }

    public function toApi(): array
    {
        $a = $this->toArray();
        $r = $this->recipients;
        $a['to_suppliers'] = $r->where('type', 'to')->map(fn ($x) => [
            'id' => $x->supplier_id !== null ? (string) $x->supplier_id : null,
            'name' => $x->name, 'email' => $x->email,
        ])->values()->all();
        $a['cc'] = $r->where('type', 'cc')->pluck('email')->values()->all();
        $a['bcc'] = $r->where('type', 'bcc')->pluck('email')->values()->all();
        return $a;
    }
}
