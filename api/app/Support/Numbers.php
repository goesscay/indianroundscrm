<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Atomic running numbers (customer / deal ...) on the shared `counters` table. */
class Numbers
{
    public static function next(string $key, string $prefix, int $pad = 5): string
    {
        $row = DB::selectOne(
            'INSERT INTO counters ("key", seq) VALUES (?, 1)
             ON CONFLICT ("key") DO UPDATE SET seq = counters.seq + 1 RETURNING seq',
            [$key]
        );

        return $prefix.str_pad((string) $row->seq, $pad, '0', STR_PAD_LEFT);
    }

    /** Digits only, India-aware (+91 / leading 0 dropped) so +91 98765 43210 and 9876543210 match. */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return $d === '' ? null : $d;
    }
}
