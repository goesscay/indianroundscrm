<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function admin(): User
    {
        $a = User::factory()->admin()->create();
        $this->loginAs($a);

        return $a;
    }

    public function test_master_crud_and_idempotent_add(): void
    {
        $this->admin();
        $a = $this->api('POST', '/api/master', ['category' => 'destination', 'value' => 'Bali'])->assertOk()->json();
        $b = $this->api('POST', '/api/master', ['category' => 'destination', 'value' => 'Bali'])->assertOk()->json();
        $this->assertSame($a['id'], $b['id']);
        $this->api('POST', '/api/master', ['category' => 'meal_plan', 'value' => 'CP']);

        $this->assertCount(2, $this->api('GET', '/api/master')->json());
        $only = $this->api('GET', '/api/master?category=meal_plan')->json();
        $this->assertCount(1, $only);
        $this->assertSame('CP', $only[0]['value']);

        $this->api('POST', '/api/master', ['category' => 'x'])->assertStatus(422);
        $this->api('DELETE', "/api/master/{$a['id']}")->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $this->api('GET', '/api/master')->json());
    }

    public function test_destination_cities(): void
    {
        $this->admin();
        $this->api('GET', '/api/destination-cities')->assertOk()->assertExactJson([]);
        $this->api('PUT', '/api/destination-cities/Goa', ['cities' => [' Panaji ', '', 'Baga']])
            ->assertOk()->assertExactJson(['destination' => 'Goa', 'cities' => ['Panaji', 'Baga']]);
        $this->api('PUT', '/api/destination-cities/Bali', ['cities' => ['Ubud']])->assertOk();
        $this->api('GET', '/api/destination-cities')->assertExactJson(['Bali' => ['Ubud'], 'Goa' => ['Panaji', 'Baga']]);
        $this->api('GET', '/api/destination-cities/Goa')->assertExactJson(['destination' => 'Goa', 'cities' => ['Panaji', 'Baga']]);
        $this->api('GET', '/api/destination-cities/Nowhere')->assertExactJson(['destination' => 'Nowhere', 'cities' => []]);
        $this->api('PUT', '/api/destination-cities/Goa', ['cities' => ['Anjuna']]);
        $this->api('GET', '/api/destination-cities/Goa')->assertJsonPath('cities', ['Anjuna']);
        $this->api('PUT', '/api/destination-cities/Goa', [])->assertStatus(422);
    }

    public function test_allocations(): void
    {
        $this->admin();
        $sales = User::factory()->sales()->create(['name' => 'Sam', 'consultant_code' => 'SM']);
        $ops = User::factory()->operations()->create();

        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => (string) $ops->id])->assertStatus(400);
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => '9999'])->assertStatus(404);
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => 'zzz'])->assertStatus(400);
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => (string) $sales->id])->assertOk()->assertJson(['success' => true]);
        $this->api('POST', '/api/allocations', ['destination' => 'Goa', 'consultant_id' => (string) $sales->id])->assertOk(); // upsert

        $list = $this->api('GET', '/api/allocations')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame(['destination' => 'Goa', 'consultant_id' => (string) $sales->id, 'consultant_name' => 'Sam', 'consultant_code' => 'SM'],
            collect($list[0])->except('id')->all());
        $this->assertIsString($list[0]['id']);

        $this->api('DELETE', "/api/allocations/{$list[0]['id']}")->assertOk();
        $this->assertCount(0, $this->api('GET', '/api/allocations')->json());
    }

    public function test_suppliers(): void
    {
        $this->admin();
        $s = $this->api('POST', '/api/suppliers', ['name' => 'Zed', 'email' => 'ZED@Tours.com', 'phone' => '+91 1', 'destination' => 'Bali', 'country' => 'ID'])
            ->assertOk()->json();
        $this->assertSame('zed@tours.com', $s['email']);
        $this->assertIsString($s['id']);
        $this->api('POST', '/api/suppliers', ['name' => 'Alpha', 'email' => 'a@tours.com'])->assertOk();
        $this->api('POST', '/api/suppliers', ['name' => 'Dup', 'email' => 'zed@tours.com'])->assertStatus(400);
        $this->api('POST', '/api/suppliers', ['name' => 'Bad', 'email' => 'nope'])->assertStatus(422);

        $list = $this->api('GET', '/api/suppliers')->assertOk()->json();
        $this->assertSame(['Alpha', 'Zed'], array_column($list, 'name')); // sorted by name
        $this->api('DELETE', "/api/suppliers/{$s['id']}")->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $this->api('GET', '/api/suppliers')->json());
    }

    public function test_seeder_is_idempotent_and_creates_demo_users(): void
    {
        $this->seed();
        $this->seed();
        $this->assertSame(4, User::count());
        $this->assertSame('operations', User::where('email', 'ops@indianrounds.com')->value('role'));
        $this->login('admin@indianrounds.com', 'admin123')->assertOk();
        $this->login('sales@indianrounds.com', 'Ir@2026Safe', 'b')->assertOk();
        $this->login('ops@indianrounds.com', 'ops123', 'c')->assertOk();
        $this->assertGreaterThan(40, \App\Models\Master::count());
        $this->assertSame(4, \App\Models\Supplier::count());
    }
}
