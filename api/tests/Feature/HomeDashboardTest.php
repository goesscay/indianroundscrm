<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overview_numbers_and_ordering(): void
    {
        $u = User::factory()->admin()->create(['name' => 'Boss']);
        $this->loginAs($u, 'a');
        $c = Customer::create(['customer_no' => 'IRC-H1', 'name' => 'Asha', 'owner_id' => $u->id]);
        $mk = fn (array $a) => Deal::create($a + ['deal_no' => 'IRD-H'.uniqid(), 'customer_id' => $c->id, 'name' => 'D', 'owner_id' => $u->id, 'probability' => 10]);
        $mk(['stage' => 'enquiry', 'selling_amount' => 100000]);
        $mk(['stage' => 'quoted', 'selling_amount' => 200000, 'probability' => 40]);
        $mk(['stage' => 'won', 'selling_amount' => 50000, 'probability' => 100, 'closed_at' => '2026-10-03 06:00:00', 'travel_date' => '2026-10-25', 'destination' => 'Goa', 'adults' => 2]);
        $mk(['stage' => 'won', 'selling_amount' => 70000, 'probability' => 100, 'closed_at' => '2026-08-03 06:00:00', 'travel_date' => '2027-02-25', 'destination' => 'Bali']);
        $mk(['stage' => 'lost', 'selling_amount' => 9000, 'probability' => 0, 'closed_at' => '2026-09-03 06:00:00']);
        $act = fn (string $title, string $due, ?string $done = null) => Activity::create(['type' => 'task', 'title' => $title, 'priority' => 'low', 'assigned_to' => $u->id, 'created_by' => $u->id, 'due_at' => $due, 'completed_at' => $done]);
        $act('Late call', '2026-10-13 05:00:00');
        $act('Later today', '2026-10-15 12:00:00');
        $act('Next week', '2026-10-22 12:00:00');
        $act('Done already', '2026-10-10 12:00:00', '2026-10-11 12:00:00');

        $r = $this->api('GET', '/api/dashboard/overview', [], 'a')->assertOk();
        $k = collect($r->json('kpis'))->keyBy('key');
        $this->assertEquals(300000, $k['pipeline']['value']);
        $this->assertEquals(50000, $k['won']['value']);          // only October's win
        $stages = collect($r->json('pipeline.stages'));
        $this->assertSame(1, $stages->firstWhere('stage', 'enquiry')['count']);
        $this->assertEquals(120000, $stages->firstWhere('stage', 'won')['value']);
        $this->assertSame(1, $r->json('pipeline.lost'));

        $this->assertSame(['Late call', 'Later today'], array_column($r->json('focus.items'), 'title')); // overdue first; nothing later than today; nothing done
        $this->assertSame(1, $r->json('focus.overdue'));

        $this->assertCount(1, $r->json('departures'));
        $this->assertSame('Goa', $r->json('departures.0.destination'));
        $this->assertSame(10, $r->json('departures.0.days'));
        $this->assertSame(2, $r->json('departures.0.pax'));
        $this->assertCount(6, $r->json('months'));
        $this->assertEquals(50000, $r->json('months.5.won'));
        $this->assertEquals(70000, $r->json('months.3.won'));
        $this->assertSame('Q3 · Oct–Dec', $r->json('target.label'));
        $this->assertSame('Boss', $r->json('leaderboard.0.name'));
        $this->assertSame(1, $r->json('leaderboard.0.deals'));
    }

    public function test_sections_follow_permissions_and_ownership(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'sales']);
        $this->loginAs($sales, 's');
        $c = Customer::create(['customer_no' => 'IRC-H2', 'name' => 'B', 'owner_id' => $sales->id]);
        Deal::create(['deal_no' => 'IRD-M1', 'customer_id' => $c->id, 'name' => 'Mine', 'owner_id' => $sales->id, 'stage' => 'quoted', 'selling_amount' => 1000, 'probability' => 40]);
        Deal::create(['deal_no' => 'IRD-O1', 'customer_id' => $c->id, 'name' => 'Theirs', 'owner_id' => $other->id, 'stage' => 'quoted', 'selling_amount' => 99000, 'probability' => 40]);
        Activity::create(['type' => 'task', 'title' => 'Not mine', 'priority' => 'low', 'assigned_to' => $other->id, 'created_by' => $other->id, 'due_at' => '2026-10-15 05:00:00']);

        $r = $this->api('GET', '/api/dashboard/overview', [], 's')->assertOk();
        $this->assertEquals(1000, collect($r->json('kpis'))->firstWhere('key', 'pipeline')['value']);   // only their own deal
        $this->assertSame([], array_column($r->json('focus.items'), 'title'));
        $this->assertNull($r->json('leaderboard'));
        $this->assertNull($r->json('supplier'));
        $this->assertSame([], $r->json('payments')); // receipts.view is a sales default; none recorded yet

        $this->loginAs(User::factory()->create(['role' => 'operations']), 'o');
        $this->assertNotNull($this->api('GET', '/api/dashboard/overview', [], 'o')->json('supplier'));

        $this->loginAs(User::factory()->create(['role' => 'nobody']), 'n');
        $this->api('GET', '/api/dashboard/overview', [], 'n')->assertStatus(403);                        // no dashboard.view
    }

    public function test_focus_list_shows_activities_not_the_old_lead_follow_up_date(): void
    {
        $u = User::factory()->create(['role' => 'sales']);
        $this->loginAs($u, 's');
        // an old lead-level follow-up date no longer feeds the dashboard (it could not be marked done)
        Lead::create(['lead_id' => 'IR1', 'customer_name' => 'Cust IR1', 'phone' => '9'.random_int(100000000, 999999999), 'destination' => 'Goa', 'staff_id' => $u->id,
            'staff_name' => $u->name, 'lead_status' => 'In Progress', 'follow_up_date' => '2026-10-14T10:00:00+05:30', 'lead_date' => '2026-10-01']);
        $r = $this->api('GET', '/api/dashboard/overview', [], 's')->assertOk();
        $this->assertSame([], $r->json('focus.items'));
        $this->assertEquals(0, collect($r->json('kpis'))->firstWhere('key', 'followups')['value']);

        $this->api('POST', '/api/activities', ['type' => 'follow_up', 'title' => 'Call Cust IR1', 'lead_id' => 'IR1', 'due_at' => '2026-10-14T10:00:00+05:30', 'assigned_to' => (string) $u->id], 's')->assertOk();
        $r = $this->api('GET', '/api/dashboard/overview', [], 's')->assertOk();
        $this->assertSame(['Call Cust IR1'], array_column($r->json('focus.items'), 'title'));
        $this->assertTrue($r->json('focus.items.0.overdue'));
        $this->assertEquals(1, collect($r->json('kpis'))->firstWhere('key', 'followups')['value']);
    }
}
