<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadStatusFlow;
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

    /** one lead per status, plus a second In Progress one: IRST00001 .. IRST00007 */
    private function seed7(): void
    {
        foreach (['New Lead', 'In Progress', 'In Progress', 'Quote Sent to Customer', 'Booked', 'Completed', 'Lost'] as $i => $s) {
            $this->lead(sprintf('IRST%05d', $i + 1), $s);
        }
    }

    private function dealFor(Lead $lead, array $over = []): Deal
    {
        static $n = 0;
        $c = Customer::create(['customer_no' => 'IRC-S'.++$n, 'name' => 'Asha Rao', 'email' => 'asha@example.com']);
        $deal = Deal::create($over + ['deal_no' => 'IRD-S'.$n, 'customer_id' => $c->id, 'name' => 'Goa', 'destination' => 'Goa', 'stage' => 'enquiry', 'travel_date' => '2026-12-20']);
        $lead->forceFill(['deal_id' => $deal->id])->save();

        return $deal;
    }

    public function test_there_are_six_statuses_each_in_exactly_one_stage_and_old_names_map_to_them(): void
    {
        $all = collect(LeadStages::MAP)->flatten();
        $this->assertCount(6, $all);
        $this->assertCount(6, $all->unique());
        $this->assertSame('progress', LeadStages::of('In Progress'));
        $this->assertSame('lost', LeadStages::of('Lost'));
        $this->assertNull(LeadStages::of('Whatever'));
        $this->assertSame('In Progress', LeadStages::normalize('Supplier Mail Sent'));
        $this->assertSame('In Progress', LeadStages::normalize('Follow-up'));
        $this->assertSame('Booked', LeadStages::normalize('Payment Received'));
        $this->assertSame('Completed', LeadStages::normalize('Closed'));
        $this->assertSame('Lost', LeadStages::normalize('Cancelled'));
        $this->assertSame('Booked', LeadStages::normalize('Booked'));
    }

    public function test_the_list_can_be_narrowed_to_a_stage(): void
    {
        $this->seed7();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $statuses = fn (string $qs) => collect($this->getJson('/api/leads?'.$qs)->assertOk()->json())->pluck('lead_status')->sort()->values()->all();

        $this->assertSame(['New Lead'], $statuses('stage=new'));
        $this->assertSame(['In Progress', 'In Progress'], $statuses('stage=progress'));
        $this->assertSame(['Booked'], $statuses('stage=booked'));
        $this->assertSame(['Completed'], $statuses('stage=completed'));
        $this->assertSame(['Lost'], $statuses('stage=lost'));
        $this->assertCount(7, $statuses('stage=all'));
        $this->assertSame([], $statuses('stage=nonsense'));                                   // an unknown stage matches nothing rather than everything
    }

    public function test_stage_counts_follow_the_other_filters_and_the_users_visibility(): void
    {
        $this->seed7();
        $this->lead('IRST00099', 'New Lead', ['destination' => 'Bali']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');

        $c = $this->getJson('/api/leads/stage-counts')->assertOk()->json();
        $this->assertSame(8, $c['stages']['all']);
        $this->assertSame([2, 2, 1, 1, 1, 1], [$c['stages']['new'], $c['stages']['progress'], $c['stages']['quoted'], $c['stages']['booked'], $c['stages']['completed'], $c['stages']['lost']]);

        $c = $this->getJson('/api/leads/stage-counts?destination=Bali')->assertOk()->json();   // same filters as the list
        $this->assertSame([1, 1, 0], [$c['stages']['all'], $c['stages']['new'], $c['stages']['booked']]);

        DB::table('leads')->where('lead_id', 'IRST00099')->update(['created_at' => now()->subYear()]);   // a created-date filter narrows the counts too
        $this->assertSame(7, $this->getJson('/api/leads/stage-counts?date_from='.now()->subDays(2)->toDateString())->json('stages.all'));

        $sales = User::factory()->sales()->create();                                           // sales count only their own leads
        DB::table('leads')->whereIn('lead_id', ['IRST00001', 'IRST00005'])->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $c = $this->getJson('/api/leads/stage-counts')->assertOk()->json();
        $this->assertSame([2, 1, 1, 0], [$c['stages']['all'], $c['stages']['new'], $c['stages']['booked'], $c['stages']['lost']]);
    }

    public function test_archived_leads_leave_every_other_tab_and_fill_the_archive_tab(): void
    {
        $this->seed7();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $this->postJson('/api/leads/IRST00001/archive')->assertOk()->assertJsonPath('lead_status', 'New Lead');   // keeps its status
        $this->postJson('/api/leads/IRST00005/archive')->assertOk();
        $this->postJson('/api/leads/IRST00005/archive')->assertOk();                                               // twice changes nothing

        $ids = fn (string $qs) => collect($this->getJson('/api/leads?'.$qs)->assertOk()->json())->pluck('lead_id')->sort()->values()->all();
        $this->assertSame(['IRST00001', 'IRST00005'], $ids('stage=archived'));
        $this->assertCount(5, $ids('stage=all'));
        $this->assertSame([], $ids('stage=new'));
        $this->assertSame(['IRST00001'], $ids('stage=archived&q=IRST00001'));                                    // search and filters still apply

        $c = $this->getJson('/api/leads/stage-counts')->assertOk()->json();
        $this->assertSame([5, 0, 0, 2], [$c['stages']['all'], $c['stages']['new'], $c['stages']['booked'], $c['stages']['archived']]);

        $this->postJson('/api/leads/IRST00001/unarchive')->assertOk()->assertJsonPath('archived_at', null);
        $this->assertSame(['IRST00001'], $ids('stage=new'));
        $this->assertSame(1, $this->getJson('/api/leads/stage-counts')->json('stages.archived'));
    }

    public function test_sales_can_archive_only_leads_they_can_see(): void
    {
        $this->seed7();
        $sales = User::factory()->sales()->create();
        DB::table('leads')->where('lead_id', 'IRST00001')->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $this->postJson('/api/leads/IRST00001/archive')->assertOk();
        $this->postJson('/api/leads/IRST00002/archive')->assertStatus(403);
        $this->assertNull(Lead::where('lead_id', 'IRST00002')->value('archived_at'));
    }

    public function test_salespeople_choose_only_in_progress_quote_sent_or_lost_and_lost_needs_a_reason(): void
    {
        $this->seed7();
        $sales = User::factory()->sales()->create();
        DB::table('leads')->whereIn('lead_id', ['IRST00001', 'IRST00005'])->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'In Progress'])->assertOk()->assertJsonPath('lead_status', 'In Progress');
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Quote Sent to Customer'])->assertOk();
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Booked'])->assertStatus(403);               // happens when the deal is won
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Completed'])->assertStatus(403);
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Follow-up'])->assertOk()->assertJsonPath('lead_status', 'In Progress');   // an old client still sending it lands in In Progress (follow-ups are activities)
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Nonsense'])->assertStatus(400);
        $this->patchJson('/api/leads/IRST00002', ['lead_status' => 'In Progress'])->assertStatus(403);          // not their lead

        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Lost'])->assertStatus(400);                 // a reason is needed
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Lost', 'lost_reason' => 'Price too high'])->assertOk()
            ->assertJsonPath('lead_status', 'Lost')->assertJsonPath('lost_reason', 'Price too high');
        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'In Progress'])->assertOk()->assertJsonPath('lost_reason', null);   // reopened

        $this->patchJson('/api/leads/IRST00001', ['booking_status' => 'Booked'])->assertOk();                  // no longer a field sales can set
        $this->assertNotSame('Booked', Lead::where('lead_id', 'IRST00001')->value('booking_status'));
    }

    public function test_marking_a_lead_lost_removes_its_scheduled_follow_ups_only(): void
    {
        $this->seed7();
        $sales = User::factory()->sales()->create();
        DB::table('leads')->whereIn('lead_id', ['IRST00001', 'IRST00005'])->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $mk = fn (string $lead, string $type, bool $done = false) => \App\Models\Activity::create([
            'type' => $type, 'title' => 't', 'due_at' => now()->addDay(), 'assigned_to' => $sales->id, 'lead_id' => $lead, 'completed_at' => $done ? now() : null,
        ])->id;
        $open = $mk('IRST00001', 'follow_up');
        $open2 = $mk('IRST00001', 'follow_up');
        $done = $mk('IRST00001', 'follow_up', true);
        $call = $mk('IRST00001', 'call');
        $other = $mk('IRST00005', 'follow_up');

        $this->patchJson('/api/leads/IRST00001', ['lead_status' => 'Lost', 'lost_reason' => 'Price too high'])->assertOk();

        $this->assertDatabaseMissing('activities', ['id' => $open]);
        $this->assertDatabaseMissing('activities', ['id' => $open2]);
        $this->assertDatabaseHas('activities', ['id' => $done]);
        $this->assertDatabaseHas('activities', ['id' => $call]);
        $this->assertDatabaseHas('activities', ['id' => $other]);
    }

    public function test_operations_can_set_any_status_and_booked_follows_into_booking_status(): void
    {
        $this->seed7();
        Sanctum::actingAs(User::factory()->operations()->create(), ['*'], 'sanctum');
        $this->patchJson('/api/leads/IRST00002', ['lead_status' => 'Booked'])->assertOk()->assertJsonPath('booking_status', 'Booked');
        $this->patchJson('/api/leads/IRST00002', ['lead_status' => 'Completed'])->assertOk()->assertJsonPath('booking_status', 'Booked');
        $this->patchJson('/api/leads/IRST00002', ['lead_status' => 'In Progress'])->assertOk()->assertJsonPath('booking_status', '');   // taken back
    }

    public function test_the_lead_follows_its_deal_and_trip(): void
    {
        $this->seed7();
        $lead = Lead::where('lead_id', 'IRST00002')->first();
        $deal = $this->dealFor($lead);

        $deal->update(['stage' => 'quoted']);
        $this->assertSame('Quote Sent to Customer', $lead->fresh()->lead_status);
        $deal->update(['stage' => 'won']);
        $this->assertSame('Booked', $lead->fresh()->lead_status);
        $this->assertSame('Booked', $lead->fresh()->booking_status);
        $deal->update(['trip_status' => 'completed']);
        $this->assertSame('Completed', $lead->fresh()->lead_status);

        $lost = Lead::where('lead_id', 'IRST00003')->first();
        $this->dealFor($lost)->update(['stage' => 'lost', 'lost_reason' => 'Chose another agent']);
        $this->assertSame(['Lost', 'Chose another agent'], [$lost->fresh()->lead_status, $lost->fresh()->lost_reason]);

        $done = Lead::where('lead_id', 'IRST00006')->first();                           // a Completed lead is never pulled back by its deal
        $this->dealFor($done)->update(['stage' => 'quoted']);
        $this->assertSame('Completed', $done->fresh()->lead_status);
    }

    public function test_booked_leads_become_completed_once_the_trip_date_has_passed(): void
    {
        $this->lead('IRST10001', 'Booked', ['travel_date' => now()->subDays(10)->toDateString(), 'trip_end_date' => now()->subDays(3)->toDateString()]);
        $this->lead('IRST10002', 'Booked', ['travel_date' => now()->subDays(2)->toDateString(), 'trip_end_date' => now()->addDays(4)->toDateString()]);   // still travelling
        $this->lead('IRST10003', 'Booked', ['travel_date' => now()->addDays(20)->toDateString()]);
        $this->lead('IRST10004', 'In Progress', ['travel_date' => now()->subDays(20)->toDateString()]);                                                     // never booked
        $this->assertSame(1, LeadStatusFlow::completeFinishedTrips());
        $this->assertSame(['Completed', 'Booked', 'Booked', 'In Progress'], Lead::orderBy('lead_id')->pluck('lead_status')->all());
        $this->artisan('leads:complete-trips')->assertSuccessful();
    }

    public function test_the_list_shows_the_next_open_follow_up_activity_of_each_lead(): void
    {
        $this->seed7();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        Activity::create(['type' => 'follow_up', 'title' => 'Call Asha', 'priority' => 'medium', 'lead_id' => 'IRST00002', 'due_at' => now()->addDays(3)]);
        Activity::create(['type' => 'follow_up', 'title' => 'Later', 'priority' => 'medium', 'lead_id' => 'IRST00002', 'due_at' => now()->addDays(9)]);
        Activity::create(['type' => 'follow_up', 'title' => 'Done one', 'priority' => 'medium', 'lead_id' => 'IRST00003', 'due_at' => now()->addDay(), 'completed_at' => now()]);
        Activity::create(['type' => 'task', 'title' => 'Not a follow-up', 'priority' => 'medium', 'lead_id' => 'IRST00004', 'due_at' => now()->addDay()]);

        $by = collect($this->getJson('/api/leads')->assertOk()->json())->keyBy('lead_id');
        $this->assertNotNull($by['IRST00002']['next_follow_up']);
        $this->assertStringStartsWith(now()->addDays(3)->format('Y-m-d'), (string) $by['IRST00002']['next_follow_up']);   // the soonest one
        $this->assertNull($by['IRST00003']['next_follow_up']);                                                            // completed
        $this->assertNull($by['IRST00004']['next_follow_up']);                                                            // other type
        $this->assertNull($by['IRST00001']['next_follow_up']);
    }
}
