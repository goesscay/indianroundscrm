<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamReportTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function lead(string $id, User $owner, array $over = []): Lead
    {
        return Lead::create($over + ['lead_id' => $id, 'customer_name' => "Cust $id", 'phone' => '9'.random_int(100000000, 999999999), 'destination' => 'Goa',
            'staff_id' => $owner->id, 'staff_name' => $owner->name, 'lead_status' => 'New Lead']);
    }

    public function test_only_a_super_admin_can_see_any_of_it(): void
    {
        foreach (['admin', 'operations', 'sales'] as $role) {
            $this->as(User::factory()->create(['role' => $role]));
            $this->getJson('/api/reports/team')->assertStatus(404);
            $this->getJson('/api/reports/team/summary')->assertStatus(404);
            $this->getJson('/api/reports/team/1')->assertStatus(404);
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/reports/team')->assertStatus(401);

        $this->as(User::factory()->create(['role' => 'super_admin']));
        $this->getJson('/api/reports/team')->assertOk()->assertJsonStructure(['period', 'previous', 'people']);
        $this->getJson('/api/reports/team/summary')->assertOk()->assertJsonStructure(['tiles', 'behind']);
    }

    public function test_the_numbers_count_what_each_person_recorded_in_the_period(): void
    {
        $now = Carbon::now('Asia/Kolkata');
        $sa = User::factory()->create(['role' => 'super_admin', 'name' => 'Gopi']);
        $priya = User::factory()->sales()->create(['name' => 'Priya']);
        $arun = User::factory()->sales()->create(['name' => 'Arun']);
        $ops = User::factory()->operations()->create(['name' => 'Karthik']);

        // Priya: 3 new leads. A is untouched and old; B was called; C got a note.
        $a = $this->lead('IRTR00001', $priya, ['created_at' => now()->subDays(2)]);
        $b = $this->lead('IRTR00002', $priya);
        $c = $this->lead('IRTR00003', $priya);
        DB::table('contact_events')->insert([
            ['lead_id' => $b->lead_id, 'kind' => 'call', 'user_id' => $priya->id, 'user_name' => 'Priya', 'timestamp' => now(), 'channel' => 'customer', 'target' => '', 'note' => ''],
            ['lead_id' => $b->lead_id, 'kind' => 'whatsapp', 'user_id' => $priya->id, 'user_name' => 'Priya', 'timestamp' => now(), 'channel' => 'customer', 'target' => '', 'note' => ''],
        ]);
        DB::table('lead_notes')->insert(['lead_id' => $c->lead_id, 'note' => 'spoke', 'created_by_id' => $priya->id, 'created_by' => 'Priya', 'created_at' => now()]);
        $this->lead('IRTR00004', $arun, ['created_at' => now()->subDays(3)]);                        // Arun's lead sits untouched

        // follow-ups: Priya has one done, one overdue
        Activity::create(['type' => 'follow_up', 'title' => 'done', 'priority' => 'medium', 'assigned_to' => $priya->id, 'due_at' => now()->subHour(), 'completed_at' => now(), 'completed_by' => $priya->id]);
        Activity::create(['type' => 'follow_up', 'title' => 'late', 'priority' => 'medium', 'assigned_to' => $priya->id, 'due_at' => now()->subHours(2)]);

        // a booking worth 25,200 and a payment
        $cust = Customer::create(['customer_no' => 'IRC-TR1', 'name' => 'Asha']);
        Deal::create(['deal_no' => 'IRD-TR1', 'customer_id' => $cust->id, 'name' => 'Goa', 'owner_id' => $priya->id, 'stage' => 'won', 'closed_at' => now(), 'selling_amount' => 25200]);
        Receipt::create(['receipt_no' => 'RCT/TR/1', 'kind' => 'receipt', 'status' => 'valid', 'customer_id' => $cust->id, 'amount' => 5000, 'received_date' => now()->toDateString(), 'mode' => 'upi', 'created_by' => $priya->id]);

        $this->as($sa);
        $r = $this->getJson('/api/reports/team?from='.$now->copy()->startOfMonth()->toDateString().'&to='.$now->toDateString())->assertOk()->json();
        $by = collect($r['people'])->keyBy('name');
        $this->assertNotContains('Gopi', $by->keys()->all());                                        // the reader is not in the list
        $p = $by['Priya']['m'];
        $this->assertSame(3, $p['open_leads']);
        $this->assertSame(3, $p['new_leads']);
        $this->assertSame(1, $p['untouched']);                                                         // only A: no call, no note
        $this->assertSame(1, $p['untouched_24h']);                                                     // and it is older than a day
        $this->assertSame([1, 1, 0, 1], [$p['calls'], $p['whatsapps'], $p['emails'], $p['notes']]);
        $this->assertSame([2, 1, 1], [$p['fu_due'], $p['fu_done'], $p['fu_overdue']]);
        $this->assertEquals([1, 25200.0], [$p['booked'], $p['booked_value']]);
        $this->assertEquals([1, 5000.0], [$p['payments'], $p['payments_value']]);
        $this->assertSame('sales', $by['Priya']['group']);
        $this->assertSame('operations', $by['Karthik']['group']);
        $this->assertSame(1, $by['Arun']['m']['untouched_24h']);
        $this->assertSame('untouched', $by['Arun']['flags'][0]['key']);                               // raised on the call
        $this->assertSame(0, $by['Karthik']['m']['new_leads']);

        // a period with nothing in it
        $r = $this->getJson('/api/reports/team?from=2020-01-01&to=2020-01-31')->assertOk()->json();
        $this->assertSame(0, collect($r['people'])->keyBy('name')['Priya']['m']['calls']);
        $this->assertSame(['2019-12-01', '2019-12-31'], [$r['previous']['from'], $r['previous']['to']]);   // the 31 days before it

        // bad dates
        $this->getJson('/api/reports/team?from=2026-02-01&to=2026-01-01')->assertStatus(400);
        $this->getJson('/api/reports/team?from=nope')->assertStatus(400);
        $this->getJson('/api/reports/team?from=2020-01-01&to=2026-01-01')->assertStatus(400);              // more than a year
    }

    public function test_the_drill_down_lists_the_specifics_and_the_summary_names_who_is_behind(): void
    {
        $sa = User::factory()->create(['role' => 'super_admin']);
        $priya = User::factory()->sales()->create(['name' => 'Priya']);
        $this->lead('IRTR00010', $priya, ['created_at' => now()->subDays(4), 'customer_name' => 'Waiting Wanda']);
        $this->lead('IRTR00011', $priya, ['lead_status' => 'Lost', 'lost_reason' => 'Price too high']);
        $this->lead('IRTR00012', $priya, ['lead_status' => 'Lost', 'lost_reason' => 'Price too high']);
        $this->lead('IRTR00013', $priya, ['lead_status' => 'Lost', 'lost_reason' => 'Not responding']);

        $this->as($sa);
        $d = $this->getJson("/api/reports/team/{$priya->id}")->assertOk()->json();
        $this->assertSame('Priya', $d['person']['name']);
        $this->assertSame(['IRTR00010'], array_column($d['untouched'], 'lead_id'));
        $this->assertSame('Waiting Wanda', $d['untouched'][0]['customer_name']);
        $this->assertSame([['reason' => 'Price too high', 'count' => 2], ['reason' => 'Not responding', 'count' => 1]], $d['lost_reasons']);
        $this->getJson('/api/reports/team/'.$sa->id)->assertStatus(404);                                // Super Admins are not in the report

        $s = $this->getJson('/api/reports/team/summary')->assertOk()->json();
        $this->assertSame(1, $s['tiles']['untouched_24h']['now']);
        $this->assertSame('Priya', $s['behind'][0]['name']);
    }
}
