<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role, array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['role' => $role, 'consultant_code' => 'KA'], $attrs));
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function payload(array $over = []): array
    {
        static $n = 0;

        return array_merge(['customer_name' => 'Asha Rao', 'phone' => '90000'.str_pad((string) (++$n), 5, '0', STR_PAD_LEFT), 'destination' => 'Bali'], $over);
    }

    public function test_a_sales_person_owns_and_sees_the_leads_they_create_even_for_an_allocated_destination(): void
    {
        $other = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ME', 'name' => 'Meena']);
        Allocation::create(['destination' => 'Bali', 'consultant_id' => $other->id]);
        $me = $this->as('sales', ['consultant_code' => 'AK', 'name' => 'Arjun']);

        $r = $this->postJson('/api/leads', $this->payload())->assertOk();
        $r->assertJsonPath('staff_id', (string) $me->id)->assertJsonPath('staff_name', 'Arjun');
        $this->assertStringStartsWith('IRAK', $r->json('lead_id'));
        $this->assertContains($r->json('lead_id'), collect($this->getJson('/api/leads')->json())->pluck('lead_id')->all());   // in their own list

        // the same when they send their own id explicitly
        $this->postJson('/api/leads', $this->payload(['owner_id' => (string) $me->id]))->assertOk()->assertJsonPath('staff_id', (string) $me->id);
    }

    public function test_sales_cannot_create_a_lead_in_someone_elses_name(): void
    {
        $other = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ME']);
        $this->as('sales');
        $this->postJson('/api/leads', $this->payload(['owner_id' => (string) $other->id]))->assertStatus(403);
    }

    public function test_operations_and_admin_can_pick_the_owner_or_leave_it_to_the_allocation(): void
    {
        $meena = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ME', 'name' => 'Meena']);
        $zed = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ZZ', 'name' => 'Zed']);
        Allocation::create(['destination' => 'Bali', 'consultant_id' => $meena->id]);
        $this->as('operations');

        $this->postJson('/api/leads', $this->payload(['owner_id' => (string) $zed->id]))->assertOk()->assertJsonPath('staff_name', 'Zed');
        $this->postJson('/api/leads', $this->payload())->assertOk()->assertJsonPath('staff_name', 'Meena');                          // omitted: allocation
        $this->postJson('/api/leads', $this->payload(['owner_id' => 'auto']))->assertOk()->assertJsonPath('staff_name', 'Meena');
        // a user who cannot own leads, or an inactive one, is not a valid owner
        $ops = User::factory()->create(['role' => 'operations']);
        $this->postJson('/api/leads', $this->payload(['owner_id' => (string) $ops->id]))->assertStatus(422);
        $gone = User::factory()->create(['role' => 'sales', 'active' => false, 'account_status' => 'inactive']);
        $this->postJson('/api/leads', $this->payload(['owner_id' => (string) $gone->id]))->assertStatus(422);
        $this->postJson('/api/leads', $this->payload(['owner_id' => '9999']))->assertStatus(422);
    }

    public function test_lead_options_list_the_consultants_for_the_owner_field(): void
    {
        $a = User::factory()->create(['role' => 'sales', 'consultant_code' => 'AA', 'name' => 'Asha']);
        User::factory()->create(['role' => 'sales', 'name' => 'Inactive', 'active' => false, 'account_status' => 'inactive']);
        User::factory()->create(['role' => 'accountant', 'name' => 'Accounts']);
        $this->as('sales');
        $owners = collect($this->getJson('/api/lead-options')->assertOk()->json('owners'));
        $this->assertContains((string) $a->id, $owners->pluck('id')->all());
        $this->assertNotContains('Inactive', $owners->pluck('name')->all());
        $this->assertNotContains('Accounts', $owners->pluck('name')->all());
        $this->assertSame(['id', 'name', 'code'], array_keys($owners->first()));
    }
}
