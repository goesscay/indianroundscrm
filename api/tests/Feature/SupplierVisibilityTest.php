<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

/** Supplier names, mail ids, mail history and replies are for Admin and Operations only. */
class SupplierVisibilityTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    public function test_sales_cannot_reach_any_supplier_detail_but_operations_and_admin_can(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $ops = User::factory()->create(['role' => 'operations']);
        $admin = User::factory()->admin()->create();
        $this->loginAs($sales, 's');
        $this->loginAs($ops, 'o');
        $this->loginAs($admin, 'a');

        $this->assertFalse($sales->hasPermission('suppliers.view'));
        foreach (['/api/suppliers', '/api/lead-suppliers/IRX1', '/api/supplier-mail/IRX1', '/api/leads/IRX1/supplier-replies', '/api/supplier-replies'] as $url) {
            $this->api('GET', $url, [], 's')->assertStatus(403);
        }
        $this->api('GET', '/api/suppliers', [], 'o')->assertOk();
        $this->api('GET', '/api/suppliers', [], 'a')->assertOk();
        // sales can still ask Operations for supplier quotes
        $this->assertTrue($sales->hasPermission('rsq.request'));
    }

    public function test_suppliers_can_be_edited_by_those_who_manage_them(): void
    {
        $ops = User::factory()->create(['role' => 'operations']);
        $sales = User::factory()->create(['role' => 'sales']);
        $this->loginAs($ops, 'o');
        $this->loginAs($sales, 's');
        $a = $this->api('POST', '/api/suppliers', ['name' => 'Sunrise', 'email' => 'Agent@Sunrise.test', 'country' => 'Vietnam'], 'o')->assertOk()->json();
        $b = $this->api('POST', '/api/suppliers', ['name' => 'Other', 'email' => 'other@x.test'], 'o')->assertOk()->json();

        $r = $this->api('PATCH', "/api/suppliers/{$a['id']}", ['name' => 'Sunrise Travels', 'email' => 'NEW@Sunrise.test', 'phone' => '+84 90 1', 'destination' => 'Vietnam', 'country' => ''], 'o')->assertOk();
        $this->assertSame(['Sunrise Travels', 'new@sunrise.test', '+84 90 1', ''], [$r->json('name'), $r->json('email'), $r->json('phone'), $r->json('country')]);
        $this->api('PATCH', "/api/suppliers/{$a['id']}", ['email' => 'other@x.test'], 'o')->assertStatus(400);      // another supplier's address
        $this->api('PATCH', "/api/suppliers/{$a['id']}", ['email' => 'new@sunrise.test'], 'o')->assertOk();         // its own address is fine
        $this->api('PATCH', "/api/suppliers/{$a['id']}", ['email' => 'not-an-email'], 'o')->assertStatus(422);
        $this->api('PATCH', '/api/suppliers/99999', ['name' => 'x'], 'o')->assertStatus(404);
        $this->api('PATCH', "/api/suppliers/{$b['id']}", ['name' => 'Hacked'], 's')->assertStatus(403);
        $this->assertSame('Other', \App\Models\Supplier::find($b['id'])->name);
    }
}
