<?php

namespace Tests\Feature;

use App\Models\TransportRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransportMasterTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    /** @return array{0:array,1:array,2:array,3:array} provider, vehicle, route, rate */
    private function setup_provider(string $country = 'IN', string $dest = 'Manali'): array
    {
        $p = $this->postJson('/api/transport/providers', ['name' => 'Manali Taxi Service', 'country_code' => $country, 'destination' => $dest, 'modes' => ['cab']])->assertOk()->json();
        $v = $this->postJson("/api/transport/providers/{$p['id']}/vehicles", ['name' => 'Ertiga', 'mode' => 'cab', 'seats' => 6])->assertOk()->json();
        $r = $this->postJson("/api/transport/providers/{$p['id']}/routes", ['title' => 'Local Manali, Solang Valley, Kullu-Naggar', 'kind' => 'sightseeing'])->assertOk()->json();
        $rate = $this->postJson("/api/transport/providers/{$p['id']}/rates", ['vehicle_id' => $v['id'], 'route_id' => $r['id'], 'basis' => 'per_day', 'season' => 'Off Season', 'cost' => 5000])->assertOk()->json();

        return [$p, $v, $r, $rate];
    }

    public function test_operations_manage_the_master_and_sales_only_look_it_up_without_costs(): void
    {
        $ops = User::factory()->operations()->create();
        $sales = User::factory()->sales()->create();
        $this->assertTrue($ops->hasPermission('transport.manage'));
        $this->assertTrue($sales->hasPermission('transport.view'));
        $this->assertFalse($sales->hasPermission('transport.manage'));

        $this->as($ops);
        [$p, $v, $r, $rate] = $this->setup_provider();
        $this->assertSame('INR', $p['currency']);                                           // taken from the country
        $this->assertSame('INR', $rate['currency']);
        $this->assertTrue($rate['dates_needed']);                                            // no season dates given yet
        $this->postJson('/api/transport/providers', ['name' => 'manali taxi service', 'country_code' => 'IN', 'destination' => 'Manali'])->assertStatus(400);   // same provider, same place
        $this->postJson("/api/transport/providers/{$p['id']}/vehicles", ['name' => 'ERTIGA', 'seats' => 6])->assertStatus(400);
        $d = $this->getJson("/api/transport/providers/{$p['id']}")->assertOk()->json();
        $this->assertCount(1, $d['rates']);
        $this->assertArrayHasKey('margin_type', $d);

        $this->as($sales);
        $list = $this->getJson('/api/transport/providers')->assertOk()->json();
        $this->assertSame('Manali Taxi Service', $list[0]['name']);
        $this->assertArrayNotHasKey('margin_type', $list[0]);
        $sd = $this->getJson("/api/transport/providers/{$p['id']}")->assertOk()->json();
        $this->assertSame([], $sd['rates']);                                                 // vendor rates are hidden
        $this->assertCount(1, $sd['vehicles']);
        $this->assertArrayNotHasKey('margin_value', $sd);
        $this->postJson('/api/transport/providers', ['name' => 'X', 'country_code' => 'IN', 'destination' => 'Y'])->assertStatus(403);
        $this->patchJson("/api/transport/rates/{$rate['id']}", ['cost' => 1])->assertStatus(403);
        $this->postJson('/api/transport/import')->assertStatus(403);
    }

    public function test_a_new_country_with_its_own_currency_can_be_added_without_help(): void
    {
        $this->as(User::factory()->operations()->create());
        $this->postJson('/api/transport/providers', ['name' => 'Bali Cars', 'country_code' => 'ID', 'destination' => 'Bali'])->assertStatus(422);   // country not set up yet
        $this->postJson('/api/transport/countries', ['code' => 'id', 'name' => 'Indonesia', 'currency' => 'idr'])->assertOk()->assertJsonPath('code', 'ID')->assertJsonPath('currency', 'IDR');
        $this->postJson('/api/transport/countries', ['code' => 'ID', 'name' => 'Indonesia', 'currency' => 'IDR'])->assertStatus(400);
        $p = $this->postJson('/api/transport/providers', ['name' => 'Bali Cars', 'country_code' => 'ID', 'destination' => 'Bali'])->assertOk()->json();
        $this->assertSame('IDR', $p['currency']);
        $sg = $this->postJson('/api/transport/providers', ['name' => 'Lion City Cabs', 'country_code' => 'SG', 'destination' => 'Singapore'])->assertOk()->json();
        $this->assertSame('SGD', $sg['currency']);
        $this->postJson('/api/transport/places', ['country_code' => 'SG', 'name' => 'Changi Airport', 'kind' => 'airport', 'code' => 'SIN', 'timezone' => 'Asia/Singapore'])->assertOk()->assertJsonPath('kind', 'airport');
        $this->assertCount(1, $this->getJson('/api/transport/places?country=sg')->assertOk()->json());
    }

    public function test_rate_rules_dates_overlap_and_currency(): void
    {
        $this->as(User::factory()->operations()->create());
        [$p, $v, $r] = $this->setup_provider();
        $base = ['vehicle_id' => $v['id'], 'route_id' => $r['id'], 'basis' => 'per_day', 'cost' => 6000];

        $this->postJson("/api/transport/providers/{$p['id']}/rates", $base + ['season' => 'Peak', 'start_date' => '2026-12-20', 'end_date' => '2027-01-10'])->assertOk()->assertJsonPath('dates_needed', false);
        $this->postJson("/api/transport/providers/{$p['id']}/rates", $base + ['season' => 'Clash', 'start_date' => '2027-01-05', 'end_date' => '2027-01-20'])->assertStatus(400);
        $this->postJson("/api/transport/providers/{$p['id']}/rates", $base + ['season' => 'Backwards', 'start_date' => '2027-02-10', 'end_date' => '2027-02-01'])->assertStatus(422);
        $this->postJson("/api/transport/providers/{$p['id']}/rates", $base + ['season' => 'Half', 'start_date' => '2027-02-01'])->assertStatus(422);      // one date only
        $this->postJson("/api/transport/providers/{$p['id']}/rates", array_merge($base, ['basis' => 'per_hour']))->assertStatus(422);
        $this->postJson("/api/transport/providers/{$p['id']}/rates", ['vehicle_id' => '99999', 'basis' => 'per_day', 'cost' => 1])->assertStatus(422);      // not this provider's vehicle
        $this->postJson("/api/transport/providers/{$p['id']}/rates", $base + ['season' => 'Peak', 'start_date' => '2027-01-11', 'end_date' => '2027-03-31'])->assertOk();   // the next season is fine

        $this->patchJson("/api/transport/providers/{$p['id']}", ['currency' => 'SGD'])->assertStatus(400);              // rates exist in INR
        $this->patchJson("/api/transport/providers/{$p['id']}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->assertCount(0, $this->getJson('/api/transport/providers')->json());
        $this->assertCount(1, $this->getJson('/api/transport/providers?active=0')->json());
    }

    public function test_deleting_a_vehicle_or_provider_removes_its_rates(): void
    {
        $this->as(User::factory()->operations()->create());
        [$p, $v] = $this->setup_provider();
        $this->assertSame(1, TransportRate::count());
        $this->deleteJson("/api/transport/vehicles/{$v['id']}")->assertOk();
        $this->assertSame(0, TransportRate::count());
        $this->deleteJson("/api/transport/providers/{$p['id']}")->assertOk();
        $this->getJson("/api/transport/providers/{$p['id']}")->assertStatus(404);
    }

    private function csv(array $rows): UploadedFile
    {
        $head = ['provider', 'country', 'destination', 'currency', 'mode', 'vehicle', 'seats', 'route', 'route_kind', 'basis', 'season', 'start_date', 'end_date', 'cost', 'child_cost'];
        $body = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.$c.'"', $r)), array_merge([$head], $rows)))."\n";
        $path = tempnam(sys_get_temp_dir(), 'tr').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'rates.csv', 'text/csv', null, true);
    }

    public function test_a_rate_sheet_imports_in_one_step_and_a_dry_run_saves_nothing(): void
    {
        $this->as(User::factory()->operations()->create());
        $rows = [
            ['Manali Taxi Service', 'IN', 'Manali', '', 'cab', 'Ertiga', '6', 'Local Manali, Solang Valley, Kullu-Naggar', 'sightseeing', 'per_day', 'Off Season', '', '', '5,000', ''],
            ['Manali Taxi Service', 'IN', 'Manali', '', 'cab', 'Sumo', '7', 'Local Manali, Solang Valley, Kullu-Naggar', 'sightseeing', 'per_day', 'Off Season', '', '', '5000', ''],
            ['Volvo Bus', 'IN', 'Manali', '', 'bus', 'Volvo seat', '1', '', '', 'per_person', 'Mid Season', '', '', '2500', '1800'],
            ['Lion City Cabs', 'SG', 'Singapore', '', 'cab', 'Sedan', '4', 'Airport to hotel', 'transfer', 'per_transfer', 'All year', '2026-01-01', '2026-12-31', '55', ''],
            ['Bad Row', 'XX', 'Nowhere', '', 'cab', 'Car', '4', '', '', 'per_day', '', '', '', '100', ''],
            ['Bad Cost', 'IN', 'Manali', '', 'cab', 'Car', '4', '', '', 'per_day', '', '', '', 'abc', ''],
        ];
        $dry = $this->post('/api/transport/import', ['file' => $this->csv($rows), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(3, $dry['providers_new']);
        $this->assertSame(4, $dry['rates_new']);
        $this->assertCount(2, $dry['errors']);
        $this->assertSame(0, TransportRate::count());                                         // nothing saved

        $done = $this->post('/api/transport/import', ['file' => $this->csv($rows)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(4, $done['rates_new']);
        $this->assertSame(4, TransportRate::count());
        $sg = TransportRate::where('basis', 'per_transfer')->first();
        $this->assertSame('SGD', $sg->currency);                                               // the provider's own currency
        $this->assertSame('2026-12-31', $sg->end_date->toDateString());
        $this->assertTrue(TransportRate::where('basis', 'per_person')->first()->start_date === null);
        $this->assertSame(5000.0, TransportRate::where('basis', 'per_day')->orderBy('id')->first()->cost);

        $again = $this->post('/api/transport/import', ['file' => $this->csv($rows)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(0, $again['rates_new']);                                              // loading it again updates, never duplicates
        $this->assertSame(4, $again['rates_updated']);
        $this->assertSame(4, TransportRate::count());

        $this->get('/api/transport/import-template', ['Accept' => 'text/csv'])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="transport-rates-template.csv"');
    }

    public function test_the_manali_sheet_and_singapore_sample_load_and_reload_cleanly(): void
    {
        $this->artisan('transport:load-sample')->assertSuccessful();
        $this->artisan('transport:load-sample')->assertSuccessful();
        $this->as(User::factory()->operations()->create());
        $list = collect($this->getJson('/api/transport/providers')->json());
        $this->assertSame(['Lion City Cabs (Sample)', 'Manali Taxi Service', 'Volvo Bus: Delhi / Chandigarh - Manali'], $list->pluck('name')->sort()->values()->all());
        $taxi = $list->firstWhere('name', 'Manali Taxi Service');
        $d = $this->getJson("/api/transport/providers/{$taxi['id']}")->json();
        $ertiga = collect($d['vehicles'])->firstWhere('name', 'Ertiga');
        $rates = collect($d['rates'])->where('vehicle_id', $ertiga['id']);
        $this->assertEquals([5000, 5500], $rates->pluck('cost')->sort()->values()->all());     // the sheet has no Ertiga rate for the short local route
        $this->assertSame(27, TransportRate::count());
        $this->assertSame('SGD', $list->firstWhere('name', 'Lion City Cabs (Sample)')['currency']);
    }
}
