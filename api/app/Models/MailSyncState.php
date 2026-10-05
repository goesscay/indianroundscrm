<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tiny key/value store for the mail poller (checkpoint, last poll / success / error). */
class MailSyncState extends Model
{
    protected $table = 'mail_sync_state';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = static::query()->whereKey($key)->value('value');

        return $v ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}
