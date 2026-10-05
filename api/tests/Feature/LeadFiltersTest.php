<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function lead(User $owner, string $id, string $dest, string $source): void
    {
        Lead::create([
            'lead_id' => $id, 'customer_name' => "Cust $id", 'phone' => '9'.str_pad((string) crc32($id), 9, '0'),
            'destination' => $dest, 'lead_source' => $source, 'staff_id' => $owner->id,
            'staff_name' => $owner->name, 'lead_status' => 'New Lead',
        ]);
    }

    public function test_source_filter_and_filter_options(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Boss']);
        $sales = User::factory()->create(['role' => 'sales', 'name' => 'Seller', 'consultant_code' => 'SE']);
        $this->lead($admin, 'IRBO0001', 'Bali', 'Website');
        $this->lead($sales, 'IRSE0001', 'Goa', 'Instagram');
        $this->lead($sales, 'IRSE0002', 'Goa', 'Website');
        $this->lead($sales, 'IRSE0003', 'Dubai', '');

        Sanctum::actingAs($admin, ['*'], 'sanctum');
        $this->assertCount(2, $this->getJson('/api/leads?lead_source=Website')->assertOk()->json());
        $this->assertCount(1, $this->getJson('/api/leads?page=1&lead_source=Instagram')->json('items'));
        $this->assertSame(1, $this->getJson('/api/leads?page=1&lead_source=Website&destination=Goa')->json('total'));

        $f = $this->getJson('/api/leads/filters')->assertOk()->json();
        $this->assertSame(['Instagram', 'Website'], $f['sources']);
        $this->assertSame(['Bali', 'Dubai', 'Goa'], $f['destinations']);
        $this->assertEqualsCanonicalizing(['Boss', 'Seller'], array_column($f['owners'], 'name'));

        // A user who only sees their own leads gets options from their own leads and no owner list.
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $f = $this->getJson('/api/leads/filters')->assertOk()->json();
        $this->assertSame(['Instagram', 'Website'], $f['sources']);
        $this->assertSame(['Dubai', 'Goa'], $f['destinations']);
        $this->assertSame([], $f['owners']);
        $this->assertSame(1, $this->getJson('/api/leads?page=1&lead_source=Instagram')->json('total'));
    }
}
