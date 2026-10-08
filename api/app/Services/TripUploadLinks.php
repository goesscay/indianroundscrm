<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Secret links that let a customer upload their own documents for one trip, without a login. Only a hash of the token is stored. */
class TripUploadLinks
{
    public const DEFAULT_DAYS = 14;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('crm.public_url') ?: config('app.url'), '/').'/upload/'.$token;
    }

    /** Create a new link and revoke the earlier ones for the trip. @return array{token:string,url:string,expires_at:string} */
    public static function create(Deal $deal, ?User $user, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(60, $days));
        $token = Str::random(48);
        $expires = now()->addDays($days);
        DB::transaction(function () use ($deal, $user, $token, $expires) {
            DB::table('trip_upload_links')->where('deal_id', $deal->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('trip_upload_links')->insert(['deal_id' => $deal->id, 'token_hash' => self::hash($token), 'expires_at' => $expires, 'created_by' => $user?->id, 'created_at' => now()]);
        });

        return ['token' => $token, 'url' => self::url($token), 'expires_at' => $expires->utc()->toIso8601String()];
    }

    /** The live link row for a token, or null when it is unknown, revoked or expired. */
    public static function find(string $token): ?object
    {
        if (strlen($token) < 20) {
            return null;
        }
        $row = DB::table('trip_upload_links')->where('token_hash', self::hash($token))->first();
        if (! $row || $row->revoked_at !== null || now()->gt($row->expires_at)) {
            return null;
        }

        return $row;
    }

    public static function active(Deal $deal): ?object
    {
        return DB::table('trip_upload_links')->where('deal_id', $deal->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
    }
}
