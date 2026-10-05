<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function lead(string $phone, ?User $owner = null): string
    {
        $this->loginAs($adm = User::factory()->admin()->create(['consultant_code' => 'AD']), 'seed');
        $r = $this->api('POST', '/api/leads', [
            'customer_name' => 'Cust', 'phone' => $phone, 'destination' => 'Goa',
        ], 'seed')->assertOk();
        $id = $r->json('lead_id');
        if ($owner) {
            Lead::where('lead_id', $id)->update(['staff_id' => $owner->id]);
        }

        return $id;
    }

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    public function test_default_sets_match_legacy_behaviour(): void
    {
        $sales = $this->as('sales', 'sales', ['consultant_code' => 'SA']);
        $ops = $this->as('operations', 'ops', ['consultant_code' => 'OP']);
        $this->as('admin', 'adm', ['consultant_code' => 'AM']);

        $mine = $this->lead('9876543210', $sales);
        $theirs = $this->lead('9876543211');

        // visibility
        $this->assertCount(1, $this->api('GET', '/api/leads', [], 'sales')->assertOk()->json());
        $this->assertGreaterThanOrEqual(2, count($this->api('GET', '/api/leads', [], 'ops')->json()));
        $this->api('GET', "/api/leads/$theirs", [], 'sales')->assertStatus(403);
        $this->api('GET', "/api/leads/$mine", [], 'sales')->assertOk();

        // create
        foreach (['sales' => '9876543212', 'ops' => '9876543213', 'adm' => '9876543214'] as $b => $ph) {
            $this->api('POST', '/api/leads', ['customer_name' => 'N', 'phone' => $ph, 'destination' => 'Goa'], $b)->assertOk();
        }

        // limited vs full patch
        $res = $this->api('PATCH', "/api/leads/$mine", ['supplier_quote_amount' => 999, 'remarks' => 'ok'], 'sales')->assertOk();
        $this->assertEquals(0, $res->json('supplier_quote_amount') ?? 0);
        $this->assertSame('ok', $res->json('remarks'));
        $this->assertEquals(500, $this->api('PATCH', "/api/leads/$mine", ['supplier_quote_amount' => 500], 'ops')->assertOk()->json('supplier_quote_amount'));

        // delete / reassign
        $this->api('DELETE', "/api/leads/$mine", [], 'sales')->assertStatus(403);
        $this->api('DELETE', "/api/leads/$mine", [], 'ops')->assertStatus(403);
        $this->api('POST', "/api/leads/$theirs/reassign", ['consultant_id' => (string) $sales->id], 'sales')->assertStatus(403);
        $this->api('POST', "/api/leads/$theirs/reassign", ['consultant_id' => (string) $sales->id], 'ops')->assertOk();
        $this->api('POST', "/api/leads/$theirs/reassign", ['consultant_id' => (string) $ops->id], 'ops')->assertStatus(400); // ops not assignable
        $this->api('DELETE', "/api/leads/$mine", [], 'adm')->assertOk();

        // RSQ / suppliers / supplier dashboard
        $this->api('POST', '/api/rsq-requests', ['lead_id' => $theirs], 'sales')->assertOk(); // reassigned to sales above
        $this->api('GET', '/api/rsq-requests', [], 'sales')->assertStatus(403);
        $this->api('GET', '/api/rsq-requests', [], 'ops')->assertOk();
        $this->api('GET', '/api/lead-suppliers/dashboard/summary', [], 'sales')->assertStatus(403);
        $this->api('GET', '/api/lead-suppliers/dashboard/summary', [], 'ops')->assertOk();
        $this->api('GET', '/api/suppliers', [], 'sales')->assertOk();
        $s = ['name' => 'S', 'email' => 's@x.com'];
        $this->api('POST', '/api/suppliers', $s, 'sales')->assertStatus(403);
        $this->api('POST', '/api/suppliers', $s, 'ops')->assertOk();

        // users / master / activity / security / allocations / chat groups / leaderboard
        foreach (['/api/users', '/api/activity', '/api/security-audit-log', '/api/dashboard/leaderboard', '/api/sessions'] as $u) {
            $this->api('GET', $u, [], 'sales')->assertStatus(403);
            $this->api('GET', $u, [], 'ops')->assertStatus(403);
            $this->api('GET', $u, [], 'adm')->assertOk();
        }
        $this->api('POST', '/api/master', ['category' => 'x', 'value' => 'y'], 'ops')->assertStatus(403);
        $this->api('POST', '/api/master', ['category' => 'x', 'value' => 'y'], 'adm')->assertOk();
        $this->api('GET', '/api/master', [], 'sales')->assertOk();
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => (string) $sales->id], 'ops')->assertStatus(403);
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => (string) $sales->id], 'adm')->assertOk();
        foreach (['sales', 'ops'] as $b) {
            $this->api('POST', '/api/chat/groups', ['name' => 'G', 'member_ids' => [(string) $sales->id]], $b)->assertStatus(403);
            $this->api('GET', '/api/chat/users', [], $b)->assertOk();
        }
        $this->api('POST', '/api/chat/groups', ['name' => 'G', 'member_ids' => [(string) $sales->id]], 'adm')->assertOk();
        // roles API needs roles.manage or users.manage
        $this->api('GET', '/api/roles', [], 'sales')->assertStatus(403);
        $this->api('GET', '/api/permissions', [], 'ops')->assertStatus(403);
    }

    public function test_me_and_login_expose_permissions(): void
    {
        $sales = User::factory()->sales()->create();
        $login = $this->loginAs($sales)->json();
        $this->assertSame('Sales', $login['role_name']);
        $this->assertEqualsCanonicalizing(Permissions::defaults('sales'), $login['permissions']);
        $me = $this->api('GET', '/api/auth/me')->assertOk()->json();
        $this->assertSame($login['permissions'], $me['permissions']);
        $this->assertSame('Sales', $me['role_name']);
        $this->assertArrayNotHasKey('leads.view_all', array_flip($me['permissions']));

        $this->loginAs(User::factory()->admin()->create(), 'adm');
        $this->assertEqualsCanonicalizing(Permissions::keys(), $this->api('GET', '/api/auth/me', [], 'adm')->json('permissions'));
    }

    public function test_catalog_and_role_listing_shapes(): void
    {
        $this->as('admin', 'adm');
        $cat = $this->api('GET', '/api/permissions', [], 'adm')->assertOk()->json();
        $this->assertSame('leads', collect($cat)->firstWhere('key', 'leads')['key']);
        $this->assertSame(['key', 'label', 'items'], array_keys($cat[0]));
        $this->assertSame(['key', 'label', 'description'], array_keys($cat[0]['items'][0]));

        $roles = collect($this->api('GET', '/api/roles', [], 'adm')->assertOk()->json());
        $admin = $roles->firstWhere('slug', 'admin');
        $this->assertTrue($admin['all_permissions']);
        $this->assertEqualsCanonicalizing(Permissions::keys(), $admin['permissions']);
        $this->assertTrue($admin['is_system']);
        $this->assertSame(1, $admin['user_count']);
        $ops = $roles->firstWhere('slug', 'operations');
        $this->assertFalse($ops['all_permissions']);
        $this->assertEqualsCanonicalizing(Permissions::defaults('operations'), $ops['permissions']);
        $this->assertIsString($ops['id']);
    }

    public function test_custom_role_grants_and_denies_and_edits_apply_immediately(): void
    {
        $this->as('admin', 'adm');
        $role = $this->api('POST', '/api/roles', [
            'name' => 'Accounts Team', 'description' => 'Finance', 'permissions' => ['activity.view', 'master.view'],
        ], 'adm')->assertOk()->json();
        $this->assertSame('accounts-team', $role['slug']);
        $this->assertFalse($role['is_system']);
        $this->assertSame(0, $role['user_count']);

        $this->as('accounts-team', 'acc');
        $this->api('GET', '/api/activity', [], 'acc')->assertOk();
        $this->api('GET', '/api/security-audit-log', [], 'acc')->assertStatus(403)->assertJsonStructure(['detail']);
        $this->api('GET', '/api/users', [], 'acc')->assertStatus(403);
        $this->api('GET', '/api/leads', [], 'acc')->assertOk(); // scoped to own (none)
        $this->api('POST', '/api/leads', ['customer_name' => 'N', 'phone' => '9876543210', 'destination' => 'Goa'], 'acc')->assertStatus(403);
        $this->assertSame('Accounts Team', $this->api('GET', '/api/auth/me', [], 'acc')->json('role_name'));

        $this->api('PATCH', "/api/roles/{$role['id']}", ['permissions' => ['leads.create', 'security.view']], 'adm')->assertOk();
        $this->api('GET', '/api/activity', [], 'acc')->assertStatus(403);
        $this->api('GET', '/api/security-audit-log', [], 'acc')->assertOk();
        $this->api('POST', '/api/leads', ['customer_name' => 'N', 'phone' => '9876543210', 'destination' => 'Goa'], 'acc')->assertOk();
        $this->assertEqualsCanonicalizing(['leads.create', 'security.view'], $this->api('GET', '/api/auth/me', [], 'acc')->json('permissions'));

        $this->assertDatabaseHas('activity_log', ['action' => 'create_role', 'entity' => 'role']);
        $this->assertDatabaseHas('activity_log', ['action' => 'update_role', 'entity' => 'role']);

        $this->api('GET', "/api/roles/{$role['id']}/users", [], 'adm')->assertOk()->assertJsonCount(1)
            ->assertJsonStructure([['id', 'name', 'email', 'active']]);
    }

    public function test_limited_edit_vs_full_edit_for_custom_roles(): void
    {
        $this->as('admin', 'adm');
        $r = $this->api('POST', '/api/roles', ['name' => 'Editors', 'permissions' => ['leads.edit', 'leads.view_all']], 'adm')->json();
        $this->as('editors', 'ed');
        $id = $this->lead('9876543210');
        $this->api('PATCH', "/api/leads/$id", ['supplier_quote_amount' => 700], 'ed')->assertOk();
        $this->assertEquals(700, $this->api('GET', "/api/leads/$id", [], 'ed')->json('supplier_quote_amount'));

        $this->api('PATCH', "/api/roles/{$r['id']}", ['permissions' => ['leads.edit_limited', 'leads.view_all']], 'adm')->assertOk();
        $res = $this->api('PATCH', "/api/leads/$id", ['supplier_quote_amount' => 1, 'remarks' => 'r'], 'ed')->assertOk();
        $this->assertEquals(700, $res->json('supplier_quote_amount'));
        $this->assertSame('r', $res->json('remarks'));

        $this->api('PATCH', "/api/roles/{$r['id']}", ['permissions' => ['leads.view_all']], 'adm')->assertOk();
        $this->api('PATCH', "/api/leads/$id", ['remarks' => 'x'], 'ed')->assertStatus(403);
    }

    public function test_view_all_permission_controls_lead_visibility(): void
    {
        $this->as('admin', 'adm');
        $r = $this->api('POST', '/api/roles', ['name' => 'Viewer', 'permissions' => []], 'adm')->json();
        $this->as('viewer', 'v');
        $id = $this->lead('9876543210');
        $this->api('GET', "/api/leads/$id", [], 'v')->assertStatus(403);
        $this->api('PATCH', "/api/roles/{$r['id']}", ['permissions' => ['leads.view_all']], 'adm')->assertOk();
        $this->api('GET', "/api/leads/$id", [], 'v')->assertOk();
    }

    public function test_admin_role_is_immutable(): void
    {
        $this->as('admin', 'adm');
        $admin = Role::where('slug', 'admin')->first();
        $this->api('PATCH', "/api/roles/{$admin->id}", ['permissions' => []], 'adm')->assertStatus(403)->assertJsonStructure(['detail']);
        $this->api('PATCH', "/api/roles/{$admin->id}", ['name' => 'Boss'], 'adm')->assertStatus(403);
        $this->api('DELETE', "/api/roles/{$admin->id}", [], 'adm')->assertStatus(403);
    }

    public function test_system_roles_only_permissions_editable_and_undeletable(): void
    {
        $this->as('admin', 'adm');
        $sales = Role::where('slug', 'sales')->first();
        $this->api('PATCH', "/api/roles/{$sales->id}", ['name' => 'Renamed'], 'adm')->assertStatus(403);
        $this->api('DELETE', "/api/roles/{$sales->id}", [], 'adm')->assertStatus(403);
        $r = $this->api('PATCH', "/api/roles/{$sales->id}", ['name' => 'Sales', 'permissions' => ['leads.create']], 'adm')->assertOk()->json();
        $this->assertSame(['leads.create'], $r['permissions']);
        $this->assertSame('sales', $r['slug']);
    }

    public function test_validation_and_delete_rules(): void
    {
        $this->as('admin', 'adm');
        $this->api('POST', '/api/roles', ['name' => 'X', 'permissions' => ['nope.nothing']], 'adm')->assertStatus(422);
        $this->api('POST', '/api/roles', ['permissions' => []], 'adm')->assertStatus(422);
        $this->api('POST', '/api/roles', ['name' => 'Sales'], 'adm')->assertStatus(422); // duplicate of system role
        $r = $this->api('POST', '/api/roles', ['name' => 'Temp Role', 'permissions' => ['leads.create']], 'adm')->assertOk()->json();
        $this->api('POST', '/api/roles', ['name' => 'temp role'], 'adm')->assertStatus(422);
        $this->api('PATCH', "/api/roles/{$r['id']}", ['permissions' => ['bogus']], 'adm')->assertStatus(422);
        $renamed = $this->api('PATCH', "/api/roles/{$r['id']}", ['name' => 'Temp Renamed'], 'adm')->assertOk()->json();
        $this->assertSame('temp-role', $renamed['slug']); // slug immutable

        $u = User::factory()->create(['role' => 'temp-role']);
        $this->api('DELETE', "/api/roles/{$r['id']}", [], 'adm')->assertStatus(409)->assertJsonStructure(['detail']);
        $u->delete();
        $this->api('DELETE', "/api/roles/{$r['id']}", [], 'adm')->assertOk()->assertExactJson(['ok' => true]);
        $this->assertDatabaseMissing('roles', ['id' => $r['id']]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $r['id']]);
        $this->assertDatabaseHas('activity_log', ['action' => 'delete_role', 'entity' => 'role']);
    }

    public function test_user_role_assignment_and_safety_rules(): void
    {
        $adm = $this->as('admin', 'adm');
        $this->api('POST', '/api/roles', ['name' => 'Helpers', 'permissions' => []], 'adm')->assertOk();
        $payload = ['email' => 'h@example.com', 'password' => 'Str0ng!Pass', 'name' => 'H', 'role' => 'helpers'];
        $created = $this->api('POST', '/api/users', $payload, 'adm')->assertOk()->json();
        $this->assertSame('Helpers', $created['role_name']);
        $this->api('POST', '/api/users', ['email' => 'i@example.com', 'role' => 'ghost'] + $payload, 'adm')->assertStatus(400);

        // role change is logged
        $this->api('PATCH', "/api/users/{$created['id']}", ['role' => 'sales'], 'adm')->assertOk()->assertJson(['role' => 'sales', 'role_name' => 'Sales']);
        $this->assertDatabaseHas('activity_log', ['action' => 'change_user_role', 'entity' => 'user']);
        $this->api('PATCH', "/api/users/{$created['id']}", ['role' => 'ghost'], 'adm')->assertStatus(400);

        // cannot change own role / last admin protections
        $this->api('PATCH', "/api/users/{$adm->id}", ['role' => 'sales'], 'adm')->assertStatus(403);
        $this->api('PATCH', "/api/users/{$adm->id}", ['active' => false], 'adm')->assertStatus(409);
        $this->api('DELETE', "/api/users/{$adm->id}", [], 'adm')->assertStatus(400); // existing self-delete rule

        // a second admin can be demoted by the first, but not the last one (by another admin-capable user)
        $second = User::factory()->admin()->create();
        $this->api('PATCH', "/api/users/{$second->id}", ['role' => 'sales'], 'adm')->assertOk();
        $custom = $this->api('POST', '/api/roles', ['name' => 'User Admins', 'permissions' => ['users.manage']], 'adm')->json();
        $this->as('user-admins', 'ua');
        $this->api('PATCH', "/api/users/{$adm->id}", ['role' => 'sales'], 'ua')->assertStatus(409);
        $this->api('DELETE', "/api/users/{$adm->id}", [], 'ua')->assertStatus(409);
        $this->api('POST', "/api/users/{$adm->id}/status", ['account_status' => 'suspended'], 'ua')->assertStatus(409);
        $this->api('GET', '/api/users', [], 'ua')->assertOk();
        $this->api('GET', '/api/roles', [], 'ua')->assertOk();
        $this->api('POST', '/api/roles', ['name' => 'Z'], 'ua')->assertStatus(403); // needs roles.manage
    }

    public function test_unknown_permission_keys_and_unknown_roles_are_denied(): void
    {
        $u = User::factory()->create(['role' => 'mystery']);
        $this->assertFalse($u->hasPermission('leads.create'));
        $this->assertFalse(User::factory()->admin()->make()->hasPermission('not.a.key'));
        $this->assertTrue(User::factory()->admin()->make()->hasPermission('roles.manage'));
        $this->loginAs($u);
        $this->api('GET', '/api/auth/me')->assertOk()->assertJson(['permissions' => []]);
        $this->api('GET', '/api/dashboard/stats')->assertStatus(403);
    }
}
