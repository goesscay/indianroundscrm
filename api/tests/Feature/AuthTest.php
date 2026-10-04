<?php

namespace Tests\Feature;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    public function test_login_returns_old_shape_and_me_works(): void
    {
        $u = User::factory()->admin()->create(['staff_code' => 'ADM9']);
        $this->loginAs($u)->assertJson([
            'id' => (string) $u->id, 'email' => $u->email, 'role' => 'admin',
            'staff_code' => 'ADM9', 'account_status' => 'active', 'password_reset_required' => false,
        ]);
        $this->assertDatabaseHas('security_audit_log', ['event' => 'login_success', 'user_email' => $u->email]);
        $this->assertSame(1, UserSession::where('user_id', $u->id)->count());

        $me = $this->api('GET', '/api/auth/me')->assertOk()->json();
        $this->assertSame((string) $u->id, $me['id']);
        $this->assertArrayNotHasKey('password', $me);
    }

    public function test_unauthenticated_requests_get_json_401(): void
    {
        $this->api('GET', '/api/auth/me')->assertStatus(401)->assertExactJson(['detail' => 'Not authenticated']);
        $this->api('GET', '/api/users')->assertStatus(401);
    }

    public function test_invalid_credentials(): void
    {
        $u = User::factory()->create();
        $this->login('nobody@example.com', 'x')->assertStatus(401)->assertJson(['detail' => 'Invalid email or password']);
        $this->login($u->email, 'wrong')->assertStatus(401)->assertJsonPath('detail', 'Invalid email or password. 4 attempt(s) remaining.');
        $this->assertSame(1, $u->fresh()->failed_login_attempts);
    }

    public function test_lockout_after_five_failures_returns_423_even_with_correct_password(): void
    {
        $u = User::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            $this->login($u->email, 'bad')->assertStatus(401);
        }
        $this->login($u->email, 'bad')->assertStatus(423);
        $fresh = $u->fresh();
        $this->assertSame('locked', $fresh->account_status);
        $this->assertTrue($fresh->locked_until->isFuture());

        $this->login($u->email, 'Passw0rd!x')->assertStatus(423);
        $this->assertDatabaseHas('security_audit_log', ['event' => 'login_locked']);
        $this->assertDatabaseHas('security_audit_log', ['event' => 'login_blocked_locked']);
    }

    public function test_expired_lock_auto_unlocks(): void
    {
        $u = User::factory()->create(['account_status' => 'locked', 'locked_until' => now()->subMinute(), 'failed_login_attempts' => 5]);
        $this->login($u->email, 'Passw0rd!x')->assertOk();
        $this->assertSame('active', $u->fresh()->account_status);
        $this->assertSame(0, $u->fresh()->failed_login_attempts);
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        $u = User::factory()->create();
        $this->login($u->email, 'bad');
        $this->loginAs($u);
        $this->assertSame(0, $u->fresh()->failed_login_attempts);
    }

    public function test_inactive_and_suspended_accounts_cannot_login(): void
    {
        $a = User::factory()->create(['account_status' => 'inactive']);
        $b = User::factory()->create(['account_status' => 'suspended']);
        $c = User::factory()->create(['active' => false]);
        $this->login($a->email, 'Passw0rd!x')->assertStatus(403);
        $this->login($b->email, 'Passw0rd!x')->assertStatus(403);
        $this->login($c->email, 'Passw0rd!x')->assertStatus(403);
    }

    public function test_logout_ends_session(): void
    {
        $u = User::factory()->create();
        $this->loginAs($u);
        $this->api('POST', '/api/auth/logout')->assertOk()->assertJson(['success' => true]);
        $this->assertTrue(UserSession::where('user_id', $u->id)->first()->revoked);
        $this->api('GET', '/api/auth/me')->assertStatus(401);
    }

    public function test_refresh_is_ok_when_authenticated(): void
    {
        $this->loginAs(User::factory()->create());
        $this->api('POST', '/api/auth/refresh')->assertOk()->assertJson(['success' => true]);
    }

    public function test_change_password_rules_and_session_survives(): void
    {
        $u = User::factory()->create();
        $this->loginAs($u);
        $good = ['current_password' => 'Passw0rd!x', 'new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'];

        $this->api('POST', '/api/auth/change-password', ['new_password' => 'a', 'confirm_password' => 'b', 'current_password' => 'x'])->assertStatus(400);
        $this->api('POST', '/api/auth/change-password', ['current_password' => 'Passw0rd!x', 'new_password' => 'weakpass', 'confirm_password' => 'weakpass'])
            ->assertStatus(400)->assertJsonPath('detail', 'Password must contain at least 1 uppercase letter.');
        $this->api('POST', '/api/auth/change-password', ['current_password' => 'nope'] + $good)->assertStatus(401);
        $this->api('POST', '/api/auth/change-password', ['current_password' => 'Passw0rd!x', 'new_password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x'])
            ->assertStatus(400)->assertJsonPath('detail', 'New password must be different from the current password.');

        $this->api('POST', '/api/auth/change-password', $good)->assertOk();
        $this->api('GET', '/api/auth/me')->assertOk(); // current session survives
        $this->assertSame(1, $u->fresh()->token_version);
        $this->login($u->email, 'Passw0rd!x', 'other')->assertStatus(401);
        $this->login($u->email, 'NewPassw0rd!y', 'other')->assertOk();
    }

    public function test_change_password_invalidates_other_sessions(): void
    {
        $u = User::factory()->create();
        $this->loginAs($u, 'a');
        $this->loginAs($u, 'b');
        $this->api('POST', '/api/auth/change-password', ['current_password' => 'Passw0rd!x', 'new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'], 'a')->assertOk();
        $this->api('GET', '/api/auth/me', [], 'a')->assertOk();
        $this->api('GET', '/api/auth/me', [], 'b')->assertStatus(401);
    }

    public function test_forced_reset_flow_does_not_need_current_password(): void
    {
        $u = User::factory()->create(['account_status' => 'password_reset_required']);
        $this->loginAs($u)->assertJson(['password_reset_required' => true]);
        $this->api('GET', '/api/auth/me')->assertOk()->assertJson(['password_reset_required' => true]);
        $this->api('POST', '/api/auth/change-password', ['new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'])->assertOk();
        $this->assertSame('active', $u->fresh()->account_status);
    }

    public function test_idle_timeout_revokes_session(): void
    {
        $u = User::factory()->create();
        $this->loginAs($u);
        UserSession::where('user_id', $u->id)->update(['last_active_at' => now()->subMinutes(61)]);
        $this->api('GET', '/api/auth/me')->assertStatus(401);
        $this->assertSame('idle_timeout', UserSession::where('user_id', $u->id)->first()->revoke_reason);
    }

    public function test_sessions_listing_and_revocation(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $this->loginAs($u, 'a');
        $this->loginAs($u, 'b');
        $this->loginAs($other, 'c');

        $mine = $this->api('GET', '/api/sessions/mine', [], 'a')->assertOk()->json();
        $this->assertCount(2, $mine);
        $this->assertCount(1, array_filter($mine, fn ($s) => $s['is_current']));

        $bJti = UserSession::where('user_id', $u->id)->orderByDesc('id')->first()->jti;
        $cJti = UserSession::where('user_id', $other->id)->first()->jti;
        $this->api('POST', "/api/sessions/$cJti/revoke", [], 'a')->assertStatus(403);
        $this->api('POST', '/api/sessions/nonexistent/revoke', [], 'a')->assertStatus(404);
        $this->api('POST', "/api/sessions/$bJti/revoke", [], 'a')->assertOk();
        $this->api('GET', '/api/auth/me', [], 'b')->assertStatus(401);
        $this->api('GET', '/api/auth/me', [], 'a')->assertOk();
        $this->api('GET', '/api/sessions', [], 'a')->assertStatus(403);
    }

    public function test_admin_can_list_and_revoke_any_session(): void
    {
        $admin = User::factory()->admin()->create();
        $u = User::factory()->create();
        $this->loginAs($admin, 'adm');
        $this->loginAs($u, 'usr');
        $all = $this->api('GET', '/api/sessions', [], 'adm')->assertOk()->json();
        $this->assertCount(2, $all);
        $this->assertSame($u->email, collect($all)->firstWhere('user_id', (string) $u->id)['email']);
        $jti = UserSession::where('user_id', $u->id)->first()->jti;
        $this->api('POST', "/api/sessions/$jti/revoke", [], 'adm')->assertOk();
        $this->api('GET', '/api/auth/me', [], 'usr')->assertStatus(401);
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        $u = User::factory()->create();
        $this->loginAs($u, 'old');

        $this->api('POST', '/api/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->api('POST', '/api/auth/forgot-password', ['email' => strtoupper($u->email)])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 1);

        $msg = app('mailer')->getSymfonyTransport()->messages()->last();
        $body = quoted_printable_decode($msg->getOriginalMessage()->getBody()->bodyToString());
        $this->assertSame('Password Reset — Indian Rounds CRM', $msg->getOriginalMessage()->getSubject());
        $this->assertMatchesRegularExpression('#http://localhost:3000/reset-password/([A-Za-z0-9]+)#', $body);
        preg_match('#/reset-password/([A-Za-z0-9]+)#', $body, $m);
        $token = $m[1];

        $this->api('GET', '/api/auth/verify-reset-token/bogus')->assertStatus(404);
        $this->api('GET', "/api/auth/verify-reset-token/$token")->assertOk()->assertJson(['email' => $u->email, 'valid' => true]);

        $this->api('POST', '/api/auth/reset-password', ['token' => $token, 'new_password' => 'x', 'confirm_password' => 'y'])->assertStatus(400);
        $this->api('POST', '/api/auth/reset-password', ['token' => $token, 'new_password' => 'weak', 'confirm_password' => 'weak'])->assertStatus(400);
        $this->api('POST', '/api/auth/reset-password', ['token' => 'bogus', 'new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'])->assertStatus(404);
        $this->api('POST', '/api/auth/reset-password', ['token' => $token, 'new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'])->assertOk();

        // single use, old sessions revoked, new password works
        $this->api('GET', "/api/auth/verify-reset-token/$token")->assertStatus(410);
        $this->api('POST', '/api/auth/reset-password', ['token' => $token, 'new_password' => 'Another1Pass!', 'confirm_password' => 'Another1Pass!'])->assertStatus(410);
        $this->api('GET', '/api/auth/me', [], 'old')->assertStatus(401);
        $this->login($u->email, 'NewPassw0rd!y', 'new')->assertOk();
        $this->assertDatabaseHas('security_audit_log', ['event' => 'password_reset_completed']);
    }

    public function test_expired_reset_token_is_rejected_and_unlocks_account(): void
    {
        $u = User::factory()->create(['account_status' => 'locked', 'locked_until' => now()->addMinutes(10)]);
        $this->api('POST', '/api/auth/forgot-password', ['email' => $u->email])->assertOk();
        $body = quoted_printable_decode(app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getBody()->bodyToString());
        preg_match('#/reset-password/([A-Za-z0-9]+)#', $body, $m);

        \App\Models\PasswordResetToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->api('GET', "/api/auth/verify-reset-token/{$m[1]}")->assertStatus(410);

        \App\Models\PasswordResetToken::query()->update(['expires_at' => now()->addMinute()]);
        $this->api('POST', '/api/auth/reset-password', ['token' => $m[1], 'new_password' => 'NewPassw0rd!y', 'confirm_password' => 'NewPassw0rd!y'])->assertOk();
        $this->assertSame('active', $u->fresh()->account_status);
        $this->assertNull($u->fresh()->locked_until);
    }

    public function test_security_events_are_audited(): void
    {
        $u = User::factory()->create();
        $this->login($u->email, 'bad');
        $this->assertSame(1, SecurityAuditLog::where('event', 'login_failed')->count());
    }
}
