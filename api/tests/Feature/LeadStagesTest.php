<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Support\LeadStages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadStagesTest extends TestCase
{
    use RefreshDatabase;

    private function lead(string $id, string $status, array $over = []): Lead
    {
        return Lead::create(array_merge([
            'lead_id' => $id, 'customer_name' => "Cust $id", 'phone' => '9'.random_int(100000000, 999999999),
            'destination' => 'Goa', 'lead_status' => $status,
        ], $over));
    }

    private function seed12(): void
    {
        foreach (['New Lead', 'Supplier Mail Sent', 'Quotation Awaiting', 'Quote Received', 'Quote Sent to Customer', 'Follow-up',
            'Booked', 'Payment Pending', 'Payment Received', 'Travel Completed', 'Closed', 'Cancelled'] as $i => $s) {
            $this->lead(sprintf('IRST%05d', $i + 1), $s);
        }
    }

    public function test_every_status_belongs_to_exactly_one_stage(): void
    {
        $all = collect(LeadStages::MAP)->flatten();
        $this->assertCount(12, $all);
        $this->assertCount(12, $all->unique());
        $this->assertSame('progress', LeadStages::of('Follow-up'));
        $this->assertSame('lost', LeadStages::of('Cancelled'));
        $this->assertNull(LeadStages::of('Whatever'));
    }

    public function test_the_list_can_be_narrowed_to_a_stage_or_a_status_inside_it(): void
    {
        $this->seed12();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $ids = fn (string $qs) => collect($this->getJson('/api/leads?'.$qs)->assertOk()->json('items') ?? $this->getJson('/api/leads?'.$qs)->json())->pluck('lead_status')->sort()->values()->all();

        $this->assertSame(['New Lead'], $ids('stage=new'));
        $this->assertSame(['Follow-up', 'Quotation Awaiting', 'Quote Received', 'Supplier Mail Sent'], $ids('stage=progress'));
        $this->assertSame(['Booked', 'Payment Pending', 'Payment Received'], $ids('stage=booked'));
        $this->assertSame(['Cancelled'], $ids('stage=lost'));
        $this->assertCount(12, $ids('stage=all'));
        $this->assertCount(12, $ids(''));
        $this->assertSame([], $ids('stage=nonsense'));                                   // an unknown stage matches nothing rather than everything
        $this->assertSame(['Quote Received'], $ids('stage=progress&status='.rawurlencode('Quote Received')));
        $this->assertSame([], $ids('stage=new&status='.rawurlencode('Booked')));          // both must hold
    }

    public function test_stage_counts_follow_the_other_filters_and_the_users_visibility(): void
    {
        $this->seed12();
        $this->lead('IRST00099', 'New Lead', ['destination' => 'Bali']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');

        $c = $this->getJson('/api/leads/stage-counts')->assertOk()->json();
        $this->assertSame(13, $c['stages']['all']);
        $this->assertSame(2, $c['stages']['new']);
        $this->assertSame(4, $c['stages']['progress']);
        $this->assertSame(1, $c['stages']['quoted']);
        $this->assertSame(3, $c['stages']['booked']);
        $this->assertSame(2, $c['stages']['completed']);
        $this->assertSame(1, $c['stages']['lost']);
        $this->assertSame(1, $c['statuses']['Follow-up']);

        $c = $this->getJson('/api/leads/stage-counts?destination=Bali')->assertOk()->json();   // same filters as the list
        $this->assertSame(1, $c['stages']['all']);
        $this->assertSame(1, $c['stages']['new']);
        $this->assertSame(0, $c['stages']['booked']);

        // a created-date filter narrows the counts too
        DB::table('leads')->where('lead_id', 'IRST00099')->update(['created_at' => now()->subYear()]);
        $c = $this->getJson('/api/leads/stage-counts?date_from='.now()->subDays(2)->toDateString())->assertOk()->json();
        $this->assertSame(12, $c['stages']['all']);

        // sales count only their own leads
        $sales = User::factory()->sales()->create();
        DB::table('leads')->whereIn('lead_id', ['IRST00001', 'IRST00007'])->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $c = $this->getJson('/api/leads/stage-counts')->assertOk()->json();
        $this->assertSame(2, $c['stages']['all']);
        $this->assertSame(1, $c['stages']['new']);
        $this->assertSame(1, $c['stages']['booked']);
        $this->assertSame(0, $c['stages']['lost']);
    }

    public function test_one_click_stage_change_uses_the_normal_lead_update(): void
    {
        $this->seed12();
        $sales = User::factory()->sales()->create();
        DB::table('leads')->where('lead_id', 'IRST00001')->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Follow-up'])->assertOk()->assertJsonPath('lead_status', 'Follow-up');
        $this->assertSame('progress', LeadStages::of(Lead::where('lead_id', 'IRST00001')->value('lead_status')));
        $this->patchJson('/api/leads/IRST00002', ['lead_status' => 'Booked'])->assertStatus(403);   // not their lead
    }
}
