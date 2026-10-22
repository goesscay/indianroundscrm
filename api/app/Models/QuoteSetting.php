<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Small key / value settings of the quotation (default margins). */
class QuoteSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? ($row->value ?? $default) : $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}
