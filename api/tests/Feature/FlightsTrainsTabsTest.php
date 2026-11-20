<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteSetting;
use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\TransportSchedule;
use App\Models\User;
use App\Services\ProposalPdf;
use App\Services\ProposalPricing;
use App\Services\TransportReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Flights and Trains tabs of the Transport card: going and return, and the rule that ticking them never changes a price. */
class FlightsTrainsTabsTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function sched(array $o): TransportSchedule
    {
        $all = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return TransportSchedule::create($o + ['kind' => 'flight', 'days' => $all, 'departs' => '09:00', 'arrives' => '10:00', 'classes' => 'Economy']);
    }

    /** the design's lists: a flight to Kullu-Manali and one to Chandigarh, a flight back from Chandigarh, a train to Kalka and a train back from Kalka */
    private function lists(): array
    {
        $week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        return [
            'out1' => $this->sched(['title' => 'Air India AI 123', 'from_name' => 'Delhi', 'from_code' => 'DEL', 'to_name' => 'Kullu-Manali', 'to_code' => 'KUU', 'serves' => 'Manali']),
            'out2' => $this->sched(['title' => 'IndiGo 6E 456', 'from_name' => 'Delhi', 'from_code' => 'DEL', 'to_name' => 'Chandigarh', 'to_code' => 'IXC', 'serves' => 'Manali']),
            'back1' => $this->sched(['title' => 'IndiGo 6E 789', 'from_name' => 'Chandigarh', 'from_code' => 'IXC', 'to_name' => 'Delhi', 'to_code' => 'DEL', 'serves' => 'Manali', 'departs' => '18:30', 'arrives' => '19:35']),
            'train' => $this->sched(['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'from_code' => 'NDLS', 'to_name' => 'Kalka', 'to_code' => 'KLK', 'serves' => 'Manali', 'days' => $week, 'classes' => 'CC, EC']),
            'trainBack' => $this->sched(['kind' => 'train', 'title' => 'Shatabdi 12012', 'from_name' => 'Kalka', 'from_code' => 'KLK', 'to_name' => 'New Delhi', 'to_code' => 'NDLS', 'serves' => 'Manali', 'days' => $week, 'classes' => 'CC, EC']),
        ];
    }

    private function titles(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json())->pluck('title')->sort()->values()->all();
    }

    public function test_going_and_return_show_the_services_that_fit_each_way(): void
    {
        $this->lists();
        $this->as(User::factory()->sales()->create());
        $going = $this->titles('/api/transport/schedules?from=Delhi&to=Manali');
        $this->assertSame(['Air India AI 123', 'IndiGo 6E 456', 'Shatabdi 12011'], $going);        // starts in Delhi, ends at or near Manali
        $this->assertSame(['Shatabdi 12011'], $this->titles('/api/transport/schedules?kind=train&from=Delhi&to=Manali'));     // "Delhi" matches New Delhi
        $this->assertSame(['Air India AI 123', 'IndiGo 6E 456'], $this->titles('/api/transport/schedules?kind=flight&from=Delhi&to=Manali'));

        $back = $this->titles('/api/transport/schedules?from=Manali&to=Delhi&direction=return');
        $this->assertSame(['IndiGo 6E 789', 'Shatabdi 12012'], $back);                             // starts near Manali, ends in Delhi
        $this->assertSame(['IndiGo 6E 789'], $this->titles('/api/transport/schedules?kind=flight&from=Manali&to=Delhi&direction=return'));
        $this->assertSame(['Shatabdi 12012'], $this->titles('/api/transport/schedules?kind=train&from=Manali&to=Delhi&direction=return'));
        $this->assertNotContains('IndiGo 6E 789', $going);                                          // each service shows on its own way only
        $this->assertNotContains('Air India AI 123', $back);
        $this->assertSame([], $this->titles('/api/transport/schedules?from=Manali&to=Delhi'));      // the same boxes without "return" find nothing: nothing starts in Manali
    }

    public function test_the_return_date_filters_by_the_days_each_service_runs(): void
    {
        $this->lists();
        $this->as(User::factory()->sales()->create());
        $this->assertSame(['IndiGo 6E 789', 'Shatabdi 12012'], $this->titles('/api/transport/schedules?from=Manali&to=Delhi&direction=return&date=2026-12-09'));    // a Wednesday
        $this->assertSame(['IndiGo 6E 789'], $this->titles('/api/transport/schedules?from=Manali&to=Delhi&direction=return&date=2026-12-13'));                      // a Sunday: the train does not run
    }

    public function test_suggestions_remember_their_direction_and_the_customer_sees_the_way_there_first(): void
    {
        $item = fn (string $title, ?string $dir) => ['kind' => 'flight', 'title' => $title, 'from_name' => 'A', 'to_name' => 'B', 'direction' => $dir];
        $p = ProposalPricing::clean(['trip' => ['destination' => 'Manali'], 'reach' => ['items' => [$item('Back', 'return'), $item('There', 'going'), $item('Old quote item', null), $item('Odd', 'sideways')]]]);
        $this->assertSame(['return', 'going', 'going', 'going'], array_column($p['reach']['items'], 'direction'));       // an old suggestion without one is "going"
        $r = TransportReach::build($p);
        $this->assertSame(['There', 'Old quote item', 'Odd', 'Back'], array_column($r['items'], 'title'));             // there first, back last
        $this->assertSame(['going', 'going', 'going', 'return'], array_column($r['items'], 'direction'));
    }

    /** The plan's rule: ticking flights and trains, going or return, changes no price, even with road transport on the quotation. */
    public function test_ticking_flights_and_trains_never_changes_a_price_with_road_transport_on_the_quotation(): void
    {
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $d = TransportDestination::create(['name' => 'Manali']);
        $cab = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'name' => 'Ertiga, 6 seats: local', 'charged' => 'per_day', 'price' => 5500, 'currency' => 'INR', 'seats' => 6]);
        $bus = TransportLine::create(['type' => 'bus', 'destination_id' => $d->id, 'name' => 'Volvo: Delhi to Manali', 'charged' => 'per_person', 'price' => 1800, 'currency' => 'INR', 'way' => 'one_way']);
        $l = $this->lists();
        $this->as(User::factory()->operations()->create());
        $base = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 5, 'adults' => 4, 'group_sizes' => [2, 4, 6]], 'gst_rate' => 5,
            'land' => ['label' => 'Cab', 'rows' => [['pax' => 4, 'cost_pp' => 1000]]], 'options' => [['name' => 'Option 1', 'stays' => []]],
            'road' => ['mode' => 'stay', 'stays' => [['line_id' => $cab->id, 'days' => 5]], 'extras' => [['id' => 'x1', 'kind' => 'bus', 'line_id' => $bus->id, 'day' => 1, 'way' => 'return']]]];
        $it = fn (TransportSchedule $s, string $dir) => $s->only(['kind', 'title', 'from_name', 'from_code', 'to_name', 'to_code', 'departs', 'arrives', 'days', 'classes', 'note']) + ['schedule_id' => $s->id, 'direction' => $dir];
        $with = $base + ['reach' => ['from' => 'Delhi', 'items' => [$it($l['out1'], 'going'), $it($l['out2'], 'going'), $it($l['train'], 'going'), $it($l['back1'], 'return'), $it($l['trainBack'], 'return'),
            ['kind' => 'flight', 'title' => 'One-off', 'from_name' => 'Mumbai', 'to_name' => 'Kullu', 'direction' => 'return']]]];
        $a = $this->postJson('/api/quote-proposals/compute', ['proposal' => $base])->assertOk()->json();
        $b = $this->postJson('/api/quote-proposals/compute', ['proposal' => $with])->assertOk()->json();
        $this->assertEquals($a['computed'], $b['computed']);                                        // identical in every group size, every row, every warning
        $this->assertGreaterThan(0, $b['computed']['options'][0]['actual']['road']['selling']);
        $this->assertCount(6, $b['proposal']['reach']['items']);
        $this->assertSame(['going', 'going', 'going', 'return', 'return', 'return'], array_column($b['proposal']['reach']['items'], 'direction'));

        $sales = $this->as(User::factory()->sales()->create());                                     // and through a saved quotation: lines and totals
        $c = Customer::create(['customer_no' => 'IRC-P4', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $q1 = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'proposal' => $base])->assertOk()->json('quote');
        $q2 = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'proposal' => $with])->assertOk()->json('quote');
        $this->assertEquals($q1['total'], $q2['total']);
        $this->assertEquals(array_column($q1['lines'], 'amount'), array_column($q2['lines'], 'amount'));
        $reopened = $this->getJson('/api/quote-proposals/'.$q2['id'])->assertOk()->json();
        $this->assertEquals($q2['total'], $reopened['quote']['total']);                             // saved, reopened: the same price
        $this->assertCount(6, $reopened['proposal']['reach']['items']);

        $html = view('pdf.proposal', ProposalPdf::data(Quote::findOrFail($q2['id'])))->render();
        $this->assertStringContainsString('How to reach Manali: suggestions', $html);
        $this->assertStringContainsString('IndiGo 6E 789', $html);
        $this->assertSame(3, substr_count($html, '>Return<'));                                      // three suggestions are for the way back
    }

    public function test_the_sample_list_has_a_return_flight_that_serves_manali(): void
    {
        $this->artisan('transport:load-schedules-sample')->assertSuccessful();
        $this->as(User::factory()->sales()->create());
        $this->assertContains('IndiGo 6E 789', $this->titles('/api/transport/schedules?from=Manali&to=Delhi&direction=return&kind=flight'));
    }
}
