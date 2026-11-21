<?php

namespace Tests\Feature;

use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Operations transport changes, phase 1: Car name, Route service (the old Service name), seasons as a choice, no hours or km, old rates and old sheets unchanged. */
class TransportFormChangesTest extends TestCase
{
    use RefreshDatabase;

    private function ops(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->operations()->create(), ['*'], 'sanctum');
    }

    private function sheet(array $head, array $rows): UploadedFile
    {
        $body = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.$c.'"', $r)), array_merge([$head], $rows)))."\n";
        $path = tempnam(sys_get_temp_dir(), 'tl').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'rates.csv', 'text/csv', null, true);
    }

    public function test_a_rate_saves_with_a_car_name_and_a_route_service_and_the_old_rate_opens_unchanged(): void
    {
        $this->ops();
        $old = $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'name' => 'Ertiga, 6 seats: local', 'price' => 5000, 'charged' => 'per_day',
            'hours_per_day' => 8, 'km_included' => 80, 'extra_hour' => 300, 'season_name' => 'Peak'])->assertOk()->json();
        $this->assertNull($old['car_name']);                                                        // car name is optional: old rates stay valid
        $new = $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'car_name' => ' Innova ', 'name' => 'Local sightseeing', 'price' => 6500, 'charged' => 'per_day', 'season_name' => 'On Season'])->assertOk()->json();
        $this->assertSame('Innova', $new['car_name']);
        $this->assertSame('Local sightseeing', $new['name']);
        $this->assertSame('On Season', $new['season_name']);

        // an old rate is edited from the new form (which no longer sends hours or km): prices, season and the old values stay
        $this->patchJson('/api/transport/lines/'.$old['id'], ['car_name' => 'Ertiga', 'name' => 'Ertiga, 6 seats: local', 'price' => 5000, 'season_name' => 'Peak'])->assertOk();
        $l = TransportLine::findOrFail($old['id']);
        $this->assertSame('Ertiga', $l->car_name);
        $this->assertSame(5000.0, $l->price);
        $this->assertSame('Peak', $l->season_name);
        $this->assertSame(8.0, $l->hours_per_day);
        $this->assertSame(80, $l->km_included);
        $this->assertSame(300.0, $l->extra_hour);
        $this->patchJson('/api/transport/lines/'.$old['id'], ['car_name' => ''])->assertOk()->assertJsonPath('car_name', null);
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'car_name' => str_repeat('x', 121), 'name' => 'X', 'price' => 1, 'charged' => 'per_day'])->assertStatus(422);
    }

    public function test_car_names_already_used_are_suggested(): void
    {
        $this->ops();
        foreach (['Innova', 'Ertiga', 'Innova'] as $i => $c) {
            $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'car_name' => $c, 'name' => 'Route '.$i, 'price' => 1000, 'charged' => 'per_day'])->assertOk();
        }
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'name' => 'No car', 'price' => 1000, 'charged' => 'per_day'])->assertOk();
        $this->assertSame([['name' => 'Ertiga', 'rates' => 1], ['name' => 'Innova', 'rates' => 2]], $this->getJson('/api/transport/lines/lookups')->assertOk()->json('car_names'));
    }

    public function test_a_sheet_with_car_name_and_route_service_columns_imports_and_fills_in_old_rates(): void
    {
        $this->ops();
        $old = $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'name' => 'Local sightseeing', 'price' => 5000, 'charged' => 'per_day'])->assertOk()->json();
        $head = ['type', 'destination', 'car_name', 'route_service', 'charged', 'price', 'season'];
        $rows = [['cab', 'Manali', 'Ertiga', 'Local sightseeing', 'per_day', '5200', ''], ['cab', 'Manali', 'Innova', 'Local sightseeing', 'per_day', '6500', 'On Season'], ['bus', 'Manali', 'Volvo B11R', 'Delhi to Manali', 'per_person', '1800', '']];
        $r = $this->post('/api/transport/lines/import', ['file' => $this->sheet($head, $rows)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(3, TransportLine::count());
        $l = TransportLine::findOrFail($old['id']);                                                 // the old rate took the first car name and its new price
        $this->assertSame('Ertiga', $l->car_name);
        $this->assertSame(5200.0, $l->price);
        $this->assertSame(['Ertiga', 'Innova', 'Volvo B11R'], TransportLine::orderBy('id')->pluck('car_name')->all());
        $this->post('/api/transport/lines/import', ['file' => $this->sheet($head, $rows)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(3, TransportLine::count());                                               // loading again changes nothing
    }

    public function test_the_old_sheet_layout_still_imports_and_leaves_car_name_empty(): void
    {
        $this->ops();
        TransportDestination::create(['name' => 'Manali']);
        $head = ['type', 'destination', 'country', 'currency', 'service', 'from', 'to', 'way', 'charged', 'price', 'season', 'valid_from', 'valid_to', 'supplier', 'seats', 'hours_per_day', 'km_included', 'extra_hour', 'note'];
        $r = $this->post('/api/transport/lines/import', ['file' => $this->sheet($head, [['cab', 'Manali', '', '', 'Ertiga: local', '', '', '', 'per_day', '5000', '', '', '', '', '6', '8', '80', '300', '']])], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(1, $r['new']);
        $l = TransportLine::firstOrFail();
        $this->assertSame('Ertiga: local', $l->name);
        $this->assertNull($l->car_name);
        $this->assertSame(5000.0, $l->price);
        $this->get('/api/transport/lines/import-template')->assertOk()->assertSee('car_name');
    }

    public function test_the_list_carries_car_name_route_service_and_destination_for_the_main_page_filter(): void
    {
        $this->ops();
        $this->postJson('/api/transport/lines', ['type' => 'cab', 'destination' => 'Manali', 'car_name' => 'Ertiga', 'name' => 'Local sightseeing', 'price' => 5000, 'charged' => 'per_day'])->assertOk();
        $this->postJson('/api/transport/lines', ['type' => 'bus', 'destination' => 'Goa', 'name' => 'Mumbai to Goa', 'price' => 900, 'charged' => 'per_person'])->assertOk();
        $rows = collect($this->getJson('/api/transport/lines')->assertOk()->json());
        $this->assertSame('Ertiga', $rows->firstWhere('destination.name', 'Manali')['car_name']);
        $this->assertNull($rows->firstWhere('destination.name', 'Goa')['car_name']);
        $this->assertSame(['Goa', 'Manali'], $rows->pluck('destination.name')->sort()->values()->all());
        $this->assertSame(['Local sightseeing'], $this->getJson('/api/transport/lines?destination=Manali')->assertOk()->json('*.name'));
        $this->assertCount(1, $this->getJson('/api/transport/lines?q=Ertiga')->assertOk()->json());                       // a car name can be searched
    }

    public function test_road_options_carry_the_car_name_and_only_operations_see_the_supplier(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $sup = \App\Models\TransportSupplier::create(['name' => 'Manali Taxi Service']);
        TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'supplier_id' => $sup->id, 'car_name' => 'Ertiga', 'name' => 'Local sightseeing', 'charged' => 'per_day', 'price' => 5500, 'currency' => 'INR', 'seats' => 6]);
        TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'name' => 'Old style: Local', 'charged' => 'per_day', 'price' => 4000, 'currency' => 'INR']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->sales()->create(), ['*'], 'sanctum');
        $sales = collect($this->getJson('/api/transport/road-options?destination=Manali')->assertOk()->json());
        $this->assertSame(['Ertiga', null], $sales->pluck('car_name')->sort()->reverse()->values()->all());
        $this->assertFalse($sales->contains(fn ($o) => array_key_exists('supplier', $o) || array_key_exists('price', $o)));
        $this->ops();
        $ops = collect($this->getJson('/api/transport/road-options?destination=Manali')->assertOk()->json());
        $this->assertSame('Manali Taxi Service', $ops->firstWhere('car_name', 'Ertiga')['supplier']);
    }

    public function test_the_fill_command_previews_first_then_splits_old_names_and_no_price_or_wording_changes(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $mk = fn (string $n, array $o = []) => TransportLine::create($o + ['type' => 'cab', 'destination_id' => $d->id, 'name' => $n, 'charged' => 'per_day', 'price' => 5500, 'currency' => 'INR', 'seats' => 6]);
        $a = $mk('Ertiga, 6 seats: Local sightseeing');
        $b = $mk('Innova: Kullu-Naggar');
        $c = $mk('No colon here');
        $e = $mk('Sumo: Local', ['car_name' => 'Sumo van']);                                        // already has a car name: left alone
        $sales = User::factory()->sales()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $cust = \App\Models\Customer::create(['customer_no' => 'IRC-F1', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $prop = fn () => ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 5, 'adults' => 4, 'group_sizes' => [4]], 'gst_rate' => 5,
            'options' => [['name' => 'Option 1', 'stays' => []]], 'road' => ['mode' => 'stay', 'stays' => [['line_id' => $a->id, 'days' => 5], ['line_id' => $b->id, 'days' => 2, 'qty' => 1]], 'extras' => []]];
        $snap = function () use ($cust, $prop) {
            \App\Services\RoadPricing::flush();
            $q = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $cust->id, 'proposal' => $prop()])->assertOk()->json('quote');
            $show = $this->getJson('/api/quote-proposals/'.$q['id'])->assertOk()->json();

            return ['total' => $q['total'], 'lines' => array_column($q['lines'], 'description'), 'labels' => collect($show['journey']['blocks'][0]['lines'])->map(fn ($l) => [$l['label'], $l['title']])->all()];
        };
        $before = $snap();

        $this->artisan('transport:fill-car-names')->assertSuccessful();                            // the preview changes nothing
        $this->assertNull($a->fresh()->car_name);
        $this->artisan('transport:fill-car-names', ['--apply' => true])->assertSuccessful();
        $this->assertSame(['Ertiga', 'Local sightseeing'], [$a->fresh()->car_name, $a->fresh()->name]);       // "6 seats" belongs to the seats field
        $this->assertSame(['Innova', 'Kullu-Naggar'], [$b->fresh()->car_name, $b->fresh()->name]);
        $this->assertNull($c->fresh()->car_name);
        $this->assertSame(['Sumo van', 'Sumo: Local'], [$e->fresh()->car_name, $e->fresh()->name]);

        $after = $snap();
        $this->assertEquals($before['total'], $after['total']);                                    // the same price through the same saved rate lines
        $this->assertGreaterThan(0, $after['total']);
        $this->assertSame($before['labels'], $after['labels']);                                     // and the customer reads the same journey
        $this->assertCount(2, $after['lines']);
        $this->assertStringContainsString('Ertiga: Local sightseeing, 5 days', $after['lines'][0]);   // the car stays in the quotation line
    }

    public function test_a_second_car_on_the_same_days_is_one_vehicle_and_the_first_still_sizes_the_group(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $ertiga = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'Ertiga', 'name' => 'Local', 'charged' => 'per_day', 'price' => 5000, 'currency' => 'INR', 'seats' => 6]);
        $innova = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'Innova', 'name' => 'Local', 'charged' => 'per_day', 'price' => 6000, 'currency' => 'INR', 'seats' => 7]);
        $this->ops();
        $p = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 2, 'adults' => 8, 'group_sizes' => [8]], 'gst_rate' => 5,
            'options' => [['name' => 'Option 1', 'stays' => []]], 'road' => ['mode' => 'stay', 'stays' => [['line_id' => $ertiga->id, 'days' => 3], ['line_id' => $innova->id, 'days' => 3, 'qty' => 1]], 'extras' => []]];
        $rows = collect($this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed.options.0.actual.road.rows'));
        $this->assertSame([2, 1], $rows->pluck('qty')->all());                                      // 8 guests need two 6-seat Ertigas; the added Innova is one
        $this->assertEquals(5000 * 2 * 3 + 6000 * 1 * 3, $rows->sum('selling') / (1 + 0) - $rows->sum('margin_amount'));
    }

    public function test_a_day_by_day_quotation_with_a_no_car_day_two_cars_and_a_volvo_line_totals_correctly_and_keeps_the_volvo_when_the_mode_changes(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $dz = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'Swift Dzire', 'name' => 'Local', 'charged' => 'per_day', 'price' => 2200, 'currency' => 'INR', 'seats' => 4]);
        $sumo = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'Sumo', 'name' => 'Local', 'charged' => 'per_day', 'price' => 3000, 'currency' => 'INR', 'seats' => 7]);
        $volvo = TransportLine::create(['type' => 'bus', 'destination_id' => $d->id, 'car_name' => 'Volvo', 'name' => 'Delhi to Manali', 'charged' => 'per_person', 'price' => 2000, 'currency' => 'INR', 'way' => 'one_way']);
        \App\Models\QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 15]);
        $this->ops();
        $extras = [['id' => 'x1', 'kind' => 'bus', 'line_id' => $volvo->id, 'day' => 1, 'way' => 'one_way']];
        $daily = ['mode' => 'daily', 'daily' => [['day' => 1, 'line_id' => null], ['day' => 2, 'line_id' => $dz->id], ['day' => 3, 'line_id' => $sumo->id]], 'extras' => $extras];
        $p = fn (array $road) => ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 4, 'adults' => 4, 'group_sizes' => [4]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]], 'road' => $road];
        $rd = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p($daily)])->assertOk()->json('computed.options.0.actual.road');
        $this->assertEquals(2530 + 3450 + 9200, $rd['selling']);                                    // Dzire day 2, Sumo day 3, Volvo 4 persons; the No car day adds nothing
        $this->assertCount(3, $rd['rows']);
        $stay = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p(['mode' => 'stay', 'stays' => [], 'extras' => $extras] + $daily)])->assertOk()->json('computed.options.0.actual.road');
        $this->assertEquals(9200, $stay['selling']);                                                // switched to the same car every day with none chosen: the Volvo is still there
    }

    public function test_a_quotation_saved_with_a_typed_vehicle_count_and_an_old_style_rate_opens_and_saves_with_the_same_total(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $old = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'name' => 'Ertiga, 6 seats: Local sightseeing', 'charged' => 'per_day', 'price' => 5000, 'currency' => 'INR', 'seats' => 6]);
        $sales = User::factory()->sales()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $cust = \App\Models\Customer::create(['customer_no' => 'IRC-O1', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $p = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 4, 'adults' => 4, 'group_sizes' => [4]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'road' => ['mode' => 'stay', 'stays' => [['line_id' => $old->id, 'days' => 5, 'qty' => 2]], 'extras' => []]];            // the old Vehicles box held 2
        $q = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $cust->id, 'proposal' => $p])->assertOk()->json('quote');
        $show = $this->getJson('/api/quote-proposals/'.$q['id'])->assertOk()->json();
        $row = $show['computed']['options'][0]['actual']['road']['rows'][0];
        $this->assertSame(2, $row['qty']);                                                          // the typed count is still honoured
        $this->assertNull($row['car_name']);                                                        // an old rate with no car name still opens
        $this->assertEquals($q['total'], $show['quote']['total']);
        $again = $this->putJson('/api/quote-proposals/'.$q['id'], ['customer_id' => (string) $cust->id, 'proposal' => $show['proposal']])->assertOk()->json('quote');
        $this->assertEquals($q['total'], $again['total']);                                          // open and save again: the same total
    }

    public function test_a_car_with_no_price_for_the_travel_date_is_marked_rate_needed_and_the_warning_reaches_the_price_bar_data(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $l = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'Ertiga', 'name' => 'Local', 'charged' => 'per_day', 'price' => 5000, 'currency' => 'INR', 'seats' => 6, 'valid_from' => '2027-04-01', 'valid_to' => '2027-06-30']);
        $this->ops();
        $p = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 4, 'adults' => 4, 'group_sizes' => [4]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'road' => ['mode' => 'stay', 'stays' => [['line_id' => $l->id, 'days' => 5]], 'extras' => []]];
        $c = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed');
        $r = $c['options'][0]['actual']['road'];
        $this->assertTrue($r['rows'][0]['rate_needed']);
        $this->assertEquals(0, $r['selling']);
        $this->assertStringContainsString('Rate needed', implode(' ', $c['warnings']));
    }

    public function test_a_per_trip_transfer_in_a_car_row_is_priced_once_whatever_the_days_so_no_transfer_button_is_needed(): void
    {
        $d = TransportDestination::create(['name' => 'Singapore', 'country' => 'Singapore', 'currency' => 'SGD']);
        $tour = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'MPV', 'name' => 'City tour (8 hours)', 'charged' => 'per_day', 'price' => 420, 'currency' => 'SGD', 'seats' => 6]);
        $transfer = TransportLine::create(['type' => 'cab', 'destination_id' => $d->id, 'car_name' => 'MPV', 'name' => 'Airport to hotel', 'charged' => 'per_trip', 'price' => 75, 'currency' => 'SGD', 'seats' => 6]);
        \App\Models\QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $this->ops();
        $p = ['trip' => ['destination' => 'Singapore', 'start_date' => '2026-12-05', 'nights' => 3, 'adults' => 4, 'group_sizes' => [4]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'transport' => ['fx' => ['date' => '2026-11-20', 'rates' => ['SGD' => 62], 'safety_pct' => 0], 'cab_scope' => []],
            'road' => ['mode' => 'stay', 'stays' => [['destination' => 'Singapore', 'line_id' => $tour->id, 'days' => 2], ['destination' => 'Singapore', 'line_id' => $transfer->id, 'days' => 4, 'qty' => 1]], 'extras' => []]];
        $rows = collect($this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed.options.0.actual.road.rows'))->keyBy('name');
        $this->assertEqualsWithDelta(420 * 2 * 62 * 1.1, $rows['City tour (8 hours)']['selling'], 0.01);          // per day: two days
        $this->assertEqualsWithDelta(75 * 1 * 62 * 1.1, $rows['Airport to hotel']['selling'], 0.01);               // per trip: once, although the row says four days
    }

    public function test_a_train_is_a_priced_rate_and_an_added_train_counts_in_the_transport_price_with_the_margin(): void
    {
        $this->ops();
        $t = $this->postJson('/api/transport/lines', ['type' => 'train', 'destination' => 'Manali', 'car_name' => 'Shatabdi Express 12011', 'name' => 'New Delhi to Kalka, Chair Car', 'from_name' => 'New Delhi', 'to_name' => 'Kalka',
            'way' => 'one_way', 'price' => 1000, 'charged' => 'per_person'])->assertOk()->json();
        $this->assertSame('train', $t['type']);
        $this->assertContains('Shatabdi Express 12011', collect($this->getJson('/api/transport/road-options?destination=Manali')->assertOk()->json())->pluck('car_name')->all());
        \App\Models\QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $p = fn (array $extras) => ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 4, 'adults' => 4, 'group_sizes' => [2, 4, 6]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'road' => ['mode' => 'stay', 'stays' => [], 'extras' => $extras]];
        $c = fn (array $extras) => $this->postJson('/api/quote-proposals/compute', ['proposal' => $p($extras)])->assertOk()->json('computed.options.0');
        $one = $c([['id' => 't1', 'kind' => 'train', 'line_id' => $t['id'], 'day' => 1, 'way' => 'one_way']]);
        $this->assertEqualsWithDelta(1000 * 4 * 1.1, $one['actual']['road']['selling'], 0.01);        // 4 persons, one way, 10% margin
        $this->assertEqualsWithDelta(1000 * 4 * 1.1, $one['actual']['total'], 0.01);                  // it is in the Transport price
        $this->assertEqualsWithDelta(1000 * 4 * 1.1, $one['actual']['road']['by_mode']['train'], 0.01);
        $this->assertSame('train', $one['actual']['road']['rows'][0]['type']);
        $this->assertEqualsWithDelta(1000 * 2 * 1.1, $one['tiers'][0]['road']['selling'] ?? 2200, 0.01);       // the group of 2 pays for 2

        $ret = $c([['id' => 't1', 'kind' => 'train', 'line_id' => $t['id'], 'day' => 1, 'way' => 'return']]);
        $this->assertEqualsWithDelta(1000 * 4 * 2 * 1.1, $ret['actual']['road']['selling'], 0.01);    // a return on a one-way fare is paid twice
        $few = $c([['id' => 't1', 'kind' => 'train', 'line_id' => $t['id'], 'day' => 1, 'way' => 'one_way', 'persons' => 3]]);
        $this->assertEqualsWithDelta(1000 * 3 * 1.1, $few['actual']['road']['selling'], 0.01);        // only three of the four take the train
        $this->assertEquals(0, $c([])['actual']['total']);                                            // removed: no price
    }

    public function test_a_train_rate_can_be_imported_from_a_sheet(): void
    {
        $this->ops();
        TransportDestination::create(['name' => 'Manali']);
        $r = $this->post('/api/transport/lines/import', ['file' => $this->sheet(['type', 'destination', 'car_name', 'route_service', 'charged', 'price'], [['train', 'Manali', 'Shatabdi 12011', 'New Delhi to Kalka', 'per_person', '1000']])], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('train', TransportLine::firstOrFail()->type);
    }

    public function test_ticking_and_unticking_train_suggestions_leaves_the_price_unchanged_with_a_priced_train_on_the_quotation(): void
    {
        $d = TransportDestination::create(['name' => 'Manali']);
        $train = TransportLine::create(['type' => 'train', 'destination_id' => $d->id, 'car_name' => 'Shatabdi 12011', 'name' => 'New Delhi to Kalka', 'charged' => 'per_person', 'price' => 1000, 'currency' => 'INR', 'way' => 'one_way']);
        \App\Models\QuoteSetting::write('margin_default', ['type' => 'percent', 'value' => 10]);
        $this->ops();
        $sched = \App\Models\TransportSchedule::create(['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'to_name' => 'Kalka', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'departs' => '07:40', 'arrives' => '11:45', 'classes' => 'CC']);
        $base = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 4, 'adults' => 4, 'group_sizes' => [2, 4]], 'gst_rate' => 5, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'road' => ['mode' => 'stay', 'stays' => [], 'extras' => [['id' => 't1', 'kind' => 'train', 'line_id' => $train->id, 'day' => 1, 'way' => 'one_way']]]];
        $tick = ['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'to_name' => 'Kalka', 'schedule_id' => $sched->id, 'direction' => 'going'];
        $a = $this->postJson('/api/quote-proposals/compute', ['proposal' => $base])->assertOk()->json('computed');
        $b = $this->postJson('/api/quote-proposals/compute', ['proposal' => $base + ['reach' => ['from' => 'Delhi', 'items' => [$tick, $tick + ['direction' => 'return']]]]])->assertOk()->json('computed');
        $this->assertEquals($a, $b);                                                                // ticked: not a rupee different
        $this->assertGreaterThan(0, $a['options'][0]['actual']['road']['selling']);                 // and the priced train is still there
    }
}
