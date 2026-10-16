<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function make(string $role, string $name = 'X'): User
    {
        return User::factory()->create(['role' => $role, 'name' => $name]);
    }

    public function test_super_admin_is_the_top_role_with_every_permission_and_cannot_be_edited(): void
    {
        $this->assertTrue(Role::where('slug', 'super_admin')->where('is_system', true)->exists());
        $sa = $this->make('super_admin');
        $this->assertTrue($sa->isAdmin());
        $this->assertTrue($sa->isSuperAdmin());
        $this->assertFalse($this->make('admin')->isSuperAdmin());
        $this->assertSame(Permissions::keys(), $sa->permissionKeys());
        $this->assertTrue($sa->hasPermission('roles.manage'));
        $this->assertTrue($sa->hasRole('admin'));

        $this->as($sa);
        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $this->assertSame(['super_admin', 'admin'], array_slice(array_column($roles, 'slug'), 0, 2));      // listed first
        $this->assertTrue($roles[0]['all_permissions']);
        $id = $roles[0]['id'];
        $this->patchJson("/api/roles/$id", ['permissions' => ['leads.create']])->assertStatus(403);
        $this->deleteJson("/api/roles/$id")->assertStatus(403);
    }

    public function test_an_admin_cannot_create_promote_or_change_a_super_admin(): void
    {
        $admin = $this->as($this->make('admin'));
        $sa = $this->make('super_admin', 'Gopi');
        $sales = $this->make('sales');

        $this->postJson('/api/users', ['email' => 'n@x.test', 'password' => 'Str0ng!Passw0rd#1', 'name' => 'New', 'role' => 'super_admin'])->assertStatus(403);
        $this->patchJson("/api/users/{$sales->id}", ['role' => 'super_admin'])->assertStatus(403);
        $this->assertSame('sales', $sales->fresh()->role);

        $this->patchJson("/api/users/{$sa->id}", ['name' => 'Hacked'])->assertStatus(403);
        $this->patchJson("/api/users/{$sa->id}", ['role' => 'sales'])->assertStatus(403);
        $this->postJson("/api/users/{$sa->id}/status", ['account_status' => 'inactive'])->assertStatus(403);
        $this->postJson("/api/users/{$sa->id}/force-reset", [])->assertStatus(403);
        $this->postJson("/api/users/{$sa->id}/unlock", [])->assertStatus(403);
        $this->postJson("/api/users/{$sa->id}/logout", [])->assertStatus(403);
        $this->deleteJson("/api/users/{$sa->id}")->assertStatus(403);
        $this->assertSame('Gopi', $sa->fresh()->name);
        $this->assertSame('super_admin', $sa->fresh()->role);

        // everything else an admin did before still works
        $this->patchJson("/api/users/{$sales->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_a_super_admin_can_manage_everyone_including_other_super_admins(): void
    {
        $this->as($this->make('super_admin'));
        $admin = $this->make('admin', 'Vasanth');
        $other = $this->make('super_admin', 'Other');

        $this->patchJson("/api/users/{$admin->id}", ['role' => 'super_admin'])->assertOk();
        $this->assertSame('super_admin', $admin->fresh()->role);
        $this->postJson('/api/users', ['email' => 'n2@x.test', 'password' => 'Str0ng!Passw0rd#1', 'name' => 'New', 'role' => 'super_admin'])->assertOk();
        $this->patchJson("/api/users/{$other->id}", ['role' => 'admin'])->assertOk();                        // there are others left
        $this->assertSame('admin', $other->fresh()->role);
    }

    public function test_a_super_admin_cannot_demote_disable_or_delete_themselves_so_one_is_always_left(): void
    {
        $me = $this->as($this->make('super_admin', 'Gopi'));
        $this->patchJson("/api/users/{$me->id}", ['role' => 'admin'])->assertStatus(403);
        $this->postJson("/api/users/{$me->id}/status", ['account_status' => 'inactive'])->assertStatus(400);
        $this->deleteJson("/api/users/{$me->id}")->assertStatus(400);
        $this->assertSame('super_admin', $me->fresh()->role);
    }
}
