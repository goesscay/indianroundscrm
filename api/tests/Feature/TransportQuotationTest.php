<?php

namespace Tests\Feature;

use App\Models\QuoteSetting;
use App\Models\User;
use App\Services\ProposalPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Transport on the premium quotation (Phase 2): legs priced from the transport master, exchange rates, cab scope, price by mode. */
class TransportQuotationTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    /** The plan's worked example: Volvo Delhi-Manali per person, an Ertiga on a sightseeing route per day, Off Season dated for December. */
    private function manali(): array
    {
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $this->as(User::factory()->operations()->create());
        $volvo = $this->postJson('/api/transport/providers', ['name' => 'Volvo Bus', 'country_code' => 'IN', 'destination' => 'Manali', 'modes' => ['bus']])->assertOk()->json();
        $seat = $this->postJson("/api/transport/providers/{$volvo['id']}/vehicles", ['name' => 'Volvo seat', 'mode' => 'bus', 'seats' => 1])->assertOk()->json();
        $dm = $this->postJson("/api/transport/providers/{$volvo['id']}/routes", ['title' => 'Delhi - Manali', 'kind' => 'intercity'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$volvo['id']}/rates", ['vehicle_id' => $seat['id'], 'route_id' => $dm['id'], 'basis' => 'per_person', 'season' => 'Off Season', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'cost' => 2000, 'child_cost' => 1500])->assertOk();
        $taxi = $this->postJson('/api/transport/providers', ['name' => 'Manali Taxi', 'country_code' => 'IN', 'destination' => 'Manali', 'modes' => ['cab']])->assertOk()->json();
        $ertiga = $this->postJson("/api/transport/providers/{$taxi['id']}/vehicles", ['name' => 'Ertiga', 'mode' => 'cab', 'seats' => 6])->assertOk()->json();
        $local = $this->postJson("/api/transport/providers/{$taxi['id']}/routes", ['title' => 'Local Manali, Solang Valley, Kullu-Naggar', 'kind' => 'sightseeing'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$taxi['id']}/rates", ['vehicle_id' => $ertiga['id'], 'route_id' => $local['id'], 'basis' => 'per_day', 'season' => 'Off Season', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'cost' => 5000])->assertOk();

        return compact('volvo', 'seat', 'dm', 'taxi', 'ertiga', 'local');
    }

    private function proposal(array $legs, array $trip = [], array $extra = []): array
    {
        return $extra + [
            'trip' => $trip + ['destination' => 'Manali', 'start_date' => '2026-12-12', 'nights' => 3, 'adults' => 4, 'group_sizes' => [2, 4, 6]],
            'options' => [['name' => 'Option 1', 'stays' => [], 'legs' => $legs]], 'gst_rate' => 5,
        ];
    }

    private function compute(array $p, ?User $as = null): array
    {
        if ($as) {
            $this->as($as);
        }

        return $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json();
    }

    public function test_the_worked_example_volvo_plus_an_ertiga_prices_as_in_the_plan(): void
    {
        $m = $this->manali();
        $legs = [
            ['id' => 'a', 'mode' => 'bus', 'day_offset' => 0, 'provider_id' => $m['volvo']['id'], 'vehicle_id' => $m['seat']['id'], 'route_id' => $m['dm']['id'], 'legs' => 2],
            ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']],
        ];
        $r = $this->compute($this->proposal($legs));
        $a = $r['computed']['options'][0]['actual'];
        $this->assertEquals(21000, $a['transport']['cost']);                      // 2000 x 4 persons x 2 legs + 5000 x 1 vehicle
        $this->assertEquals(2100, $a['transport']['margin_amount']);
        $this->assertEquals(23100, $a['transport']['selling']);
        $this->assertEquals(23100, $a['total']);
        $this->assertEquals(5775, $a['per_person']);
        $this->assertEquals(['bus' => 17600, 'cab' => 5500], $a['transport']['by_mode']);      // the price by mode, with the margin
        $this->assertSame([], $r['computed']['warnings']);

        $tiers = collect($r['computed']['options'][0]['tiers'])->keyBy('pax');
        $this->assertEquals(2 * 2000 * 2 + 5000, $tiers[2]['transport']['cost']);   // 2 persons, still one Ertiga
        $this->assertEquals(6 * 2000 * 2 + 5000, $tiers[6]['transport']['cost']);   // 6 persons still fit one Ertiga

        $lines = ProposalPricing::quoteLines($r['proposal'], $r['computed']);       // each leg becomes a quote line, so approval and invoicing work as before
        $this->assertCount(2, $lines);
        $this->assertSame('transfer', $lines[0]['kind']);
        $this->assertEquals(17600, $lines[0]['unit_price']);
        $this->assertStringContainsString('Ertiga', $lines[1]['description']);
        $this->assertStringContainsString('Day 2', $lines[1]['description']);
    }

    public function test_a_bigger_group_needs_more_vehicles_and_sales_can_change_the_quantity(): void
    {
        $m = $this->manali();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $r = $this->compute($this->proposal([$cab], ['adults' => 7, 'group_sizes' => [6, 7, 13]]));
        $tiers = collect($r['computed']['options'][0]['tiers'])->keyBy('pax');
        $this->assertEquals(5000 * 1, $tiers[6]['transport']['cost']);
        $this->assertEquals(5000 * 2, $tiers[7]['transport']['cost']);
        $this->assertEquals(5000 * 3, $tiers[13]['transport']['cost']);
        $this->assertSame(2, $r['computed']['options'][0]['actual']['transport']['legs'][0]['qty']);      // 7 adults: two vehicles suggested

        $manual = $this->compute($this->proposal([array_merge($cab, ['qty' => 3])], ['adults' => 4, 'group_sizes' => [2, 4]]));
        $this->assertEquals(15000, $manual['computed']['options'][0]['actual']['transport']['cost']);     // what sales typed is used for the real group
        $this->assertEquals(15000, collect($manual['computed']['options'][0]['tiers'])->keyBy('pax')[2]['transport']['cost']);
    }

    public function test_a_missing_rate_warns_and_is_left_out_so_nothing_is_guessed(): void
    {
        $m = $this->manali();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $r = $this->compute($this->proposal([$cab], ['start_date' => '2027-03-10']));                      // no rate covers March
        $this->assertEquals(0, $r['computed']['options'][0]['actual']['total']);
        $this->assertTrue($r['computed']['options'][0]['actual']['transport']['legs'][0]['rate_needed']);
        $this->assertStringContainsString('Rate needed: Ertiga (Local Manali, Solang Valley, Kullu-Naggar) has no rate for 11 Mar 2027', $r['computed']['warnings'][0]);

        $none = $this->compute($this->proposal([$cab], ['start_date' => null]));
        $this->assertStringContainsString('Set the travel date', implode(' ', $none['computed']['warnings']));
        $half = $this->compute($this->proposal([['id' => 'c', 'mode' => 'cab', 'day_offset' => 0]]));
        $this->assertStringContainsString('choose the service and vehicle', implode(' ', $half['computed']['warnings']));
    }

    public function test_several_days_use_each_days_rate_and_the_season_can_change_midway(): void
    {
        $m = $this->manali();
        $this->postJson("/api/transport/providers/{$m['taxi']['id']}/rates", ['vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id'], 'basis' => 'per_day', 'season' => 'Peak', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31', 'cost' => 8000])->assertOk();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 0, 'days' => 3, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $r = $this->compute($this->proposal([$cab], ['start_date' => '2026-12-30']));                      // 30 Dec, 31 Dec (Off), 1 Jan (Peak)
        $this->assertEquals(5000 + 5000 + 8000, $r['computed']['options'][0]['actual']['transport']['cost']);
        $gap = $this->compute($this->proposal([array_merge($cab, ['days' => 5])], ['start_date' => '2026-12-30']));    // 1-3 Jan are covered, so still priced
        $this->assertEquals(5000 + 5000 + 8000 * 3, $gap['computed']['options'][0]['actual']['transport']['cost']);
        $miss = $this->compute($this->proposal([array_merge($cab, ['days' => 30])], ['start_date' => '2027-01-20']));  // runs past the last season (31 Jan)
        $this->assertEquals(0, $miss['computed']['options'][0]['actual']['transport']['selling']);
        $this->assertStringContainsString('Rate needed', implode(' ', $miss['computed']['warnings']));
    }

    public function test_flights_and_trains_are_typed_in_and_children_can_have_their_own_fare(): void
    {
        $this->as(User::factory()->operations()->create());
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $flight = ['id' => 'f', 'mode' => 'flight', 'day_offset' => 0, 'title' => 'IndiGo 6E 123', 'from' => 'Delhi', 'to' => 'Singapore', 'depart' => '06:30', 'class' => 'Economy', 'baggage' => '15 kg', 'fare_pp' => 5000, 'child_fare_pp' => 4000];
        $train = ['id' => 't', 'mode' => 'train', 'day_offset' => 1, 'title' => 'Rajdhani', 'fare_pp' => 1000];
        $r = $this->compute($this->proposal([$flight, $train], ['adults' => 2, 'children_bed' => 1]));
        $a = $r['computed']['options'][0]['actual'];
        $this->assertEquals(2 * 5000 + 4000 + (2 * 1000 + 1000), $a['transport']['cost']);
        $this->assertEquals(round(17000 * 1.1, 2), $a['transport']['selling']);
        $this->assertEquals(['flight' => 15400, 'train' => 3300], $a['transport']['by_mode']);
        $this->assertStringContainsString('Flight: IndiGo 6E 123', $a['transport']['legs'][0]['description']);
        $lines = ProposalPricing::quoteLines($r['proposal'], $r['computed']);
        $this->assertSame('flight', $lines[0]['kind']);
        $this->assertSame('transfer', $lines[1]['kind']);
        $nofare = $this->compute($this->proposal([['id' => 'x', 'mode' => 'flight']]));
        $this->assertStringContainsString('enter the fare', implode(' ', $nofare['computed']['warnings']));
    }

    public function test_foreign_prices_convert_at_the_rate_held_on_the_quotation(): void
    {
        $this->as(User::factory()->operations()->create());
        QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $p = $this->postJson('/api/transport/providers', ['name' => 'Lion City Cabs', 'country_code' => 'SG', 'destination' => 'Singapore', 'modes' => ['cab']])->assertOk()->json();
        $v = $this->postJson("/api/transport/providers/{$p['id']}/vehicles", ['name' => 'Sedan', 'mode' => 'cab', 'seats' => 4])->assertOk()->json();
        $rt = $this->postJson("/api/transport/providers/{$p['id']}/routes", ['title' => 'Airport to hotel', 'kind' => 'transfer'])->assertOk()->json();
        $this->postJson("/api/transport/providers/{$p['id']}/rates", ['vehicle_id' => $v['id'], 'route_id' => $rt['id'], 'basis' => 'per_transfer', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'cost' => 55])->assertOk();
        $leg = ['id' => 'a', 'mode' => 'cab', 'day_offset' => 0, 'provider_id' => $p['id'], 'vehicle_id' => $v['id'], 'route_id' => $rt['id'], 'legs' => 2];
        $trip = ['destination' => 'Singapore', 'adults' => 2];

        $no = $this->compute($this->proposal([$leg], $trip));
        $this->assertEquals(0, $no['computed']['options'][0]['actual']['total']);
        $this->assertStringContainsString('exchange rate needed for SGD', implode(' ', $no['computed']['warnings']));
        $this->assertSame(['SGD'], $no['computed']['options'][0]['actual']['transport']['fx_needed']);

        $fx = ['fx' => ['date' => '2026-11-20', 'rates' => ['SGD' => 62.5], 'safety_pct' => 0]];
        $r = $this->compute($this->proposal([$leg], $trip, ['transport' => $fx]));
        $a = $r['computed']['options'][0]['actual'];
        $this->assertEquals(55 * 1 * 2 * 62.5, $a['transport']['cost']);                       // SGD 55 x 1 vehicle x return, in rupees
        $this->assertEquals(round(6875 * 1.1, 2), $a['transport']['selling']);
        $this->assertSame('SGD', $a['transport']['legs'][0]['currency']);
        $this->assertSame([], $r['computed']['warnings']);

        $safe = $this->compute($this->proposal([$leg], $trip, ['transport' => ['fx' => array_merge($fx['fx'], ['safety_pct' => 2])]]));      // a 2% safety margin on the rate
        $this->assertEquals(round(6875 * 1.02, 2), $safe['computed']['options'][0]['actual']['transport']['cost']);
        $this->assertEquals(62.5, $safe['proposal']['transport']['fx']['rates']['SGD']);        // the rate itself is kept as typed
    }

    public function test_information_only_legs_show_but_stay_out_of_the_total_and_each_option_has_its_own_transport(): void
    {
        $m = $this->manali();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $p = $this->proposal([array_merge($cab, ['included' => false])]);
        $p['options'][] = ['name' => 'Option 2', 'stays' => [], 'legs' => [$cab]];
        $r = $this->compute($p);
        $o = $r['computed']['options'];
        $this->assertEquals(0, $o[0]['actual']['total']);
        $this->assertEquals(5500, $o[0]['actual']['transport']['legs'][0]['selling']);          // shown for information
        $this->assertFalse($o[0]['actual']['transport']['legs'][0]['included']);
        $this->assertEquals(5500, $o[1]['actual']['total']);
        $this->assertCount(0, ProposalPricing::quoteLines($r['proposal'], $r['computed']));      // option 1 is the quote: nothing included, so no line
    }

    public function test_cab_scope_per_destination_is_kept_and_cleaned(): void
    {
        $this->as(User::factory()->operations()->create());
        $r = $this->compute($this->proposal([], [], ['transport' => ['cab_scope' => ['Manali' => 'daily', 'Goa' => 'stay', 'Bali' => 'weekly', '' => 'stay'], 'fx' => ['rates' => ['inr' => 1, 'sgd' => '62', 'xx' => 5, 'EUR' => -3]]]]));
        $this->assertSame(['Manali' => 'daily', 'Goa' => 'stay'], $r['proposal']['transport']['cab_scope']);
        $this->assertSame(['SGD' => 62], array_map('intval', $r['proposal']['transport']['fx']['rates']));
        $this->assertSame([], $this->compute($this->proposal([]))['proposal']['options'][0]['legs']);
    }

    public function test_sales_see_selling_prices_only_never_cost_or_margin(): void
    {
        $m = $this->manali();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $r = $this->compute($this->proposal([$cab]), User::factory()->sales()->create());
        $a = $r['computed']['options'][0]['actual'];
        $this->assertEquals(5500, $a['transport']['selling']);
        $this->assertArrayNotHasKey('cost', $a);
        $this->assertArrayNotHasKey('margin_amount', $a);
        $this->assertArrayNotHasKey('cost', $a['transport']);
        $this->assertArrayNotHasKey('margin_amount', $a['transport']);
        $this->assertArrayNotHasKey('cost', $a['transport']['legs'][0]);
        $this->assertArrayNotHasKey('margin_amount', $a['transport']['legs'][0]);
        $this->assertArrayNotHasKey('cost', collect($r['computed']['options'][0]['tiers'])->first()['transport']['legs'][0]);
        $this->getJson('/api/transport/providers/'.$m['taxi']['id'])->assertOk()->assertJsonPath('rates', []);          // and the master still hides the vendor rates
    }

    public function test_a_provider_margin_beats_the_destination_default_and_a_fixed_amount_is_per_person(): void
    {
        $m = $this->manali();
        $this->patchJson("/api/transport/providers/{$m['taxi']['id']}", ['margin_type' => 'amount', 'margin_value' => 100])->assertOk();
        $cab = ['id' => 'b', 'mode' => 'cab', 'day_offset' => 1, 'provider_id' => $m['taxi']['id'], 'vehicle_id' => $m['ertiga']['id'], 'route_id' => $m['local']['id']];
        $r = $this->compute($this->proposal([$cab], ['adults' => 4]));
        $this->assertEquals(400, $r['computed']['options'][0]['actual']['transport']['margin_amount']);       // 100 per person x 4, not the 10% default
        $this->assertEquals(5400, $r['computed']['options'][0]['actual']['transport']['selling']);
    }

    public function test_default_exchange_rates_are_kept_by_operations_and_start_each_new_quotation(): void
    {
        $this->as(User::factory()->sales()->create());
        $this->putJson('/api/transport/exchange-rates', ['rates' => ['SGD' => 62]])->assertStatus(403);
        $this->as(User::factory()->operations()->create());
        $this->putJson('/api/transport/exchange-rates', ['date' => '2026-11-20', 'rates' => ['sgd' => 62.4, 'IDR' => 0.0052, 'xx' => 1, 'USD' => 0]])->assertOk()->assertJsonPath('date', '2026-11-20');
        $this->assertEquals(['SGD' => 62.4, 'IDR' => 0.0052], $this->getJson('/api/transport/exchange-rates')->json('rates'));
        $this->as(User::factory()->sales()->create());
        $seed = $this->getJson('/api/quote-proposals/seed?destination=Singapore')->assertOk()->json('proposal');
        $this->assertEquals(62.4, $seed['transport']['fx']['rates']['SGD']);
        $this->assertSame([], $seed['options'][0]['legs']);
    }
}
