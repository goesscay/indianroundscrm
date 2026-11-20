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
}
