<?php

namespace App\Support;

class Ids
{
    /** Parse a path id; non-numeric -> 400 "Invalid ID format" (matches old oid()). */
    public static function parse(string $id): int
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            abort(400, 'Invalid ID format');
        }

        return (int) $id;
    }
}
