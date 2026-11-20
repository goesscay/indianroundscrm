<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\TransportSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Operations -> Transport -> Rates as one list: nothing to set up first, four required fields, optional extras, seasons inside a line, import with a check. */
class TransportRatesListTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function add(array $over = []): array
    {
        return $this->postJson('/api/transport/lines', $over + ['type' => 'cab', 'destination' => 'Manali', 'name' => 'Ertiga, 6 seats: local sightseeing', 'price' => 5000, 'charged' => 'per_day'])->assertOk()->json();
    }

    public function test_the_first_rate_can_be_saved_on_day_one_with_only_four_fields_and_nothing_set_up(): void
    {
        $this->as(User::factory()->operations()->create());
        $this->assertSame(0, TransportDestination::count());                                   // no country, place or provider exists
        $l = $this->add();
        $this->assertSame('cab', $l['type']);
        $this->assertSame('Manali', $l['destination']['name']);
        $this->assertSame('India', $l['destination']['country']);                               // country and currency come with the destination
        $this->assertSame('INR', $l['currency']);
        $this->assertSame(5000, $l['price']);
        $this->assertNull($l['supplier']);
        $this->assertFalse($l['needs_price']);

        foreach (['type', 'destination', 'name', 'price', 'charged'] as $missing) {
            $body = ['type' => 'cab', 'destination' => 'Manali', 'name' => 'X', 'price' => 1, 'charged' => 'per_day'];
            unset($body[$missing]);
            $this->postJson('/api/transport/lines', $body)->assertStatus(422);
        }
        $this->postJson('/api/transport/lines', ['type' => 'train', 'destination' => 'Manali', 'name' => 'X', 'price' => 1, 'charged' => 'per_day'])->assertStatus(422);    // trains are details only, not rates
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'name' => 'X', 'price' => 1, 'charged' => 'per_km'])->assertStatus(422);
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'name' => 'X', 'price' => -5, 'charged' => 'per_day'])->assertStatus(422);
    }

    public function test_a_new_foreign_destination_brings_its_country_and_currency_and_later_rates_reuse_it(): void
    {
        $this->as(User::factory()->operations()->create());
        $sg = $this->add(['destination' => 'Singapore', 'country' => 'Singapore', 'dest_currency' => 'sgd', 'name' => 'MPV: airport to hotel', 'charged' => 'per_trip', 'price' => 60]);
        $this->assertSame('SGD', $sg['currency']);
        $this->assertSame('Singapore', $sg['destination']['country']);
        $again = $this->add(['destination' => 'singapore', 'name' => 'Sedan: airport to hotel', 'charged' => 'per_trip', 'price' => 45]);       // typed in another case: the same destination
        $this->assertSame('SGD', $again['currency']);
        $this->assertSame($sg['destination']['id'], $again['destination']['id']);
        $this->assertSame(1, TransportDestination::count());
        $changed = $this->add(['destination' => 'Singapore', 'name' => 'Tour', 'price' => 300, 'currency' => 'USD']);                              // a rate may differ from its destination
        $this->assertSame('USD', $changed['currency']);
    }

    public function test_suppliers_appear_by_themselves_and_destinations_and_places_are_suggested_from_hotels_and_earlier_rates(): void
    {
        $this->as(User::factory()->operations()->create());
        Hotel::create(['name' => 'Mareema', 'destination' => 'Goa']);
        $a = $this->add(['supplier' => 'Manali Taxi Service']);
        $this->add(['name' => 'Innova', 'supplier' => 'manali taxi service']);                  // the same supplier, typed differently
        $this->add(['type' => 'bus', 'name' => 'Volvo', 'from_name' => 'Delhi', 'to_name' => 'Manali', 'charged' => 'per_person', 'price' => 1800, 'supplier' => 'Volvo desk']);
        $this->assertSame(2, TransportSupplier::count());
        $this->assertSame('Manali Taxi Service', $a['supplier']);

        $look = $this->getJson('/api/transport/lines/lookups')->assertOk()->json();
        $names = array_column($look['destinations'], 'name');
        $this->assertSame(['Goa', 'Manali'], $names);                                              // Goa is only in Hotels so far
        $this->assertTrue(collect($look['destinations'])->firstWhere('name', 'Manali')['known']);
        $this->assertFalse(collect($look['destinations'])->firstWhere('name', 'Goa')['known']);
        $this->assertSame(3, collect($look['destinations'])->firstWhere('name', 'Manali')['rates']);
        $this->assertEqualsCanonicalizing(['Delhi', 'Manali'], array_column($look['places'], 'name'));
        $this->assertSame([['name' => 'Manali Taxi Service', 'rates' => 2], ['name' => 'Volvo desk', 'rates' => 1]], $look['suppliers']);
    }

    public function test_optional_details_edit_duplicate_delete_and_the_list_filters(): void
    {
        $this->as(User::factory()->operations()->create());
        $l = $this->add(['seats' => 6, 'hours_per_day' => 8, 'km_included' => 80, 'extra_hour' => 500, 'note' => 'Toll and parking extra', 'supplier' => 'Manali Taxi Service', 'season_name' => 'Off season', 'valid_from' => '2026-11-01', 'valid_to' => '2026-12-14']);
        $this->assertSame(6, $l['seats']);
        $this->assertSame('2026-12-14', $l['valid_to']);
        $this->patchJson("/api/transport/lines/{$l['id']}", ['price' => 5500, 'valid_from' => '2026-12-20', 'valid_to' => '2026-12-10'])->assertStatus(422);       // end before start
        $this->patchJson("/api/transport/lines/{$l['id']}", ['valid_from' => '2026-12-20'])->assertStatus(422);                                              // one date only
        $this->patchJson("/api/transport/lines/{$l['id']}", ['price' => 5500, 'destination' => 'Kullu', 'supplier' => null])->assertOk()
            ->assertJsonPath('price', 5500)->assertJsonPath('destination.name', 'Kullu')->assertJsonPath('supplier', null);

        $copy = $this->postJson("/api/transport/lines/{$l['id']}/duplicate")->assertOk()->json();
        $this->assertSame('Ertiga, 6 seats: local sightseeing (copy)', $copy['name']);
        $this->assertSame(5500, $copy['price']);
        $this->add(['type' => 'bus', 'name' => 'Volvo', 'charged' => 'per_person', 'price' => 1800, 'destination' => 'Manali']);

        $this->assertCount(3, $this->getJson('/api/transport/lines')->json());
        $this->assertCount(2, $this->getJson('/api/transport/lines?type=cab')->json());
        $this->assertCount(1, $this->getJson('/api/transport/lines?type=bus')->json());
        $this->assertCount(2, $this->getJson('/api/transport/lines?destination=kullu')->json());
        $this->assertCount(1, $this->getJson('/api/transport/lines?q=volvo')->json());
        $this->assertCount(2, $this->getJson('/api/transport/lines?q='.urlencode('Kullu'))->json());                                                          // search finds the destination too
        $this->deleteJson("/api/transport/lines/{$copy['id']}")->assertOk();
        $this->patchJson("/api/transport/lines/{$l['id']}", ['active' => false])->assertOk();
        $this->assertCount(1, $this->getJson('/api/transport/lines')->json());
        $this->assertCount(2, $this->getJson('/api/transport/lines?active=0')->json());
    }

    public function test_seasons_sit_inside_the_line_and_may_not_overlap(): void
    {
        $this->as(User::factory()->operations()->create());
        $l = $this->add(['season_name' => 'Off season', 'valid_from' => '2027-01-01', 'valid_to' => '2027-03-14']);
        $with = $this->postJson("/api/transport/lines/{$l['id']}/seasons", ['name' => 'Mid season', 'valid_from' => '2027-03-15', 'valid_to' => '2027-05-14', 'price' => 5500])->assertOk()->json();
        $this->assertCount(1, $with['seasons']);
        $this->assertFalse($with['seasons'][0]['dates_needed']);
        $this->postJson("/api/transport/lines/{$l['id']}/seasons", ['name' => 'Clash', 'valid_from' => '2027-03-01', 'valid_to' => '2027-03-20', 'price' => 1])->assertStatus(400);      // overlaps both
        $this->postJson("/api/transport/lines/{$l['id']}/seasons", ['name' => 'Half', 'valid_from' => '2027-06-01', 'price' => 1])->assertStatus(422);
        $on = $this->postJson("/api/transport/lines/{$l['id']}/seasons", ['name' => 'On season', 'price' => 6500])->assertOk()->json();                                     // dates still to come
        $this->assertTrue(collect($on['seasons'])->firstWhere('name', 'On season')['dates_needed']);
        $sid = collect($on['seasons'])->firstWhere('name', 'On season')['id'];
        $this->patchJson("/api/transport/line-seasons/{$sid}", ['valid_from' => '2027-05-15', 'valid_to' => '2027-06-30'])->assertOk();
        $this->patchJson("/api/transport/line-seasons/{$sid}", ['valid_from' => '2027-03-20', 'valid_to' => '2027-06-30'])->assertStatus(400);
        $this->assertCount(2, $this->deleteJson("/api/transport/line-seasons/{$with['seasons'][0]['id']}")->assertOk()->json('seasons') ? [1, 2] : []);
        $copy = $this->postJson("/api/transport/lines/{$l['id']}/duplicate")->json();
        $this->assertCount(1, $copy['seasons']);                                                  // a copy keeps the seasons
    }

    public function test_needs_a_price_prices_can_move_by_a_percentage_with_a_preview_first(): void
    {
        $this->as(User::factory()->operations()->create());
        $a = $this->add(['price' => 5000]);
        $b = $this->add(['name' => 'Innova', 'price' => 6500]);
        $this->postJson("/api/transport/lines/{$b['id']}/seasons", ['name' => 'Peak', 'valid_from' => '2026-12-20', 'valid_to' => '2027-01-05', 'price' => 8000])->assertOk();
        TransportLine::where('id', $a['id'])->update(['price' => null]);
        $this->assertSame([$a['id']], array_column($this->getJson('/api/transport/lines?needs_price=1')->json(), 'id'));
        TransportLine::where('id', $a['id'])->update(['price' => 5000]);
        $this->assertCount(0, $this->getJson('/api/transport/lines?needs_price=1')->json());

        $ids = [(int) $a['id'], (int) $b['id']];
        $pv = $this->postJson('/api/transport/lines/bulk-price', ['ids' => $ids, 'direction' => 'increase', 'percent' => 10, 'preview' => true])->assertOk()->json();
        $this->assertTrue($pv['preview']);
        $row = collect($pv['changes'])->firstWhere('id', $b['id']);
        $this->assertEquals([6500, 7150], [$row['old'], $row['new']]);
        $this->assertEquals([8000, 8800], [$row['seasons'][0]['old'], $row['seasons'][0]['new']]);
        $this->assertEquals(5000, TransportLine::find($a['id'])->price);                                  // a preview changes nothing

        $this->postJson('/api/transport/lines/bulk-price', ['ids' => $ids, 'direction' => 'increase', 'percent' => 10, 'preview' => false])->assertOk();
        $this->assertEquals(5500, TransportLine::find($a['id'])->price);
        $this->assertEquals(7150, TransportLine::find($b['id'])->price);
        $this->assertEquals(8800, TransportLine::find($b['id'])->seasons()->first()->price);               // seasons move with the line
        $this->postJson('/api/transport/lines/bulk-price', ['ids' => $ids, 'direction' => 'decrease', 'percent' => 10, 'preview' => false])->assertOk();
        $this->assertEquals(4950, TransportLine::find($a['id'])->price);
        $this->postJson('/api/transport/lines/bulk-price', ['ids' => $ids, 'direction' => 'decrease', 'percent' => 100])->assertStatus(422);
        $this->postJson('/api/transport/lines/bulk-price', ['ids' => [], 'direction' => 'increase', 'percent' => 5])->assertStatus(422);
    }

    public function test_only_operations_reach_the_rates_and_sales_do_not(): void
    {
        $this->as(User::factory()->operations()->create());
        $l = $this->add();
        $this->as(User::factory()->sales()->create());
        $this->getJson('/api/transport/lines')->assertStatus(403);                                 // prices and suppliers are not for sales
        $this->getJson('/api/transport/lines/lookups')->assertStatus(403);
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'X', 'name' => 'X', 'price' => 1, 'charged' => 'per_day'])->assertStatus(403);
        $this->patchJson("/api/transport/lines/{$l['id']}", ['price' => 1])->assertStatus(403);
        $this->deleteJson("/api/transport/lines/{$l['id']}")->assertStatus(403);
        $this->post('/api/transport/lines/import', [], ['Accept' => 'application/json'])->assertStatus(403);
    }

    private function csv(array $rows, array $extraHead = []): UploadedFile
    {
        $head = ['type', 'destination', 'country', 'currency', 'service', 'from', 'to', 'way', 'charged', 'price', 'season', 'valid_from', 'valid_to', 'supplier', 'seats', 'hours_per_day', 'km_included', 'extra_hour', 'note'];
        $body = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.$c.'"', $r)), array_merge([$head], $rows)))."\n";
        $path = tempnam(sys_get_temp_dir(), 'tl').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'rates.csv', 'text/csv', null, true);
    }

    private function row(array $o): array
    {
        return array_values(array_merge(['type' => 'cab', 'destination' => 'Manali', 'country' => '', 'currency' => '', 'service' => 'Ertiga: local sightseeing', 'from' => '', 'to' => '', 'way' => '', 'charged' => 'per_day', 'price' => '5000',
            'season' => '', 'valid_from' => '', 'valid_to' => '', 'supplier' => '', 'seats' => '', 'hours_per_day' => '', 'km_included' => '', 'extra_hour' => '', 'note' => ''], $o));       // in the sheet's column order
    }

    public function test_an_imported_sheet_shows_ready_check_and_missing_before_anything_is_saved(): void
    {
        $this->as(User::factory()->operations()->create());
        $this->add(['name' => 'Ertiga: Rohtang side', 'price' => 5500]);                          // Manali is known
        $sheet = [
            $this->row(['service' => 'Ertiga: local sightseeing', 'price' => '5,000', 'supplier' => 'Manali Taxi Service']),                          // ready
            $this->row(['type' => 'taxi', 'service' => 'Innova Crysta: local', 'price' => '6500']),                                                   // "taxi" understood as a cab
            $this->row(['type' => 'bus', 'service' => 'Volvo: Delhi to Manali', 'from' => 'Delhi', 'to' => 'Manali', 'charged' => 'per person', 'price' => '1800']),
            $this->row(['service' => 'Dzire: Manali to Kullu', 'charged' => '', 'price' => '3200']),                                                  // check: charged how?
            $this->row(['type' => 'bus', 'service' => 'Volvo: Chandigarh to Manali', 'charged' => 'per_person', 'price' => '']),                       // missing: no price
            $this->row(['type' => 'plane', 'service' => 'Nope']),                                                                                       // missing: not a transport type
            $this->row(['destination' => 'Singapore', 'service' => 'MPV airport', 'charged' => 'per_trip', 'price' => '60']),                           // check: a new destination
            $this->row(['destination' => 'Dubai', 'country' => 'UAE', 'currency' => 'aed', 'service' => 'Car with driver', 'price' => '400']),           // ready: the sheet names its country and currency
        ];
        $dry = $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(['ready' => 4, 'check' => 2, 'missing' => 2], $dry['summary']);
        $by = collect($dry['rows'])->keyBy('row');
        $this->assertSame('Ready', $by[2]['status']);
        $this->assertSame('cab', $by[3]['type']);
        $this->assertSame('per_person', $by[4]['charged']);
        $this->assertSame(['Check', 'charged'], [$by[5]['status'], $by[5]['needs']]);
        $this->assertStringContainsString('Price is empty', $by[6]['note']);
        $this->assertSame(['Check', 'destination'], [$by[8]['status'], $by[8]['needs']]);
        $this->assertSame('AED', $by[9]['currency']);
        $this->assertSame(['Singapore'], array_column($dry['new_destinations'], 'name'));
        $this->assertSame(1, TransportLine::count());                                              // nothing saved by a check
        $this->assertSame(1, TransportDestination::count());
    }

    public function test_the_import_saves_what_is_ready_and_what_was_chosen_and_skips_the_rest_and_reloading_updates(): void
    {
        $this->as(User::factory()->operations()->create());
        TransportDestination::create(['name' => 'Manali']);                                         // a destination already in the list
        $sheet = [
            $this->row(['supplier' => 'Manali Taxi Service', 'seats' => '6']),
            $this->row(['service' => 'Dzire: Manali to Kullu', 'charged' => '', 'price' => '3200']),                                                  // row 3: check
            $this->row(['destination' => 'Singapore', 'service' => 'MPV airport', 'charged' => 'per_trip', 'price' => '60']),                           // row 4: new destination
            $this->row(['type' => 'bus', 'service' => 'Skipped', 'price' => '']),                                                                      // row 5: missing
        ];
        $first = $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet)], ['Accept' => 'application/json'])->assertOk()->json();      // no choices made
        $this->assertSame([1, 0, 3], [$first['new'], $first['updated'], $first['skipped']]);
        $this->assertSame(1, TransportLine::count());

        $choices = json_encode(['charged' => ['3' => 'per_day'], 'destinations' => ['Singapore' => ['country' => 'Singapore', 'currency' => 'SGD']]]);
        $second = $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet), 'choices' => $choices], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 1, 1], [$second['new'], $second['updated'], $second['skipped']]);        // 2 new, the first row updated, only the empty price skipped
        $this->assertSame(3, TransportLine::count());
        $sg = TransportLine::where('name', 'MPV airport')->first();
        $this->assertSame('SGD', $sg->currency);
        $this->assertSame('per_day', TransportLine::where('name', 'Dzire: Manali to Kullu')->first()->charged);
        $this->assertSame(6, TransportLine::where('name', 'Ertiga: local sightseeing')->first()->seats);
        $this->assertSame('Manali Taxi Service', TransportSupplier::first()->name);

        $third = $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet), 'choices' => $choices], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([0, 3], [$third['new'], $third['updated']]);                              // loading it again updates, never duplicates
        $this->assertSame(3, TransportLine::count());
    }

    public function test_seasons_of_the_same_service_in_a_sheet_join_one_line_and_the_template_downloads(): void
    {
        $this->as(User::factory()->operations()->create());
        TransportDestination::create(['name' => 'Manali']);
        $v = ['type' => 'bus', 'service' => 'Volvo: Delhi to Manali', 'charged' => 'per_person', 'supplier' => 'Volvo desk'];
        $sheet = [$this->row(array_merge($v, ['price' => '2000', 'season' => 'Off season'])), $this->row(array_merge($v, ['price' => '2500', 'season' => 'Mid season', 'valid_from' => '2027-03-15', 'valid_to' => '2027-05-14'])),
            $this->row(array_merge($v, ['price' => '3000', 'season' => 'On season', 'valid_from' => '2027-05-15', 'valid_to' => '2027-06-30']))];
        $r = $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(1, TransportLine::count());                                                // one line, not three
        $line = TransportLine::with('seasons')->first();
        $this->assertSame(2000.0, $line->price);
        $this->assertSame(['Mid season', 'On season'], $line->seasons->pluck('name')->all());
        $this->assertSame([2500.0, 3000.0], $line->seasons->pluck('price')->all());
        $this->post('/api/transport/lines/import', ['file' => $this->csv($sheet)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, TransportLine::first()->seasons()->count());                           // again: updated, not doubled
        $this->get('/api/transport/lines/import-template')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="transport-rates-template.csv"');
    }

    public function test_the_manali_sheet_and_the_singapore_sample_load_and_reload_cleanly(): void
    {
        $this->artisan('transport:load-rates-sample')->assertSuccessful();
        $this->artisan('transport:load-rates-sample')->assertSuccessful();
        $this->assertSame(23, TransportLine::count());
        $ertiga = TransportLine::where('name', 'Ertiga: Local Manali, Solang Valley, Kullu-Naggar')->first();
        $this->assertEquals([5000, 'per_day', 'INR', 6], [$ertiga->price, $ertiga->charged, $ertiga->currency, $ertiga->seats]);
        $this->assertNull(TransportLine::where('name', 'like', 'Ertiga: Local Manali, Solang Valley')->where('name', 'not like', '%Kullu%')->first());       // the sheet has no short-route Ertiga rate
        $volvo = TransportLine::with('seasons')->where('name', 'Volvo: Delhi to Manali')->first();
        $this->assertEquals([2000, 'per_person'], [$volvo->price, $volvo->charged]);
        $this->assertSame([2500.0, 3000.0], $volvo->seasons->pluck('price')->all());
        $this->assertSame('SGD', TransportLine::whereHas('destination', fn ($q) => $q->where('name', 'Singapore'))->first()->currency);
    }
}
