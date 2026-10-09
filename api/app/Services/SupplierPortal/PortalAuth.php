<?php

namespace App\Services\SupplierPortal;

use App\Crm\MailSender;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in for supplier staff: a 6-digit code mailed to a registered address, then a random session token in an
 * HttpOnly cookie. Nothing here touches the ERP's own users, sessions or guards, so a supplier can never be an ERP user.
 */
class PortalAuth
{
    public const COOKIE = 'ir_portal';
    public const CODE_MINUTES = 10;
    public const SESSION_HOURS = 12;
    public const MAX_CODE_ATTEMPTS = 5;

    private static function hash(string $v): string
    {
        return hash_hmac('sha256', $v, (string) config('app.key'));
    }

    public static function url(string $path = '/portal'): string
    {
        return rtrim((string) config('crm.public_url') ?: config('app.url'), '/').$path;
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Mails a code when the address belongs to an active user of a portal-enabled supplier. Says nothing either way. */
    public static function sendCode(string $email, string $ip): void
    {
        $email = self::normalise($email);
        $ipKey = 'portal-code-ip:'.$ip;
        $mailKey = 'portal-code:'.sha1($email);
        if (RateLimiter::tooManyAttempts($ipKey, 20) || RateLimiter::tooManyAttempts($mailKey, 5)) {
            return;
        }
        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($mailKey, 3600);

        $user = SupplierUser::with('supplier')->whereRaw('lower(email) = ?', [$email])->where('active', true)->first();
        if (! $user || ! $user->supplier || ! $user->supplier->portal_enabled) {
            return;
        }
        DB::table('supplier_login_codes')->where('supplier_user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('supplier_login_codes')->insert([
            'supplier_user_id' => $user->id, 'code_hash' => self::hash($user->id.':'.$code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'attempts' => 0, 'created_at' => now(),
        ]);
        $company = (string) config('crm.company.name', 'Indian Rounds');
        MailSender::send([$user->email], "Your $company supplier portal code: $code", implode("\n", [
            'Hello'.($user->name ? ' '.$user->name : '').',', '',
            "Your one-time code for the $company supplier portal is:", '', "    $code", '',
            'It works for '.self::CODE_MINUTES.' minutes and only once. If you did not ask for it, you can ignore this e-mail.', '',
            'Regards,', $company,
        ]));
    }

    /** @return array{token:string,user:SupplierUser}|null null on a wrong, used or expired code */
    public static function verify(string $email, string $code, string $ip): ?array
    {
        $email = self::normalise($email);
        $ipKey = 'portal-verify-ip:'.$ip;
        $mailKey = 'portal-verify:'.sha1($email);
        if (RateLimiter::tooManyAttempts($ipKey, 40) || RateLimiter::tooManyAttempts($mailKey, 15)) {
            return null;
        }
        RateLimiter::hit($ipKey, 900);
        RateLimiter::hit($mailKey, 900);

        $user = SupplierUser::with('supplier')->whereRaw('lower(email) = ?', [$email])->where('active', true)->first();
        if (! $user || ! $user->supplier || ! $user->supplier->portal_enabled) {
            return null;
        }
        $row = DB::table('supplier_login_codes')->where('supplier_user_id', $user->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $row || $row->attempts >= self::MAX_CODE_ATTEMPTS) {
            return null;
        }
        if (! hash_equals($row->code_hash, self::hash($user->id.':'.preg_replace('/\D/', '', $code)))) {
            DB::table('supplier_login_codes')->where('id', $row->id)->increment('attempts');

            return null;
        }
        DB::table('supplier_login_codes')->where('id', $row->id)->update(['used_at' => now()]);
        RateLimiter::clear($mailKey);

        $token = Str::random(48);
        DB::table('supplier_sessions')->insert([
            'supplier_user_id' => $user->id, 'token_hash' => self::hash($token),
            'expires_at' => now()->addHours(self::SESSION_HOURS), 'last_seen_at' => now(), 'ip' => mb_substr($ip, 0, 64), 'created_at' => now(),
        ]);
        DB::table('supplier_sessions')->where('expires_at', '<', now()->subDays(2))->delete();
        $user->forceFill(['last_login_at' => now()])->save();

        return ['token' => $token, 'user' => $user];
    }

    /** The signed-in supplier user for this request, or null. */
    public static function resolve(Request $request): ?SupplierUser
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if (strlen($token) < 40) {
            return null;
        }
        $row = DB::table('supplier_sessions')->where('token_hash', self::hash($token))->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        $user = SupplierUser::with('supplier')->find($row->supplier_user_id);
        if (! $user || ! $user->active || ! $user->supplier || ! $user->supplier->portal_enabled) {
            return null;
        }
        if (! $row->last_seen_at || now()->diffInSeconds($row->last_seen_at, true) > 60) {
            DB::table('supplier_sessions')->where('id', $row->id)->update(['last_seen_at' => now()]);
        }

        return $user;
    }

    public static function logout(Request $request): void
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if ($token !== '') {
            DB::table('supplier_sessions')->where('token_hash', self::hash($token))->delete();
        }
    }
}
