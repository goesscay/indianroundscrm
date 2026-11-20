<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteSetting;
use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\TransportSupplier;
use App\Models\User;
use App\Services\ProposalPdf;
use App\Services\ProposalPricing;
use App\Services\TransportJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Transport card's Road transport tab: a vehicle for the stay or for each day, plus added transfers and buses, priced from the rate list. */
class TransportRoadTabTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function dest(string $name = 'Manali', string $country = 'India', string $cur = 'INR'): TransportDestination
    {
        return TransportDestination::firstOrCreate(['name' => $name], ['country' => $country, 'currency' => $cur]);
    }

    private function line(array $o = []): TransportLine
    {
        $d = $this->dest($o['destination'] ?? 'Manali');
        unset($o['destination']);

        return TransportLine::create($o + ['type' => 'cab', 'destination_id' => $d->id, 'name' => 'Ertiga, 6 seats: local sightseeing', 'charged' => 'per_day', 'price' => 5500, 'currency' => $d->currency, 'seats' => 6]);
    }

    /** the plan's example: Sat 5 Dec to Thu 10 Dec, 4 adults; an Ertiga at 5,500 a day and a Volvo seat at 1,800 */
    private function fleet(): array
    {
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 0]);
        $sup = TransportSupplier::create(['name' => 'Secret Supplier']);

        return [
            'ertiga' => $this->line(['supplier_id' => $sup->id]),
            'ertigaRohtang' => $this->line(['name' => 'Ertiga, 6 seats: Rohtang side', 'price' => 5500]),
            'ertigaLocal' => $this->line(['name' => 'Ertiga, 6 seats: local sightseeing (5,000)', 'price' => 5000]),
            'innova' => $this->line(['name' => 'Innova Crysta, 6 seats: local sightseeing', 'price' => 6500]),
            'volvo' => $this->line(['type' => 'bus', 'name' => 'Volvo: Delhi to Manali', 'from_name' => 'Delhi', 'to_name' => 'Manali', 'way' => 'one_way', 'charged' => 'per_person', 'price' => 1800, 'seats' => null, 'supplier_id' => $sup->id]),
            'transfer' => $this->line(['name' => 'Sedan: airport to hotel', 'charged' => 'per_trip', 'price' => 900, 'seats' => 4]),
        ];
    }

    private function proposal(array $road, array $trip = [], array $extra = []): array
    {
        $days = array_map(fn ($t) => ['title' => $t, 'bullets' => ['x']], ['Arrival by Volvo', 'Manali local sightseeing', 'Atal Tunnel and Solang', 'Kullu and Naggar', 'Free day', 'Departure']);

        return $extra + ['trip' => $trip + ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 5, 'adults' => 4, 'group_sizes' => [2, 4, 6]], 'days' => $days,
            'options' => [['name' => 'Option 1', 'stays' => []]], 'gst_rate' => 5, 'road' => $road];
    }

    private function compute(array $p, ?User $as = null): array
    {
        $this->as($as ?? User::factory()->operations()->create());

        return $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json();
    }

    private function road(array $r): array
    {
        return $r['computed']['options'][0]['actual']['road'];
    }

    public function test_the_same_vehicle_every_day_and_an_added_volvo_price_as_in_the_plan(): void
    {
        $f = $this->fleet();
        $road = ['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 5]], 'extras' => [['id' => 'x1', 'kind' => 'bus', 'line_id' => $f['volvo']->id, 'day' => 1, 'way' => 'return']]];
        $r = $this->compute($this->proposal($road));
        $rd = $this->road($r);
        $this->assertEquals(27500 + 14400, $rd['selling']);                                       // 5,500 x 5 days + 1,800 x 4 persons x 2 legs
        $this->assertEquals(41900, $r['computed']['options'][0]['actual']['total']);              // Transport price in the card
        $stay = collect($rd['rows'])->firstWhere('source', 'stay');
        $this->assertEquals([5, 1, 5500, 27500], [$stay['days'], $stay['qty'], $stay['unit_selling'], $stay['selling']]);       // 5 days, 1 vehicle (4 fit 6 seats), Rate per day, Amount
        $bus = collect($rd['rows'])->firstWhere('source', 'extra');
        $this->assertEquals([4, 1800, 14400, 'return'], [$bus['qty'], $bus['unit_selling'], $bus['selling'], $bus['way']]);     // 4 persons x 2, 1,800, 14,400
        $this->assertEquals(['cab' => 27500, 'bus' => 14400], $rd['by_mode']);
        $this->assertSame([], $r['computed']['warnings']);
    }

    public function test_the_number_of_days_comes_from_the_itinerary_unless_changed(): void
    {
        $f = $this->fleet();
        $auto = $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id]]])));
        $this->assertSame(6, $auto['rows'][0]['days']);                                           // the six itinerary days
        $this->assertEquals(33000, $auto['selling']);
        $this->assertEquals(11000, $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 2]]])))['selling']);
        $noItin = $this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id]]]);
        $noItin['days'] = [];
        $this->assertSame(6, $this->road($this->compute($noItin))['rows'][0]['days']);            // no itinerary yet: the nights plus one
        $late = $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'from_day' => 3]]])));
        $this->assertSame([3, 4], [$late['rows'][0]['day'], $late['rows'][0]['days']]);           // starting on day 3 covers the days left
    }

    public function test_choosing_a_vehicle_for_each_day_and_switching_keeps_the_added_transport(): void
    {
        $f = $this->fleet();
        $extras = [['id' => 'x1', 'kind' => 'bus', 'line_id' => $f['volvo']->id, 'day' => 1, 'way' => 'return']];
        $daily = ['mode' => 'daily', 'extras' => $extras, 'daily' => [
            ['day' => 1, 'line_id' => null],                                                      // arrival by Volvo: no vehicle
            ['day' => 2, 'line_id' => $f['ertigaLocal']->id], ['day' => 3, 'line_id' => $f['ertigaRohtang']->id], ['day' => 4, 'line_id' => $f['innova']->id], ['day' => 5, 'line_id' => $f['ertigaLocal']->id]]];
        $r = $this->road($this->compute($this->proposal($daily)));
        $this->assertEquals(5000 + 5500 + 6500 + 5000 + 14400, $r['selling']);                    // 36,400, as in the plan
        $this->assertCount(5, $r['rows']);
        $this->assertSame([2, 3, 4, 5], array_column(array_filter($r['rows'], fn ($x) => $x['source'] === 'daily'), 'day') ?: []);

        $both = $this->proposal(['mode' => 'stay', 'extras' => $extras, 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 5]], 'daily' => $daily['daily']]);
        $this->assertEquals(41900, $this->road($this->compute($both))['selling']);                // the stay vehicle counts, the day-by-day choices do not
        $both['road']['mode'] = 'daily';
        $this->assertEquals(36400, $this->road($this->compute($both))['selling']);               // switching back: each side kept, the Volvo counts in both
    }

    public function test_seasons_inside_a_line_and_the_rate_needed_rules(): void
    {
        $f = $this->fleet();
        $peak = $this->line(['name' => 'Seasonal cab', 'price' => 4000, 'season_name' => 'Off season', 'valid_from' => '2026-11-01', 'valid_to' => '2026-12-14']);
        $peak->seasons()->create(['name' => 'Peak', 'valid_from' => '2026-12-15', 'valid_to' => '2027-01-10', 'price' => 9000]);
        $peak->seasons()->create(['name' => 'Undated', 'price' => 1]);                              // a season without dates is not used
        $stay = fn (array $trip) => $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $peak->id, 'days' => 2]]], $trip)));

        $this->assertEquals(8000, $stay(['start_date' => '2026-12-05'])['selling']);               // off season both days
        $this->assertEquals(4000 + 9000, $stay(['start_date' => '2026-12-14'])['selling']);        // the season changes between the two days
        $this->assertEquals(18000, $stay(['start_date' => '2026-12-20'])['selling']);
        $out = $stay(['start_date' => '2027-03-01']);                                              // after every season
        $this->assertEquals(0, $out['selling']);
        $this->assertTrue($out['rows'][0]['rate_needed']);
        $this->assertStringContainsString('Rate needed: Seasonal cab has no rate for 1 Mar 2027.', $out['warnings'] ?? $out['rows'][0]['warnings'][0]);
        $this->assertStringContainsString('Set the travel date', $stay(['start_date' => null])['rows'][0]['warnings'][0]);                 // a dated price needs a date

        $allYear = $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 2]]], ['start_date' => null])));
        $this->assertEquals(11000, $allYear['selling']);                                           // a price with no dates applies all year, even without a travel date
    }

    public function test_vehicles_follow_the_group_and_sales_may_change_the_number_for_the_real_group_only(): void
    {
        $f = $this->fleet();
        $road = ['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 1]]];
        $r = $this->compute($this->proposal($road, ['adults' => 7, 'group_sizes' => [6, 7, 13]]));
        $tiers = collect($r['computed']['options'][0]['tiers'])->keyBy('pax');
        $this->assertEquals([5500, 11000, 16500], [$tiers[6]['road']['selling'], $tiers[7]['road']['selling'], $tiers[13]['road']['selling']]);
        $this->assertSame(2, $this->road($r)['rows'][0]['qty']);                                   // 7 travellers: two vehicles suggested

        $road['stays'][0]['qty'] = 3;
        $m = $this->compute($this->proposal($road, ['adults' => 4, 'group_sizes' => [2, 4]]));
        $this->assertEquals(16500, $this->road($m)['selling']);                                    // typed 3 is used as it is for the real group
        $this->assertEquals(16500, collect($m['computed']['options'][0]['tiers'])->keyBy('pax')[2]['road']['selling']);
    }

    public function test_buses_per_person_and_transfers_per_trip_with_one_way_and_return(): void
    {
        $f = $this->fleet();
        $x = fn (string $kind, TransportLine $l, string $way) => ['kind' => $kind, 'line_id' => $l->id, 'day' => 1, 'way' => $way];
        $price = fn (array $extras) => $this->road($this->compute($this->proposal(['mode' => 'stay', 'extras' => $extras])))['selling'];
        $this->assertEquals(7200, $price([$x('bus', $f['volvo'], 'one_way')]));                    // 1,800 x 4 persons
        $this->assertEquals(14400, $price([$x('bus', $f['volvo'], 'return')]));                    // return on a one-way price: twice
        $retPrice = $this->line(['type' => 'bus', 'name' => 'Volvo return fare', 'way' => 'return', 'charged' => 'per_person', 'price' => 3000, 'seats' => null]);
        $this->assertEquals(12000, $price([$x('bus', $retPrice, 'return')]));                      // a return price is paid once
        $this->assertEquals(900, $price([$x('transfer', $f['transfer'], 'one_way')]));             // one sedan seats the four
        $this->assertEquals(1800, $price([$x('transfer', $f['transfer'], 'return')]));
        $big = $this->road($this->compute($this->proposal(['mode' => 'stay', 'extras' => [$x('transfer', $f['transfer'], 'one_way')]], ['adults' => 9])));
        $this->assertEquals(2700, $big['selling']);                                                // nine travellers: three sedans
        $this->assertSame(['transfer', 'bus', 'ferry', 'vehicle'], array_keys(\App\Services\RoadPricing::KINDS));
    }

    public function test_the_margin_is_added_and_foreign_prices_convert_at_the_quotation_rate(): void
    {
        $f = $this->fleet();
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $r = $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 2]]])));
        $this->assertEquals([11000, 1100, 12100], [$r['cost'], $r['margin_amount'], $r['selling']]);
        QuoteSetting::write('margin_destinations', ['Manali' => ['type' => 'amount', 'value' => 250]]);
        $this->assertEquals(11000 + 4 * 250, $this->road($this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 2]]])))['selling']);   // per person, not 10%

        QuoteSetting::write('margin_destinations', []);
        $sg = $this->dest('Singapore', 'Singapore', 'SGD');
        $mpv = TransportLine::create(['type' => 'cab', 'destination_id' => $sg->id, 'name' => 'MPV, 6 seats: airport to hotel', 'charged' => 'per_trip', 'price' => 60, 'currency' => 'SGD', 'seats' => 6]);
        $road = ['mode' => 'stay', 'stays' => [['line_id' => null]], 'extras' => [['id' => 'a', 'kind' => 'transfer', 'line_id' => $mpv->id, 'day' => 1, 'way' => 'return']]];
        $no = $this->compute($this->proposal($road, ['destination' => 'Singapore']));
        $this->assertEquals(0, $this->road($no)['selling']);
        $this->assertSame(['SGD'], $this->road($no)['fx_needed']);
        $this->assertStringContainsString('Exchange rate needed for SGD', implode(' ', $no['computed']['warnings']));
        $with = $this->compute($this->proposal($road, ['destination' => 'Singapore'], ['transport' => ['fx' => ['date' => '2026-11-20', 'rates' => ['SGD' => 62.5]]]]));
        $this->assertEquals(round(60 * 2 * 62.5 * 1.1, 2), $this->road($with)['selling']);          // SGD 60 x 2 legs x 62.5 + 10% margin
        $this->assertSame('SGD', $this->road($with)['rows'][0]['currency']);
    }

    public function test_sales_pick_without_seeing_prices_and_see_only_selling_figures(): void
    {
        $f = $this->fleet();
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $this->as(User::factory()->sales()->create());
        $opts = $this->getJson('/api/transport/road-options?destination=Manali')->assertOk()->json();
        $this->assertCount(6, $opts);
        foreach ($opts as $o) {
            $this->assertEqualsCanonicalizing(['id', 'type', 'car_name', 'name', 'charged', 'seats', 'from_name', 'to_name', 'way', 'destination'], array_keys($o));       // no price, supplier or note
        }
        $this->assertStringNotContainsString('Secret Supplier', json_encode($opts));
        $this->getJson('/api/transport/lines')->assertStatus(403);                                  // the rate list itself stays with Operations
        $this->assertCount(0, $this->getJson('/api/transport/road-options?destination=Goa')->json());

        $r = $this->compute($this->proposal(['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 2]]]), User::factory()->sales()->create());
        $rd = $this->road($r);
        $this->assertEquals(12100, $rd['selling']);
        $this->assertArrayNotHasKey('cost', $rd);
        $this->assertArrayNotHasKey('margin_amount', $rd);
        $this->assertArrayNotHasKey('cost', $rd['rows'][0]);
        $this->assertArrayNotHasKey('margin_amount', $rd['rows'][0]);
        $this->assertArrayNotHasKey('cost', collect($r['computed']['options'][0]['tiers'])->first()['road']['rows'][0] ?? []);
        $this->assertStringNotContainsString('Secret Supplier', json_encode($r));
    }

    public function test_the_road_plan_is_cleaned_and_always_has_a_stay_block(): void
    {
        $p = ProposalPricing::clean($this->proposal(['mode' => 'weekly', 'stays' => [], 'extras' => [['kind' => 'plane', 'line_id' => 1], ['kind' => 'ferry', 'line_id' => 'x', 'qty' => 999, 'day' => 200], ['kind' => 'bus', 'line_id' => '5', 'way' => 'sideways']]]));
        $this->assertSame('stay', $p['road']['mode']);
        $this->assertCount(1, $p['road']['stays']);
        $this->assertSame('Manali', $p['road']['stays'][0]['destination']);
        $this->assertNull($p['road']['stays'][0]['line_id']);
        $this->assertCount(2, $p['road']['extras']);                                                // the unknown kind is dropped
        $this->assertSame([null, 50, 90, 'one_way'], [$p['road']['extras'][0]['line_id'], $p['road']['extras'][0]['qty'], $p['road']['extras'][0]['day'], $p['road']['extras'][0]['way']]);
        $this->assertSame(['bus', 5, 'one_way'], [$p['road']['extras'][1]['kind'], $p['road']['extras'][1]['line_id'], $p['road']['extras'][1]['way']]);
        $this->assertSame('stay', ProposalPricing::clean(['trip' => ['destination' => 'X']])['road']['mode']);                            // an old quotation has no road plan
    }

    public function test_a_quotation_saved_with_a_road_plan_keeps_its_lines_and_total_and_the_customer_sees_the_journey(): void
    {
        $f = $this->fleet();
        $sales = $this->as(User::factory()->sales()->create());
        $c = Customer::create(['customer_no' => 'IRC-RD1', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $road = ['mode' => 'stay', 'stays' => [['line_id' => $f['ertiga']->id, 'days' => 5]], 'extras' => [['id' => 'x1', 'kind' => 'bus', 'line_id' => $f['volvo']->id, 'day' => 1, 'way' => 'return']]];
        $saved = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'title' => 'Manali', 'proposal' => $this->proposal($road)])->assertOk();
        $id = $saved->json('quote.id');
        $lines = collect($saved->json('quote.lines'));
        $this->assertEquals(41900, $lines->sum('amount'));                                          // the road lines carry the price (before GST)
        $this->assertTrue($lines->contains(fn ($l) => str_contains($l['description'], 'Ertiga, 6 seats: local sightseeing, 5 days')));
        $this->assertTrue($lines->contains(fn ($l) => str_contains($l['description'], 'Volvo: Delhi to Manali, 4 persons, return')));

        $show = $this->getJson("/api/quote-proposals/{$id}")->assertOk();                           // reopen: the same plan, the same price
        $this->assertSame('stay', $show->json('proposal.road.mode'));
        $this->assertEquals(41900, $show->json('computed.options.0.actual.road.selling'));
        $j = $show->json('journey');
        $this->assertSame('Manali', $j['blocks'][0]['destination']);
        $by = collect($j['blocks'][0]['lines'])->keyBy('label');
        $this->assertEqualsCanonicalizing(['Volvo bus', 'Private Ertiga'], array_keys($by->all()));
        $this->assertSame('Delhi to Manali', $by['Volvo bus']['title']);
        $this->assertSame('4 persons, return', $by['Volvo bus']['detail']);
        $this->assertSame('local sightseeing', $by['Private Ertiga']['title']);
        $this->assertEquals(round(41900 * 1.05), $j['total_incl_gst']);
        $this->assertContains('Private Ertiga for Days 1 to 5 (local sightseeing)', $j['inclusions']);
        $this->assertContains('Volvo bus seats Delhi to Manali for all travellers (return)', $j['inclusions']);
        $this->assertStringNotContainsString('Secret Supplier', $show->getContent());

        $html = view('pdf.proposal', ProposalPdf::data(Quote::findOrFail($id)))->render();
        $this->assertStringContainsString('Your transport', $html);
        $this->assertStringContainsString('Private Ertiga', $html);
        $this->assertStringContainsString('Volvo bus', $html);
        $this->assertStringNotContainsString('Secret Supplier', $html);
        $this->assertStringStartsWith('%PDF', $this->get("/api/quotes/{$id}/pdf")->assertOk()->getContent());

        $g = TransportJourney::build(ProposalPricing::clean($this->proposal($road, [], ['transport' => ['wording' => 'general']])), ProposalPricing::compute(ProposalPricing::clean($this->proposal($road, [], ['transport' => ['wording' => 'general']])), false));
        $this->assertEqualsCanonicalizing(['Bus', 'Private cab'], array_column($g['blocks'][0]['lines'], 'label'));      // general wording: no vehicle names
        $this->assertStringNotContainsString('Ertiga', json_encode($g['blocks']));
    }

    public function test_an_old_quotation_without_a_road_plan_prices_exactly_the_same_and_road_adds_to_earlier_legs(): void
    {
        $this->fleet();
        $old = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 3, 'adults' => 4, 'group_sizes' => [2, 4]], 'gst_rate' => 5,
            'land' => ['label' => 'Cab', 'rows' => [['pax' => 4, 'cost_pp' => 3000]]], 'options' => [['name' => 'Option 1', 'stays' => []]]];
        $a = $this->compute($old);
        $this->assertEquals(12000, $a['computed']['options'][0]['actual']['total']);                // land package only: unchanged
        $this->assertEquals(0, $this->road($a)['selling']);
        $this->assertSame('stay', $a['proposal']['road']['mode']);                                  // and it opens, with an empty road plan
        $withEmpty = $this->compute($old + ['road' => ['mode' => 'daily', 'daily' => [['day' => 1, 'line_id' => null]]]]);
        $this->assertEquals($a['computed']['options'][0]['actual'], $withEmpty['computed']['options'][0]['actual']);

        $f = $this->line(['name' => 'Extra cab', 'price' => 1000]);
        $both = $this->compute($old + ['road' => ['mode' => 'stay', 'stays' => [['line_id' => $f->id, 'days' => 2]]]]);
        $this->assertEquals(12000 + 2000, $both['computed']['options'][0]['actual']['total']);       // road transport adds to whatever else the quotation prices
    }
}
