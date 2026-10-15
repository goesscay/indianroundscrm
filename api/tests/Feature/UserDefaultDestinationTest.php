<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class UserDefaultDestinationTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    public function test_an_admin_can_give_a_user_a_default_destination_change_it_and_clear_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');

        $created = $this->postJson('/api/users', [
            'email' => 'priya@indianrounds.test', 'password' => 'Str0ng@Pass1', 'name' => 'Priya', 'role' => 'sales', 'consultant_code' => 'PR',
            'default_destination' => '  Bali  ',
        ])->assertOk()->assertJsonPath('default_destination', 'Bali')->json();

        $id = $created['id'];
        $this->patchJson("/api/users/$id", ['default_destination' => 'Phu   Quoc'])->assertOk();
        $this->assertSame('Phu Quoc', User::find($id)->default_destination);                    // tidied

        $this->patchJson("/api/users/$id", ['name' => 'Priya N'])->assertOk();                   // other edits leave it alone
        $this->assertSame('Phu Quoc', User::find($id)->default_destination);

        $this->patchJson("/api/users/$id", ['default_destination' => null])->assertOk();         // an empty value clears it
        $this->assertNull(User::find($id)->default_destination);
        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['default_destination' => null]);
    }

    public function test_only_user_managers_can_set_a_default_destination(): void
    {
        $sales = User::factory()->sales()->create(['default_destination' => 'Goa']);
        $other = User::factory()->sales()->create();
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $this->patchJson("/api/users/{$other->id}", ['default_destination' => 'Bali'])->assertStatus(403);
        $this->patchJson("/api/users/{$sales->id}", ['default_destination' => 'Bali'])->assertStatus(403);   // their own default is set by the admin
        $this->assertSame('Goa', $sales->fresh()->default_destination);
    }

    public function test_the_default_destination_comes_with_the_user_on_sign_in_and_on_every_load(): void
    {
        $u = User::factory()->sales()->create(['default_destination' => 'Bali']);
        $this->loginAs($u, 'dd')->assertJsonPath('default_destination', 'Bali');      // the sign-in response: so the default applies without a reload
        $this->api('GET', '/api/auth/me', [], 'dd')->assertOk()->assertJsonPath('default_destination', 'Bali');
    }
}
