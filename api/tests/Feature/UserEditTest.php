<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class UserEditTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    public function test_admin_can_edit_name_email_mobile_codes_and_role(): void
    {
        $admin = User::factory()->admin()->create();
        $this->loginAs($admin, 'a');
        $u = User::factory()->create(['role' => 'sales', 'email' => 'old@example.com', 'name' => 'Old Name']);
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $r = $this->api('PATCH', "/api/users/{$u->id}", ['name' => 'New Name', 'email' => ' New@Example.com ', 'mobile' => '9840011111', 'staff_code' => 'SAL009', 'consultant_code' => 'nm', 'role' => 'operations'], 'a')->assertOk();
        $this->assertSame('New Name', $r->json('name'));
        $this->assertSame('new@example.com', $r->json('email'));       // trimmed and lower-cased
        $this->assertSame('9840011111', $r->json('mobile'));
        $this->assertSame('NM', $r->json('consultant_code'));
        $this->assertSame('operations', $r->json('role'));

        $this->api('PATCH', "/api/users/{$u->id}", ['email' => 'taken@example.com'], 'a')->assertStatus(400);   // another user has it
        $this->api('PATCH', "/api/users/{$u->id}", ['email' => 'not-an-email'], 'a')->assertStatus(422);
        $this->api('PATCH', "/api/users/{$u->id}", ['email' => 'new@example.com', 'name' => 'Same Email Fine'], 'a')->assertOk(); // own address is not a clash
        $this->assertNotNull($other);

        // the admin may edit their own details but not demote themselves
        $this->api('PATCH', "/api/users/{$admin->id}", ['name' => 'Renamed Admin'], 'a')->assertOk()->assertJsonPath('name', 'Renamed Admin');
        $this->api('PATCH', "/api/users/{$admin->id}", ['role' => 'sales'], 'a')->assertStatus(403);
    }

    public function test_only_users_manage_can_edit(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $this->loginAs($sales, 's');
        $v = User::factory()->create();
        $this->api('PATCH', "/api/users/{$v->id}", ['name' => 'Hacked'], 's')->assertStatus(403);
        $this->assertNotSame('Hacked', $v->fresh()->name);
    }
}
