<?php

namespace Tests\Feature;

use App\Models\DestinationCity;
use App\Models\DestinationCityItem;
use App\Models\Master;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class LeadOptionsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function seedMaster(): void
    {
        Master::create(['category' => 'destination', 'value' => 'Goa']);
        Master::create(['category' => 'destination', 'value' => 'Bali']);
        Master::create(['category' => 'lead_source', 'value' => 'Instagram']);
        $d = DestinationCity::create(['destination' => 'Goa']);
        DestinationCityItem::create(['destination_city_id' => $d->id, 'city' => 'Panaji', 'position' => 0]);
    }

    public function test_sales_and_operations_have_no_master_data_access_but_still_get_the_lead_form_lists(): void
    {
        $this->seedMaster();
        foreach (['sales', 'operations'] as $i => $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->loginAs($u, "u$i");
            $this->assertFalse($u->hasPermission('master.view'));
            $this->api('GET', '/api/master', [], "u$i")->assertStatus(403);
            $this->api('GET', '/api/destination-cities', [], "u$i")->assertStatus(403);

            $r = $this->api('GET', '/api/lead-options', [], "u$i")->assertOk();
            $this->assertSame(['Goa', 'Bali'], $r->json('master.destination'));
            $this->assertSame(['Instagram'], $r->json('master.lead_source'));
            $this->assertSame(['Panaji'], $r->json('cities.Goa'));
        }
    }

    public function test_admin_keeps_master_data_and_roles_without_lead_access_cannot_read_the_lists(): void
    {
        $this->seedMaster();
        $admin = User::factory()->admin()->create();
        $this->loginAs($admin, 'a');
        $this->api('GET', '/api/master', [], 'a')->assertOk()->assertJsonCount(3);
        $this->api('GET', '/api/lead-options', [], 'a')->assertOk();

        $acct = User::factory()->create(['role' => 'accountant']);
        $this->loginAs($acct, 'c');
        $this->api('GET', '/api/lead-options', [], 'c')->assertStatus(403);
    }

    public function test_a_custom_role_can_still_be_given_master_view(): void
    {
        $this->seedMaster();
        $role = Role::create(['name' => 'Data lead', 'slug' => 'data-lead', 'description' => '', 'is_system' => false]);
        $role->syncPermissions(['master.view']);
        $u = User::factory()->create(['role' => 'data-lead']);
        $this->loginAs($u, 'd');
        $this->api('GET', '/api/master', [], 'd')->assertOk();
    }
}
