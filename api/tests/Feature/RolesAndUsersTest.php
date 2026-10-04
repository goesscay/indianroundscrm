<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class RolesAndUsersTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function admin(): User
    {
        $a = User::factory()->admin()->create();
        $this->loginAs($a);

        return $a;
    }

    public function test_role_middleware_matrix(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'role:admin,ops'])->get('/api/_t/role', fn () => ['ok' => true]);

        $this->api('GET', '/api/_t/role')->assertStatus(401);

        $this->loginAs(User::factory()->sales()->create(), 'sales');
        $this->api('GET', '/api/_t/role', [], 'sales')->assertStatus(403)->assertJson(['detail' => 'Forbidden: insufficient role']);

        $this->loginAs(User::factory()->operations()->create(), 'ops');
        $this->api('GET', '/api/_t/role', [], 'ops')->assertOk(); // 'ops' alias == operations

        $this->loginAs(User::factory()->admin()->create(), 'adm');
        $this->api('GET', '/api/_t/role', [], 'adm')->assertOk();
    }

    public function test_admin_only_endpoints_reject_sales_and_ops(): void
    {
        $this->loginAs(User::factory()->sales()->create(), 'sales');
        $this->loginAs(User::factory()->operations()->create(), 'ops');
        foreach (['sales', 'ops'] as $b) {
            $this->api('GET', '/api/users', [], $b)->assertStatus(403);
            $this->api('GET', '/api/security-audit-log', [], $b)->assertStatus(403);
            $this->api('POST', '/api/master', ['category' => 'x', 'value' => 'y'], $b)->assertStatus(403);
            $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => 1], $b)->assertStatus(403);
        }
        // supplier create: admin + operations only
        $s = ['name' => 'S', 'email' => 's@x.com'];
        $this->api('POST', '/api/suppliers', $s, 'sales')->assertStatus(403);
        $this->api('POST', '/api/suppliers', $s, 'ops')->assertOk();
        // any authenticated user may read master data
        $this->api('GET', '/api/master', [], 'sales')->assertOk();
    }

    public function test_users_crud(): void
    {
        $admin = $this->admin();
        $payload = ['email' => 'New.User@Example.com', 'password' => 'Str0ng!Pass', 'name' => 'New User', 'role' => 'sales', 'staff_code' => 'S1', 'consultant_code' => 'abc'];

        $created = $this->api('POST', '/api/users', $payload)->assertOk()->json();
        $this->assertSame('new.user@example.com', $created['email']);
        $this->assertSame('AB', $created['consultant_code']);
        $this->assertIsString($created['id']);
        $this->assertArrayNotHasKey('password', $created);
        $this->assertSame('active', $created['account_status']);

        $this->api('POST', '/api/users', $payload)->assertStatus(400)->assertJson(['detail' => 'Email already exists']);
        $this->api('POST', '/api/users', ['email' => 'c@d.com', 'role' => 'boss'] + $payload)->assertStatus(400)->assertJson(['detail' => 'Invalid role']);
        $this->api('POST', '/api/users', ['email' => 'e@f.com', 'password' => 'weak'] + $payload)->assertStatus(400);
        $this->api('POST', '/api/users', ['email' => 'not-an-email'] + $payload)->assertStatus(422)->assertJsonStructure(['detail']);

        $list = $this->api('GET', '/api/users')->assertOk()->json();
        $this->assertCount(2, $list);

        $this->api('PATCH', "/api/users/{$created['id']}", ['name' => 'Renamed', 'active' => false])
            ->assertOk()->assertJson(['name' => 'Renamed', 'active' => false]);
        $this->api('PATCH', "/api/users/{$created['id']}", [])->assertStatus(400)->assertJson(['detail' => 'No fields to update']);
        $this->api('PATCH', '/api/users/99999', ['name' => 'x'])->assertStatus(404);
        $this->api('PATCH', '/api/users/abc', ['name' => 'x'])->assertStatus(400);
        $this->api('PATCH', "/api/users/{$created['id']}", ['password' => 'weak'])->assertStatus(400);
        $this->api('PATCH', "/api/users/{$created['id']}", ['password' => 'Brand1New!pw'])->assertOk();
        $this->assertSame(1, User::find($created['id'])->token_version);

        $this->api('DELETE', "/api/users/{$admin->id}")->assertStatus(400)->assertJson(['detail' => 'Cannot delete yourself']);
        $this->api('DELETE', "/api/users/{$created['id']}")->assertOk()->assertJson(['success' => true]);
        $this->api('DELETE', "/api/users/{$created['id']}")->assertStatus(404);
        $this->assertDatabaseHas('activity_log', ['action' => 'create_user']);
        $this->assertDatabaseHas('activity_log', ['action' => 'delete_user']);
    }

    public function test_update_user_password_does_not_leak_into_activity_log(): void
    {
        $this->admin();
        $u = User::factory()->create();
        $this->api('PATCH', "/api/users/{$u->id}", ['password' => 'Brand1New!pw'])->assertOk();
        $row = \App\Models\ActivityLog::where('action', 'update_user')->first();
        $this->assertArrayNotHasKey('password', $row->new_value);
        $this->assertTrue($row->new_value['password_changed']);
    }

    public function test_admin_force_reset_unlock_status_and_logout(): void
    {
        $this->admin();
        $u = User::factory()->create();
        $this->loginAs($u, 'usr');

        // kick all
        $this->api('POST', "/api/users/{$u->id}/logout")->assertOk();
        $this->api('GET', '/api/auth/me', [], 'usr')->assertStatus(401);
        $this->assertTrue(UserSession::where('user_id', $u->id)->first()->revoked);

        // force reset
        $this->api('POST', "/api/users/{$u->id}/force-reset", ['reason' => 'audit'])->assertOk();
        $this->assertSame('password_reset_required', $u->fresh()->account_status);
        $this->login($u->email, 'Passw0rd!x', 'usr2')->assertOk()->assertJson(['password_reset_required' => true]);

        // lock then unlock
        $u->update(['account_status' => 'locked', 'locked_until' => now()->addMinutes(5), 'failed_login_attempts' => 5]);
        $this->api('POST', "/api/users/{$u->id}/unlock")->assertOk();
        $this->assertSame('active', $u->fresh()->account_status);
        $this->assertNull($u->fresh()->locked_until);

        // status
        $this->api('POST', "/api/users/{$u->id}/status", ['account_status' => 'bogus'])->assertStatus(400);
        $this->api('POST', "/api/users/{$u->id}/status", ['account_status' => 'suspended'])->assertOk();
        $this->login($u->email, 'Passw0rd!x', 'usr3')->assertStatus(403);
        $this->api('POST', "/api/users/{$u->id}/status", ['account_status' => 'active'])->assertOk();
        $this->login($u->email, 'Passw0rd!x', 'usr3')->assertOk();

        $me = User::where('role', 'admin')->first();
        $this->api('POST', "/api/users/{$me->id}/status", ['account_status' => 'inactive'])->assertStatus(400);
        $this->api('POST', '/api/users/99999/unlock')->assertStatus(404);
    }

    public function test_deactivated_user_session_is_cut_immediately(): void
    {
        $this->admin();
        $u = User::factory()->create();
        $this->loginAs($u, 'usr');
        $this->api('POST', "/api/users/{$u->id}/status", ['account_status' => 'inactive'])->assertOk();
        $this->api('GET', '/api/auth/me', [], 'usr')->assertStatus(401);
    }

    public function test_security_audit_log_endpoint_filters(): void
    {
        $this->admin();
        $u = User::factory()->create();
        $this->login($u->email, 'bad', 'x');
        $this->login($u->email, 'bad', 'x');
        $rows = $this->api('GET', '/api/security-audit-log?event=login_failed')->assertOk()->json();
        $this->assertCount(2, $rows);
        $this->assertSame('login_failed', $rows[0]['event']);
        $this->assertSame(['attempts' => 2], $rows[0]['extra']);
        $this->assertCount(2, $this->api('GET', '/api/security-audit-log?user_email='.$u->email)->json());
        $this->assertCount(1, $this->api('GET', '/api/security-audit-log?limit=1')->json());
    }
}
