<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserSession;
use App\Support\PasswordPolicy;
use App\Support\SecurityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! $request->hasSession()) {
            abort(400, 'Login requires a first-party (stateful) SPA request.');
        }

        $email = strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();
        if (! $user) {
            abort(401, 'Invalid email or password');
        }

        $maxAttempts = (int) config('auth.lockout_max_attempts', 5);
        $lockMinutes = (int) config('auth.lockout_minutes', 15);

        // Lockout window
        if ($user->locked_until) {
            if ($user->locked_until->isFuture()) {
                $mins = (int) (now()->diffInSeconds($user->locked_until, true) / 60) + 1;
                SecurityLogger::log($user, 'login_blocked_locked', $request);
                abort(423, "Account locked. Try again in {$mins} minute(s) or contact your administrator.");
            }
            // Lock expired - auto-unlock
            $user->locked_until = null;
            $user->failed_login_attempts = 0;
            if ($user->account_status === 'locked') {
                $user->account_status = 'active';
            }
            $user->save();
        }

        if ($user->account_status === 'inactive' || ! $user->active) {
            abort(403, 'Your account is inactive. Contact admin.');
        }
        if ($user->account_status === 'suspended') {
            abort(403, 'Your account is suspended. Contact admin.');
        }
        if ($user->account_status === 'locked') {
            abort(403, 'Your account is locked. Contact your administrator.');
        }

        if (! Hash::check($data['password'], $user->password)) {
            $attempts = (int) $user->failed_login_attempts + 1;
            $user->failed_login_attempts = $attempts;
            if ($attempts >= $maxAttempts) {
                $user->locked_until = now()->addMinutes($lockMinutes);
                $user->account_status = 'locked';
                $user->save();
                SecurityLogger::log($user, 'login_locked', $request, ['attempts' => $attempts]);
                abort(423, "Too many unsuccessful login attempts. Account locked for {$lockMinutes} minutes.");
            }
            $user->save();
            SecurityLogger::log($user, 'login_failed', $request, ['attempts' => $attempts]);
            abort(401, 'Invalid email or password. '.($maxAttempts - $attempts).' attempt(s) remaining.');
        }

        // Success: end any session previously attached to this browser, start a fresh one.
        $session = $request->session();
        if ($oldJti = $session->get('jti')) {
            UserSession::where('jti', $oldJti)->where('revoked', false)->first()?->revoke('relogin');
        }
        Auth::guard('web')->login($user);
        $session->regenerate();

        $jti = Str::random(32);
        $session->put('jti', $jti);
        $session->put('tv', (int) $user->token_version);
        $this->createSessionRow($user, $jti, $request);

        $user->forceFill([
            'failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(),
        ])->save();
        SecurityLogger::log($user, 'login_success', $request);

        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'role_name' => $user->role_name,
            'permissions' => $user->permissionKeys(),
            'staff_code' => $user->staff_code ?? '',
            'destinations' => $user->destinations ?? [],
            'default_destination' => $user->default_destination,
            'account_status' => $user->account_status,
            'password_reset_required' => $user->account_status === 'password_reset_required',
        ];
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['last_logout_at' => now()])->save();
        $row = $request->attributes->get('user_session');
        $row?->revoke('user_logout');
        SecurityLogger::log($user, 'logout', $request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['success' => true];
    }

    /** Sessions are server-side; refresh simply validates the session (EnforceSession bumps activity). */
    public function refresh(Request $request)
    {
        return ['success' => true];
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $out = $user->toArray();
        $out['permissions'] = $user->permissionKeys();
        $out['account_status'] = $user->account_status;
        $out['password_reset_required'] = $user->account_status === 'password_reset_required';
        $out['last_login_at'] = $user->last_login_at;
        $out['mobile'] = $user->mobile ?? '';

        return $out;
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['nullable', 'string'],
            'new_password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
        ]);
        if ($data['new_password'] !== $data['confirm_password']) {
            abort(400, 'New password and confirmation do not match.');
        }
        PasswordPolicy::validate($data['new_password']);

        $user = $request->user();
        $forced = $user->account_status === 'password_reset_required';
        if (! $forced) {
            if (empty($data['current_password']) || ! Hash::check($data['current_password'], $user->password)) {
                abort(401, 'Current password is incorrect.');
            }
        }
        if (Hash::check($data['new_password'], $user->password)) {
            abort(400, 'New password must be different from the current password.');
        }

        $currentJti = $request->session()->get('jti');
        $user->password = $data['new_password'];
        $user->account_status = 'active';
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->password_changed_at = now();
        $user->invalidateSessions('password_changed', $currentJti); // bumps token_version
        $user->save();
        // Keep the current session alive across the token_version bump.
        $request->session()->put('tv', (int) $user->token_version);
        $request->session()->regenerate();
        SecurityLogger::log($user, 'password_changed', $request, ['forced' => $forced]);

        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    public function forgotPassword(Request $request)
    {
        $generic = ['success' => true, 'message' => 'If an account with that email exists, a reset link has been sent.'];
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '') {
            return $generic;
        }
        $user = User::where('email', $email)->first();
        if (! $user) {
            return $generic;
        }

        // Invalidate earlier outstanding tokens, issue a new one (valid 1 hour, single use).
        PasswordResetToken::where('user_id', $user->id)->where('used', false)->update(['used' => true, 'used_at' => now()]);
        $token = Str::random(64);
        PasswordResetToken::create([
            'token_hash' => PasswordResetToken::hash($token),
            'user_id' => $user->id,
            'email' => $email,
            'expires_at' => now()->addHour(),
        ]);

        $link = rtrim(config('app.frontend_url'), '/').'/reset-password/'.$token;
        // Same Microsoft 365 transport as supplier mail (never throws; failures come back as status 'failed').
        $result = \App\Crm\MailSender::send(
            [$email],
            'Password Reset — Indian Rounds ERP',
            $this->resetEmailText($user->name, $link)
        );
        if (($result['status'] ?? '') === 'failed') {
            SecurityLogger::log($user, 'password_reset_email_failed', $request, ['error' => $result['error'] ?? 'unknown']);
        } else {
            SecurityLogger::log($user, 'password_reset_requested', $request, ['status' => $result['status'] ?? 'sent']);
        }

        return $generic;
    }

    public function verifyResetToken(string $token)
    {
        $doc = $this->findToken($token);

        return ['email' => $doc->email, 'valid' => true];
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'new_password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
        ]);
        if ($data['new_password'] !== $data['confirm_password']) {
            abort(400, 'New password and confirmation do not match.');
        }
        PasswordPolicy::validate($data['new_password']);
        $doc = $this->findToken($data['token'], 'Invalid reset token');

        $user = User::find($doc->user_id);
        if (! $user) {
            abort(404, 'User not found');
        }
        if (Hash::check($data['new_password'], $user->password)) {
            abort(400, 'New password must be different from the current password.');
        }

        $user->password = $data['new_password'];
        $user->account_status = 'active';
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->password_changed_at = now();
        $user->invalidateSessions('password_reset'); // bumps token_version, revokes all sessions
        $user->save();
        $doc->forceFill(['used' => true, 'used_at' => now()])->save();
        SecurityLogger::log($user, 'password_reset_completed', $request);

        return ['success' => true, 'message' => 'Password reset. Please log in with your new password.'];
    }

    private function findToken(string $token, string $notFound = 'Invalid or unknown reset token'): PasswordResetToken
    {
        $doc = PasswordResetToken::where('token_hash', PasswordResetToken::hash($token))->first();
        if (! $doc) {
            abort(404, $notFound);
        }
        if ($doc->used) {
            abort(410, 'This reset link has already been used.');
        }
        if ($doc->expires_at->isPast()) {
            abort(410, 'This reset link has expired. Please request a new one.');
        }

        return $doc;
    }

    private function createSessionRow(User $user, string $jti, Request $request): UserSession
    {
        $ua = (string) $request->userAgent();

        return UserSession::create([
            'user_id' => $user->id,
            'jti' => $jti,
            'session_id' => $request->session()->getId(),
            'ip' => mb_substr((string) $request->ip(), 0, 64),
            'user_agent' => mb_substr($ua, 0, 250),
            'device' => UserSession::deviceFromUserAgent($ua),
            'created_at' => now(),
            'last_active_at' => now(),
            'revoked' => false,
        ]);
    }

    private function resetEmailText(?string $name, string $link): string
    {
        return implode("\n", [
            'Hi '.($name ?: 'there').',',
            '',
            'We received a request to reset the password for your Indian Rounds ERP account.',
            '',
            'Click the link below to set a new password. This link is valid for 1 hour and can only be used once.',
            '',
            $link,
            '',
            'If you did not request a password reset, you can safely ignore this email — your password will not change.',
            '',
            'For security, we recommend never sharing this link with anyone.',
            '',
            'Regards,',
            'Indian Rounds — ERP Security Team',
        ]);
    }
}
