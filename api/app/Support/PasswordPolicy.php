<?php

namespace App\Support;

class PasswordPolicy
{
    /** Aborts with HTTP 400 {"detail": "..."} when the password fails policy. */
    public static function validate(?string $password): void
    {
        if (! $password || mb_strlen($password) < 8) {
            abort(400, 'Password must be at least 8 characters.');
        }
        if (! preg_match('/[A-Z]/', $password)) {
            abort(400, 'Password must contain at least 1 uppercase letter.');
        }
        if (! preg_match('/[a-z]/', $password)) {
            abort(400, 'Password must contain at least 1 lowercase letter.');
        }
        if (! preg_match('/\d/', $password)) {
            abort(400, 'Password must contain at least 1 number.');
        }
        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            abort(400, 'Password must contain at least 1 special character.');
        }
    }
}
