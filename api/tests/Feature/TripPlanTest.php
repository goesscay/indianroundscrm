<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\TripTask;
use App\Models\TripTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class TripPlanTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['crm.company.state' => 'Tamil Nadu', 'crm.company.gstin' => '33ABCDE1234F1Z5']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role, string $browser): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function deal(User $owner, array $over = []): Deal
    {
        static $n = 0;
        $c = Customer::create(['customer_no' => 'IRC-T'.++$n, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'state' => 'Tamil Nadu', 'owner_id' => $owner->id]);

        return Deal::create($over + ['deal_no' => 'IRD-T'.$n, 'customer_id' => $c->id, 'name' => 'Goa trip', 'destination' => 'Goa', 'stage' => 'negotiation',
            'travel_date' => '2026-12-20', 'owner_id' => $owner->id, 'selling_amount' => 42000]);
    }

    /** an issued invoice of 42,000 on the deal (placed by operations) */
    private function invoice(Deal $deal): array
    {
        $d = $this->api('POST', '/api/invoices', ['customer_id' => (string) $deal->customer_id, 'deal_id' => (string) $deal->id, 'title' => 'Goa', 'travel_date' => '2026-12-20',
            'lines' => [['kind' => 'package', 'description' => 'Goa', 'qty' => 2, 'unit_price' => 20000, 'tax_rate' => 5]]], 'o')->assertOk()->json();

        return $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk()->json();
    }

    public function test_default_templates_exist_and_confirming_builds_the_plan_with_dates_counted_back_from_travel(): void
    {
        $this->assertSame(['domestic', 'international', 'package'], TripTemplate::orderBy('id')->pluck('trip_type')->all());
        $ops = $this->as('operations', 'o');
        $deal = $this->deal($ops);

        $r = $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'international'], 'o')->assertOk();
        $this->assertTrue($r->json('confirmed'));
        $this->assertSame('international', $r->json('trip_type'));
        $tasks = collect($r->json('tasks'));
        $this->assertGreaterThan(15, $tasks->count());
        $visa = $tasks->firstWhere('title', 'Apply for visas');
        $this->assertSame('2026-11-10', $visa['due_date']);                      // 40 days before 20 Dec
        $this->assertTrue($visa['critical']);
        $this->assertSame('2026-12-23', $tasks->firstWhere('title', 'Collect feedback and a review')['due_date']);   // after the trip
        $this->assertSame('won', $deal->fresh()->stage);                         // confirming books the deal
        $this->assertSame(0, $r->json('progress.done'));
        $this->assertSame($tasks->count(), $r->json('progress.total'));
        $this->assertSame(66, $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->json('days_left'));   // 15 Oct to 20 Dec

        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertStatus(409);   // once only
        $this->assertSame($tasks->count(), TripTask::where('deal_id', $deal->id)->count());
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'bogus'], 'o')->assertStatus(422);
    }

    public function test_the_trip_confirms_itself_when_the_advance_reaches_the_threshold(): void
    {
        $ops = $this->as('operations', 'o');
        $deal = $this->deal($ops);
        $inv = $this->invoice($deal);

        $this->api('POST', "/api/invoices/{$inv['id']}/receipts", ['amount' => 5000, 'mode' => 'upi'], 'o')->assertOk();   // 11.9%: below the 25% threshold
        $t = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->assertOk();
        $this->assertFalse($t->json('confirmed'));
        $this->assertSame(['part_paid', 11, false], [$t->json('payment.status'), $t->json('payment.percent'), $t->json('payment.advance_received')]);

        $this->api('POST', "/api/invoices/{$inv['id']}/receipts", ['amount' => 6000, 'mode' => 'upi'], 'o')->assertOk();   // 26.2%
        $t = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->assertOk();
        $this->assertTrue($t->json('confirmed'));
        $this->assertSame('domestic', $t->json('trip_type'));
        $this->assertTrue($t->json('payment.advance_received'));
        $this->assertSame([42000.0, 11000.0, 31000.0], [(float) $t->json('payment.invoiced'), (float) $t->json('payment.paid'), (float) $t->json('payment.balance')]);
        $count = TripTask::where('deal_id', $deal->id)->count();
        $this->assertGreaterThan(10, $count);

        $this->api('POST', "/api/invoices/{$inv['id']}/receipts", ['amount' => 31000, 'mode' => 'upi'], 'o')->assertOk();   // paid in full: no second plan
        $t = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o');
        $this->assertSame(['paid', 100], [$t->json('payment.status'), $t->json('payment.percent')]);
        $this->assertSame($count, TripTask::where('deal_id', $deal->id)->count());
    }

    public function test_tasks_can_be_ticked_assigned_added_and_removed_and_progress_follows(): void
    {
        $ops = $this->as('operations', 'o');
        $deal = $this->deal($ops);
        $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'x'], 'o')->assertStatus(409);      // not confirmed yet
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $tasks = TripTask::where('deal_id', $deal->id)->orderBy('id')->get();
        $total = $tasks->count();

        $a = $this->api('PATCH', "/api/trip-tasks/{$tasks[0]->id}", ['status' => 'done'], 'o')->assertOk();
        $this->assertSame('done', $a->json('status'));
        $this->assertNotNull($a->json('completed_at'));
        $b = $this->api('PATCH', "/api/trip-tasks/{$tasks[1]->id}", ['status' => 'na', 'notes' => 'Customer drives'], 'o')->assertOk();
        $this->assertSame('Customer drives', $b->json('notes'));
        $this->api('PATCH', "/api/trip-tasks/{$tasks[2]->id}", ['assigned_to' => (string) $ops->id, 'due_date' => '2026-11-01', 'status' => 'in_progress'], 'o')->assertOk()->assertJsonPath('assigned_to.name', $ops->name);
        $this->api('PATCH', "/api/trip-tasks/{$tasks[2]->id}", ['due_date' => 'tomorrow'], 'o')->assertStatus(422);

        $new = $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'Book the houseboat', 'category' => 'stay', 'due_date' => '2026-10-10', 'critical' => true], 'o')->assertOk();
        $this->assertTrue($new->json('overdue'));                                   // due date already past, still open
        $p = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->json('progress');
        $this->assertSame($total, $p['total']);                                     // 'not needed' leaves the count, the added one joins it
        $this->assertSame(1, $p['done']);
        $this->assertGreaterThanOrEqual(1, $p['overdue']);
        $this->api('DELETE', "/api/trip-tasks/{$new->json('id')}", [], 'o')->assertOk();
        $this->api('PATCH', "/api/trip-tasks/{$tasks[0]->id}", ['status' => 'pending'], 'o')->assertOk()->assertJsonPath('completed_at', null);
    }

    public function test_moving_the_travel_date_moves_the_open_tasks(): void
    {
        $ops = $this->as('operations', 'o');
        $deal = $this->deal($ops);
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $flights = TripTask::where('deal_id', $deal->id)->where('title', 'Book flight, train or bus tickets')->first();
        $done = TripTask::where('deal_id', $deal->id)->where('title', 'Confirm hotel bookings and get vouchers')->first();
        $this->api('PATCH', "/api/trip-tasks/{$done->id}", ['status' => 'done'], 'o')->assertOk();
        $this->assertSame('2026-11-05', $flights->due_date->format('Y-m-d'));       // 45 days before 20 Dec

        $this->api('PATCH', "/api/deals/{$deal->id}", ['travel_date' => '2026-12-27'], 'o')->assertOk();
        $this->assertSame('2026-11-12', $flights->fresh()->due_date->format('Y-m-d'));          // a week later
        $this->assertSame('2026-11-20', $done->fresh()->due_date->format('Y-m-d'));             // finished work is left as it was (30 days before 20 Dec)
    }

    public function test_board_customer_view_and_access_rules(): void
    {
        $ops = $this->as('operations', 'o');
        $sales = $this->as('sales', 's');
        $accountant = $this->as('accountant', 'a');
        $soon = $this->deal($ops, ['travel_date' => '2026-11-01', 'name' => 'Soon']);
        $later = $this->deal($ops, ['travel_date' => '2027-02-01', 'name' => 'Later']);
        $this->deal($ops, ['name' => 'Not confirmed']);
        foreach ([$later, $soon] as $d) {
            $this->api('POST', "/api/deals/{$d->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        }
        $this->api('PATCH', "/api/trip-tasks/".TripTask::where('deal_id', $soon->id)->orderBy('id')->value('id'), ['status' => 'done'], 'o')->assertOk();

        $b = $this->api('GET', '/api/trips', [], 'o')->assertOk();
        $this->assertSame(['Soon', 'Later'], collect($b->json('items'))->pluck('deal.name')->all());          // soonest departure first
        $this->assertSame(2, $b->json('total'));
        $this->assertGreaterThan(0, $b->json('items.0.progress.overdue'));                                    // 1 Nov is under 45 days away: flights were due
        $this->assertGreaterThanOrEqual(1, $b->json('attention'));
        $this->assertCount(1, $this->api('GET', '/api/trips?q=later', [], 'o')->json('items'));

        $this->api('GET', "/api/customers/{$soon->customer_id}/trips", [], 'o')->assertOk()->assertJsonCount(1)->assertJsonPath('0.deal.name', 'Soon');

        // sales sees only their own deals' trips and cannot change anything; accountant has no access
        $this->api('GET', '/api/trips', [], 's')->assertOk()->assertJsonPath('total', 0);
        $this->api('GET', "/api/deals/{$soon->id}/trip", [], 's')->assertStatus(404);
        $this->api('GET', '/api/trips', [], 'a')->assertStatus(403);
        $mine = $this->deal($sales);
        $this->api('POST', "/api/deals/{$mine->id}/trip/confirm", ['trip_type' => 'domestic'], 's')->assertStatus(403);      // sales may view, not confirm
        $this->api('POST', "/api/deals/{$mine->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $this->api('GET', "/api/deals/{$mine->id}/trip", [], 's')->assertOk()->assertJsonPath('confirmed', true);
        $t = TripTask::where('deal_id', $mine->id)->first();
        $this->api('PATCH', "/api/trip-tasks/{$t->id}", ['status' => 'done'], 's')->assertStatus(403);
    }

    public function test_templates_are_editable_by_admin_only_and_new_trips_use_the_edited_version(): void
    {
        $admin = User::factory()->admin()->create();
        $this->loginAs($admin, 'adm');
        $ops = $this->as('operations', 'o');
        $this->api('GET', '/api/trip-templates', [], 'o')->assertStatus(403);
        $list = $this->api('GET', '/api/trip-templates', [], 'adm')->assertOk()->assertJsonCount(3);
        $dom = collect($list->json())->firstWhere('trip_type', 'domestic');

        $items = [['title' => 'Only step', 'category' => 'transport', 'due_days_before' => 10, 'critical' => true], ['title' => 'After the trip', 'category' => 'post_trip', 'due_days_before' => -2]];
        $this->api('PUT', "/api/trip-templates/{$dom['id']}", ['name' => 'Domestic (short)', 'items' => $items], 'o')->assertStatus(403);
        $this->api('PUT', "/api/trip-templates/{$dom['id']}", ['name' => 'Domestic (short)', 'items' => $items], 'adm')->assertOk()->assertJsonCount(2, 'items');
        $this->api('PUT', "/api/trip-templates/{$dom['id']}", ['items' => [['title' => '', 'category' => 'transport']]], 'adm')->assertStatus(422);
        $this->api('PUT', "/api/trip-templates/{$dom['id']}", ['items' => [['title' => 'x', 'category' => 'nonsense']]], 'adm')->assertStatus(422);

        $deal = $this->deal($ops);
        $r = $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $this->assertSame(['Only step', 'After the trip'], collect($r->json('tasks'))->pluck('title')->all());
        $this->assertSame('2026-12-10', $r->json('tasks.0.due_date'));
        $this->assertSame('2026-12-22', $r->json('tasks.1.due_date'));
    }

    public function test_operations_can_undo_a_mistaken_confirmation_and_the_deal_goes_back_to_its_stage(): void
    {
        $ops = $this->as('operations', 'o');
        $sales = User::factory()->sales()->create();
        $deal = $this->deal($ops, ['probability' => 40]);
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();          // confirmed (by someone allowed to)
        $this->assertSame('won', $deal->fresh()->stage);
        $this->assertNotNull($this->api('GET', '/api/trips?scope=upcoming', [], 'o')->assertOk()->json('items.0'));

        // sales cannot undo it; a reason is required
        $this->assertFalse($sales->hasPermission('trips.revert'));
        $this->assertTrue($ops->hasPermission('trips.revert'));
        $this->api('POST', "/api/deals/{$deal->id}/trip/revert", [], 'o')->assertStatus(422);

        $show = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->assertOk()->json();
        $this->assertSame('negotiation', $show['revert']['prev_stage']);
        $r = $this->api('POST', "/api/deals/{$deal->id}/trip/revert", ['reason' => 'Confirmed by mistake', 'restore_deal' => true], 'o')->assertOk();
        $this->assertFalse($r->json('confirmed'));
        $d = $deal->fresh();
        $this->assertSame('negotiation', $d->stage);                                  // back to where it was
        $this->assertSame(40, (int) $d->probability);
        $this->assertNull($d->closed_at);
        $this->assertNull($d->trip_confirm_prev);
        $this->assertSame(0, TripTask::where('deal_id', $deal->id)->count());         // the checklist went
        $this->assertSame([], $this->api('GET', '/api/trips?scope=all', [], 'o')->json('items'));   // and so did the Trips board row
        $this->assertTrue(\App\Models\ActivityLog::where('action', 'revert_trip')->exists());
        $this->api('POST', "/api/deals/{$deal->id}/trip/revert", ['reason' => 'again'], 'o')->assertStatus(409);       // nothing left to undo

        // it can be confirmed again afterwards
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $this->assertGreaterThan(0, TripTask::where('deal_id', $deal->id)->count());
    }

    public function test_undo_keeps_the_deal_won_when_asked_and_warns_about_work_already_done(): void
    {
        $ops = $this->as('operations', 'o');
        $deal = $this->deal($ops);
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        TripTask::where('deal_id', $deal->id)->first()->update(['status' => 'done']);

        $this->api('POST', "/api/deals/{$deal->id}/trip/revert", ['reason' => 'mistake'], 'o')->assertStatus(409);        // work was done: needs force
        $this->assertGreaterThan(0, TripTask::where('deal_id', $deal->id)->count());
        $this->api('POST', "/api/deals/{$deal->id}/trip/revert", ['reason' => 'mistake', 'force' => true], 'o')->assertOk();
        $this->assertSame('won', $deal->fresh()->stage);                                                                 // restore_deal was not asked for
        $this->assertNull($deal->fresh()->trip_confirmed_at);

        // a trip that has already finished cannot be taken back
        $this->api('POST', "/api/deals/{$deal->id}/trip/confirm", ['trip_type' => 'domestic'], 'o')->assertOk();
        $deal->fresh()->forceFill(['trip_status' => 'completed'])->save();
        $this->api('POST', "/api/deals/{$deal->id}/trip/revert", ['reason' => 'not needed', 'force' => true], 'o')->assertStatus(409);
    }
}
