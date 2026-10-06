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
}
