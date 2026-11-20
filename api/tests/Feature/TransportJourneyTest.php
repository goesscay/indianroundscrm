<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteSetting;
use App\Models\User;
use App\Services\ProposalPdf;
use App\Services\ProposalPricing;
use App\Services\TransportJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** What the customer sees of the transport (Phase 3): the journey per destination, inclusions and notes, tax and currency, PDF and screen. */
class TransportJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    /** The plan's example: Volvo Delhi-Manali per person (return) on day 1, an Ertiga on a sightseeing route on day 2. */
    private function manali(): array
    {
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $this->as(User::factory()->operations()->create());
        $volvo = $this->postJson('/api/transport/providers', ['name' => 'Secret Volvo Co', 'country_code' => 'IN', 'destination' => 'Manali', 'modes' => ['bus']])->assertOk()->json();
        $seat = $this->postJson("/api/transport/providers/{$volvo['id']}/vehicles", ['name' => 'Volvo seat', 'mode' => 'bus', 'seats' => 1])->assertOk()->json();
        $dm = $this->postJson("/api/transport/providers/{$volvo['id']}/routes", ['title' => 'Delhi - Manali', 'kind' => 'intercity', 'inclusions' => 'Pickup and drop at Manali Volvo Bus Stand included'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$volvo['id']}/rates", ['vehicle_id' => $seat['id'], 'route_id' => $dm['id'], 'basis' => 'per_person', 'season' => 'Off Season', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'cost' => 2000])->assertOk();
        $taxi = $this->postJson('/api/transport/providers', ['name' => 'Hidden Taxi Service', 'country_code' => 'IN', 'destination' => 'Manali', 'modes' => ['cab']])->assertOk()->json();
        $ertiga = $this->postJson("/api/transport/providers/{$taxi['id']}/vehicles", ['name' => 'Ertiga', 'mode' => 'cab', 'seats' => 6])->assertOk()->json();
        $local = $this->postJson("/api/transport/providers/{$taxi['id']}/routes", ['title' => 'Local Manali, Solang Valley, Kullu-Naggar', 'kind' => 'sightseeing', 'duration' => 'Full day'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$taxi['id']}/rates", ['vehicle_id' => $ertiga['id'], 'route_id' => $local['id'], 'basis' => 'per_day', 'season' => 'Off Season', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'cost' => 5000])->assertOk();

        return [
            ['id' => 'a', 'mode' => 'bus', 'day_offset' => 0, 'provider_id' => $volvo['id'], 'vehicle_id' => $seat['id'], 'route_id' => $dm['id'], 'legs' => 2],
            ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $taxi['id'], 'vehicle_id' => $ertiga['id'], 'route_id' => $local['id']],
        ];
    }

    private function proposal(array $legs, array $transport = []): array
    {
        return [
            'trip' => ['destination' => 'Manali', 'start_date' => '2026-12-12', 'nights' => 3, 'adults' => 4, 'group_sizes' => [2, 4, 6]],
            'days' => [['title' => 'Arrival', 'bullets' => ['Check in']], ['title' => 'Sightseeing', 'bullets' => ['Solang Valley']]],
            'options' => [['name' => 'Option 1', 'stays' => [], 'legs' => $legs]], 'gst_rate' => 5,
            'content' => ['inclusions' => ['Breakfast daily'], 'exclusions' => ['Lunch']], 'transport' => $transport,
        ];
    }

    private function journey(array $proposal): ?array
    {
        $p = ProposalPricing::clean($proposal);

        return TransportJourney::build($p, ProposalPricing::compute($p, false));
    }

    public function test_the_customer_sees_a_line_per_leg_a_summary_and_the_total_with_gst(): void
    {
        $j = $this->journey($this->proposal($this->manali()));
        $this->assertCount(1, $j['blocks']);
        $this->assertSame('Manali', $j['blocks'][0]['destination']);
        [$bus, $cab] = $j['blocks'][0]['lines'];
        $this->assertSame(['day' => 1, 'date' => '2026-12-12', 'mode' => 'bus', 'label' => 'Volvo bus', 'tag' => 'Included'], array_intersect_key($bus, array_flip(['day', 'date', 'mode', 'label', 'tag'])));
        $this->assertSame('Delhi - Manali', $bus['title']);
        $this->assertSame('4 persons, return', $bus['detail']);
        $this->assertSame('Private Ertiga', $cab['label']);
        $this->assertSame('Local Manali, Solang Valley, Kullu-Naggar', $cab['title']);
        $this->assertSame('2026-12-13', $cab['date']);
        $this->assertStringContainsString('Full day', $cab['detail']);
        $this->assertCount(2, $j['summary']);
        $this->assertEquals(23100, $j['selling']);
        $this->assertEquals(24255, $j['total_incl_gst']);                                  // the plan's example: 23,100 + 5% GST
        $this->assertSame('with_gst', $j['tax_display']);
        $this->assertSame([1 => 1, 2 => 1], array_map('count', $j['by_day']));            // lines are also placed on their own day
    }

    public function test_inclusions_and_notes_follow_from_the_legs_without_repeating_a_line(): void
    {
        $j = $this->journey($this->proposal($this->manali()));
        $this->assertContains('Pickup and drop at Manali Volvo Bus Stand included', $j['inclusions']);
        $this->assertContains('Volvo bus seats Delhi - Manali for all travellers (return)', $j['inclusions']);
        $this->assertContains('Private Ertiga for Day 2 (Local Manali, Solang Valley, Kullu-Naggar)', $j['inclusions']);
        $this->assertSame(['Any route or sightseeing beyond the one listed.'], $j['exclusions']);
        $this->assertContains('Transport rates are valid for the travel dates in this quotation.', $j['notes']);
        $this->assertNotContains('Flight and train fares and timings are indicative and are confirmed at the time of booking.', $j['notes']);
        $merged = TransportJourney::merge(['Breakfast daily', 'pickup and drop at manali volvo bus stand included'], $j['inclusions']);
        $this->assertSame(1, count(array_filter($merged, fn ($x) => stripos($x, 'Pickup and drop') !== false)));      // not repeated, whatever the case
        $this->assertSame('Breakfast daily', $merged[0]);
    }

    public function test_general_wording_hides_vehicle_names_and_vendors_are_never_shown(): void
    {
        $legs = $this->manali();
        $named = json_encode($this->journey($this->proposal($legs)));
        $general = $this->journey($this->proposal($legs, ['wording' => 'general']));
        $g = json_encode($general);
        $this->assertSame(['Bus', 'Private cab'], array_column($general['blocks'][0]['lines'], 'label'));
        $this->assertStringNotContainsString('Ertiga', $g);
        $this->assertStringNotContainsString('Volvo seat', $g);
        foreach ([$named, $g] as $json) {
            foreach (['Secret Volvo Co', 'Hidden Taxi', 'cost', 'margin'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $json);
            }
        }
    }

    public function test_flights_and_trains_show_their_details_and_the_indicative_fare_note(): void
    {
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $legs = [
            ['id' => 'f', 'mode' => 'flight', 'day_offset' => 0, 'title' => 'IndiGo 6E 123', 'from' => 'Delhi', 'to' => 'Kullu', 'depart' => '06:30', 'arrive' => '08:00', 'class' => 'Economy', 'baggage' => '15 kg', 'fare_pp' => 5000],
            ['id' => 'x', 'mode' => 'train', 'day_offset' => 2, 'title' => 'No fare yet'],
        ];
        $j = $this->journey($this->proposal($legs));
        $line = $j['blocks'][0]['lines'][0];
        $this->assertSame('Flight', $line['label']);
        $this->assertSame('IndiGo 6E 123', $line['title']);
        $this->assertSame('Delhi to Kullu, departs 06:30, arrives 08:00, Economy, baggage 15 kg', $line['detail']);
        $this->assertCount(1, $j['blocks'][0]['lines']);                                     // the train without a fare is not shown
        $this->assertContains('Flight and train fares and timings are indicative and are confirmed at the time of booking.', $j['notes']);
        $this->assertContains('Flight: Delhi to Kullu (IndiGo 6E 123)', $j['inclusions']);
    }

    public function test_tax_display_and_the_currency_note(): void
    {
        $this->as(User::factory()->operations()->create());
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $p = $this->postJson('/api/transport/providers', ['name' => 'Lion City Cabs', 'country_code' => 'SG', 'destination' => 'Singapore', 'modes' => ['cab']])->assertOk()->json();
        $v = $this->postJson("/api/transport/providers/{$p['id']}/vehicles", ['name' => 'Sedan', 'mode' => 'cab', 'seats' => 4])->assertOk()->json();
        $rt = $this->postJson("/api/transport/providers/{$p['id']}/routes", ['title' => 'Airport to hotel', 'kind' => 'transfer'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$p['id']}/rates", ['vehicle_id' => $v['id'], 'route_id' => $rt['id'], 'basis' => 'per_transfer', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'cost' => 55])->assertOk();
        $leg = ['id' => 'a', 'mode' => 'cab', 'day_offset' => 0, 'provider_id' => $p['id'], 'vehicle_id' => $v['id'], 'route_id' => $rt['id'], 'legs' => 2];
        $prop = $this->proposal([$leg], ['fx' => ['date' => '2026-11-20', 'rates' => ['SGD' => 62.5]], 'tax_display' => 'before_gst']);
        $prop['trip']['destination'] = 'Singapore';
        $j = $this->journey($prop);
        $this->assertSame('before_gst', $j['tax_display']);
        $this->assertContains('Transport priced in SGD is converted at ₹62.5 per SGD as of 20 Nov 2026, fixed for this quotation.', $j['notes']);
        $this->assertContains('GST of 5% is extra on the transport price.', $j['notes']);
        $this->assertSame('private vehicle, return', $j['blocks'][0]['lines'][0]['detail']);
        $this->assertSame('Singapore', $j['blocks'][0]['destination']);
    }

    public function test_a_leg_without_a_rate_or_a_leg_for_information_only(): void
    {
        $legs = $this->manali();
        $miss = $this->proposal($legs);
        $miss['trip']['start_date'] = '2027-03-10';                                          // no rate covers March
        $this->assertNull($this->journey($miss));                                            // nothing priced, nothing shown
        $info = $legs;
        $info[1]['included'] = false;
        $j = $this->journey($this->proposal($info));
        $this->assertSame(['Included', 'For information'], array_column($j['blocks'][0]['lines'], 'tag'));
        $this->assertEquals(17600, $j['selling']);                                           // only the bus is in the price
        $this->assertNull($this->journey($this->proposal([])));
    }

    public function test_the_saved_quotation_screen_and_pdf_carry_the_journey_and_sales_see_no_vendor(): void
    {
        $legs = $this->manali();
        $sales = $this->as(User::factory()->sales()->create());
        $c = Customer::create(['customer_no' => 'IRC-TJ1', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $saved = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'title' => 'Manali', 'proposal' => $this->proposal($legs)])->assertOk();
        $id = $saved->json('quote.id');

        $show = $this->getJson("/api/quote-proposals/{$id}")->assertOk();
        $this->assertSame('Volvo bus', $show->json('journey.blocks.0.lines.0.label'));
        $this->assertEquals(24255, $show->json('journey.total_incl_gst'));
        foreach (['Secret Volvo Co', 'Hidden Taxi'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $show->getContent());              // vendor names do not reach sales either
        }
        $q = Quote::findOrFail($id);
        $this->assertStringContainsString('Pickup and drop at Manali Volvo Bus Stand included', (string) $q->inclusions);      // the older quote page and PDF
        $this->assertStringContainsString('Breakfast daily', (string) $q->inclusions);

        $html = view('pdf.proposal', ProposalPdf::data($q))->render();
        $this->assertStringContainsString('Your transport', $html);
        $this->assertStringContainsString('Transport: Volvo bus', $html);                    // on day 1
        $this->assertStringContainsString('Private Ertiga', $html);
        $this->assertStringContainsString('Transport total (incl. GST)', $html);
        $this->assertStringContainsString('24,255', $html);
        $this->assertStringContainsString('Pickup and drop at Manali Volvo Bus Stand included', $html);
        $this->assertStringContainsString('Any route or sightseeing beyond the one listed.', $html);
        foreach (['Secret Volvo Co', 'Hidden Taxi'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $html);
        }
        $this->assertStringStartsWith('%PDF', $this->get("/api/quotes/{$id}/pdf")->assertOk()->getContent());

        $plain = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'proposal' => $this->proposal([])])->assertOk()->json('quote.id');
        $this->assertNull($this->getJson("/api/quote-proposals/{$plain}")->json('journey'));
        $this->assertStringNotContainsString('Your transport', view('pdf.proposal', ProposalPdf::data(Quote::findOrFail($plain)))->render());
    }
}
