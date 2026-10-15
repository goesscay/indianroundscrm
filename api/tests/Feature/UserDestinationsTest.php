<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\TravelPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class UserDestinationsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    public function test_an_admin_can_give_a_user_several_destinations_reorder_and_clear_them(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');

        $created = $this->postJson('/api/users', [
            'email' => 'priya@indianrounds.test', 'password' => 'Str0ng@Pass1', 'name' => 'Priya', 'role' => 'sales', 'consultant_code' => 'PR',
            'destinations' => ['  Bali  ', 'Phu   Quoc', 'bali', '', 'Goa'],
        ])->assertOk()->json();
        $this->assertSame(['Bali', 'Phu Quoc', 'Goa'], $created['destinations']);              // tidied, no blanks, no repeats
        $this->assertSame('Bali', $created['default_destination']);                              // the first one is the primary

        $id = $created['id'];
        $this->patchJson("/api/users/$id", ['destinations' => ['Goa', 'Bali']])->assertOk()->assertJsonPath('default_destination', 'Goa');
        $this->assertSame(['Goa', 'Bali'], User::find($id)->destinations);

        $this->patchJson("/api/users/$id", ['name' => 'Priya N'])->assertOk();                   // other edits leave them alone
        $this->assertSame(['Goa', 'Bali'], User::find($id)->destinations);

        $this->patchJson("/api/users/$id", ['default_destination' => 'Kerala'])->assertOk();     // the older single field still works
        $this->assertSame(['Kerala'], User::find($id)->destinations);

        $this->patchJson("/api/users/$id", ['destinations' => []])->assertOk();                  // an empty list clears them
        $this->assertSame([], User::find($id)->destinations);
        $this->assertNull(User::find($id)->default_destination);
    }

    public function test_only_user_managers_can_set_destinations(): void
    {
        $sales = User::factory()->sales()->create(['destinations' => ['Goa']]);
        $other = User::factory()->sales()->create();
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $this->patchJson("/api/users/{$other->id}", ['destinations' => ['Bali']])->assertStatus(403);
        $this->patchJson("/api/users/{$sales->id}", ['destinations' => ['Bali']])->assertStatus(403);   // their own are set by the admin
        $this->assertSame(['Goa'], $sales->fresh()->destinations);
    }

    public function test_the_destinations_come_with_the_user_on_sign_in_and_on_every_load(): void
    {
        $u = User::factory()->sales()->create(['destinations' => ['Bali', 'Goa']]);
        $this->loginAs($u, 'dd')->assertJsonPath('destinations', ['Bali', 'Goa'])->assertJsonPath('default_destination', 'Bali');
        $this->api('GET', '/api/auth/me', [], 'dd')->assertOk()->assertJsonPath('destinations', ['Bali', 'Goa'])->assertJsonPath('default_destination', 'Bali');
    }

    public function test_the_leads_and_packages_lists_can_be_filtered_to_several_destinations_at_once(): void
    {
        foreach ([['IRDS00001', 'Goa'], ['IRDS00002', 'Bali'], ['IRDS00003', 'Kerala'], ['IRDS00004', 'Goa']] as [$id, $dest]) {
            Lead::create(['lead_id' => $id, 'customer_name' => "C $id", 'phone' => '9'.random_int(100000000, 999999999), 'destination' => $dest]);
        }
        foreach (['Goa', 'Bali', 'Kerala'] as $i => $dest) {
            TravelPackage::create(['name' => "Pkg $dest", 'code' => "P$i", 'destination' => $dest, 'category' => 'family', 'active' => true]);
        }
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $ids = fn (string $qs) => collect($this->getJson('/api/leads?'.$qs)->assertOk()->json('items') ?? $this->getJson('/api/leads?'.$qs)->json())->pluck('lead_id')->sort()->values()->all();

        $this->assertSame(['IRDS00001', 'IRDS00004'], $ids('destination=Goa'));
        $this->assertSame(['IRDS00001', 'IRDS00002', 'IRDS00004'], $ids('destination='.rawurlencode('Goa|Bali')));
        $this->assertCount(4, $ids(''));
        $c = $this->getJson('/api/leads/stage-counts?destination='.rawurlencode('Goa|Kerala'))->assertOk()->json();
        $this->assertSame(3, $c['stages']['all']);                                               // the tab counts follow it too

        $res = $this->getJson('/api/packages?destination='.rawurlencode('Bali|Kerala'))->assertOk()->json();
        $pk = collect($res['items'] ?? $res)->pluck('destination')->sort()->values()->all();
        $this->assertSame(['Bali', 'Kerala'], $pk);
    }
}
