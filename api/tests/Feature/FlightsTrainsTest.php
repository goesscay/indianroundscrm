<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\TransportSchedule;
use App\Models\User;
use App\Services\ProposalPdf;
use App\Services\ProposalPricing;
use App\Services\TransportReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Flights and trains (details only, no prices): the Operations lists, suggestions on a quotation, and the customer's "How to reach". */
class FlightsTrainsTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function flight(array $over = []): array
    {
        return $this->postJson('/api/transport/schedules', $over + ['kind' => 'flight', 'title' => 'Air India AI 123', 'from_name' => 'Delhi', 'from_code' => 'del', 'to_name' => 'Kullu-Manali', 'to_code' => 'kuu',
            'serves' => 'Manali', 'departs' => '09:10', 'arrives' => '10:35', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'classes' => 'Economy', 'note' => 'About 40 km from Manali.'])->assertOk()->json();
    }

    private function train(array $over = []): array
    {
        return $this->postJson('/api/transport/schedules', $over + ['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'from_code' => 'NDLS', 'to_name' => 'Kalka', 'to_code' => 'KLK',
            'serves' => 'Manali, Shimla', 'departs' => '07:40', 'arrives' => '11:45', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'classes' => 'CC, EC', 'note' => 'Then road to Manali.'])->assertOk()->json();
    }

    public function test_operations_keep_the_lists_and_sales_only_look_them_up_and_there_is_no_price_anywhere(): void
    {
        $ops = User::factory()->operations()->create();
        $sales = User::factory()->sales()->create();
        $this->as($ops);
        $f = $this->flight();
        $this->assertSame('DEL', $f['from_code']);                                              // codes are kept in capitals
        $this->assertSame('Daily', $f['runs_on']);
        $this->assertSame('Daily except Sun', $this->train()['runs_on']);
        $this->assertSame('Mon, Wed', $this->flight(['title' => 'X 1', 'days' => ['wed', 'mon']])['runs_on']);
        foreach (array_keys($f) as $key) {
            $this->assertDoesNotMatchRegularExpression('/price|fare|cost|amount|margin/i', $key);   // nothing here can carry a price
        }
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('transport_schedules', 'price'));

        $this->as($sales);
        $this->assertCount(3, $this->getJson('/api/transport/schedules')->assertOk()->json());
        $this->postJson('/api/transport/schedules', ['kind' => 'flight'])->assertStatus(403);
        $this->patchJson("/api/transport/schedules/{$f['id']}", ['title' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/transport/schedules/{$f['id']}")->assertStatus(403);
        $this->post('/api/transport/schedules/import', [], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_add_edit_duplicate_delete_and_the_rules(): void
    {
        $this->as(User::factory()->operations()->create());
        $f = $this->flight();
        $this->postJson('/api/transport/schedules', ['kind' => 'flight', 'title' => 'Bad', 'from_name' => 'A', 'to_name' => 'B', 'days' => ['mon'], 'departs' => '25:99'])->assertStatus(422);
        $this->postJson('/api/transport/schedules', ['kind' => 'flight', 'title' => 'Bad', 'from_name' => 'A', 'to_name' => 'B', 'days' => []])->assertStatus(422);
        $this->postJson('/api/transport/schedules', ['kind' => 'bus', 'title' => 'Bad', 'from_name' => 'A', 'to_name' => 'B', 'days' => ['mon']])->assertStatus(422);

        $this->patchJson("/api/transport/schedules/{$f['id']}", ['departs' => '09:30', 'arrives_next_day' => true, 'days' => ['sun', 'mon']])->assertOk()
            ->assertJsonPath('departs', '09:30')->assertJsonPath('days', ['mon', 'sun'])->assertJsonPath('runs_on', 'Mon, Sun');
        $copy = $this->postJson("/api/transport/schedules/{$f['id']}/duplicate")->assertOk()->json();
        $this->assertSame('Air India AI 123 (copy)', $copy['title']);
        $this->assertNotSame($f['id'], $copy['id']);
        $this->deleteJson("/api/transport/schedules/{$copy['id']}")->assertOk();
        $this->getJson('/api/transport/schedules')->assertJsonCount(1);
        $this->patchJson("/api/transport/schedules/{$f['id']}", ['active' => false])->assertOk();
        $this->getJson('/api/transport/schedules')->assertJsonCount(0);
        $this->getJson('/api/transport/schedules?active=0')->assertJsonCount(1);
    }

    public function test_the_list_filters_by_route_destination_running_day_and_needs_update(): void
    {
        $this->as(User::factory()->operations()->create());
        $this->flight();
        $this->train();                                                                            // runs every day except Sunday
        $this->flight(['title' => 'IndiGo 6E 456', 'to_name' => 'Chandigarh', 'to_code' => 'IXC', 'serves' => 'Manali', 'departs' => '07:00', 'arrives' => '08:05']);
        $this->train(['title' => 'Himalayan Queen 14095', 'from_name' => 'Kalka', 'from_code' => 'KLK', 'to_name' => 'Shimla', 'to_code' => 'SML', 'serves' => null]);

        $this->assertCount(2, $this->getJson('/api/transport/schedules?kind=flight')->json());
        $this->assertCount(2, $this->getJson('/api/transport/schedules?kind=train')->json());
        $this->assertCount(1, $this->getJson('/api/transport/schedules?q=shatabdi')->json());
        $this->assertSame(['Air India AI 123'], array_column($this->getJson('/api/transport/schedules?kind=flight&route='.urlencode('Delhi → Kullu-Manali'))->json(), 'title'));
        $routes = $this->getJson('/api/transport/schedules/routes?kind=train')->json();
        $this->assertSame(['Kalka → Shimla', 'New Delhi → Kalka'], $routes);
        $this->assertSame(['Delhi', 'Kalka', 'New Delhi'], $this->getJson('/api/transport/schedules/origins')->json());

        $manali = collect($this->getJson('/api/transport/schedules?to=Manali')->json())->pluck('title')->sort()->values()->all();     // ends near Manali, so "serves" it
        $this->assertSame(['Air India AI 123', 'IndiGo 6E 456', 'Shatabdi 12011'], $manali);
        $this->assertCount(2, $this->getJson('/api/transport/schedules?to=Manali&from=Delhi&kind=flight')->json());
        $this->assertCount(1, $this->getJson('/api/transport/schedules?to=Manali&from=New+Delhi')->json());

        $sat = collect($this->getJson('/api/transport/schedules?to=Manali&date=2026-12-05')->json())->pluck('title')->all();            // Saturday: all run
        $sun = collect($this->getJson('/api/transport/schedules?to=Manali&date=2026-12-06')->json())->pluck('title')->all();            // Sunday: Shatabdi does not
        $this->assertCount(3, $sat);
        $this->assertNotContains('Shatabdi 12011', $sun);
        $this->assertCount(2, $sun);

        $this->assertCount(0, $this->getJson('/api/transport/schedules?stale=1')->json());
        TransportSchedule::where('title', 'Shatabdi 12011')->update(['updated_at' => now()->subDays(200)]);
        $stale = $this->getJson('/api/transport/schedules?stale=1')->json();
        $this->assertSame(['Shatabdi 12011'], array_column($stale, 'title'));
        $this->assertTrue($stale[0]['needs_update']);
        $id = $stale[0]['id'];
        $this->patchJson("/api/transport/schedules/{$id}", ['note' => 'Checked'])->assertOk()->assertJsonPath('needs_update', false);   // saving a review clears it
    }

    private function csv(array $rows): UploadedFile
    {
        $head = ['type', 'name', 'from', 'from_code', 'to', 'to_code', 'serves', 'departs', 'arrives', 'next_day', 'days', 'classes', 'note'];
        $body = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.$c.'"', $r)), array_merge([$head], $rows)))."\n";
        $path = tempnam(sys_get_temp_dir(), 'fl').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'schedules.csv', 'text/csv', null, true);
    }

    public function test_an_excel_list_is_checked_first_then_imported_and_loading_it_again_updates(): void
    {
        $this->as(User::factory()->operations()->create());
        $rows = [
            ['flight', 'Air India AI 123', 'Delhi', 'del', 'Kullu-Manali', 'kuu', 'Manali', '9:10', '10:35', '', 'Daily', 'Economy', ''],
            ['train', 'Shatabdi 12011', 'New Delhi', 'NDLS', 'Kalka', 'KLK', 'Manali', '07:40', '11:45', '', 'Daily except Sun', 'CC, EC', 'Then road'],
            ['train', 'Night train', 'Delhi', '', 'Jammu', '', '', '22:00', '06:30', 'yes', 'Mon, Wed, Fri', 'SL', ''],
            ['plane', 'Bad type', 'A', '', 'B', '', '', '', '', '', 'Daily', '', ''],
            ['train', 'Bad days', 'A', '', 'B', '', '', '', '', '', 'Whenever', '', ''],
            ['flight', 'Bad time', 'A', '', 'B', '', '', '7pm', '', '', 'Daily', '', ''],
            ['flight', '', 'A', '', 'B', '', '', '', '', '', 'Daily', '', ''],
        ];
        $dry = $this->post('/api/transport/schedules/import', ['file' => $this->csv($rows), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame([3, 0], [$dry['new'], $dry['updated']]);
        $this->assertCount(4, $dry['errors']);
        $this->assertSame(0, TransportSchedule::count());                                          // nothing saved by a check

        $done = $this->post('/api/transport/schedules/import', ['file' => $this->csv($rows)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(3, $done['new']);
        $air = TransportSchedule::where('title', 'Air India AI 123')->first();
        $this->assertSame('09:10', $air->departs);                                                  // 9:10 becomes 09:10
        $this->assertSame('KUU', $air->to_code);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $air->days);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], TransportSchedule::where('title', 'Shatabdi 12011')->first()->days);
        $night = TransportSchedule::where('title', 'Night train')->first();
        $this->assertSame(['mon', 'wed', 'fri'], $night->days);
        $this->assertTrue($night->arrives_next_day);

        $again = $this->post('/api/transport/schedules/import', ['file' => $this->csv($rows)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([0, 3], [$again['new'], $again['updated']]);
        $this->assertSame(3, TransportSchedule::count());
        $this->get('/api/transport/schedules/import-template')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="flights-trains-template.csv"');
    }

    public function test_running_day_words_are_understood(): void
    {
        $c = \App\Http\Controllers\Crm\TransportScheduleController::class;
        $this->assertCount(7, $c::parseDays('Daily'));
        $this->assertCount(7, $c::parseDays(''));
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], $c::parseDays('Daily except Sun'));
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], $c::parseDays('daily except Fri'));
        $this->assertSame(['tue', 'thu'], $c::parseDays('Tuesday, Thursday'));
        $this->assertSame(['mon', 'wed', 'fri'], $c::parseDays('Fri/Mon & Wed'));
        $this->assertNull($c::parseDays('Whenever'));
    }

    /** The plan's rule: the package price is exactly the same with or without the suggestions. */
    public function test_suggestions_never_change_the_package_price_and_are_kept_safe(): void
    {
        $this->as(User::factory()->operations()->create());
        $f = $this->flight();
        $base = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 3, 'adults' => 4, 'group_sizes' => [2, 4, 6]], 'gst_rate' => 5,
            'land' => ['label' => 'Cab', 'rows' => [['pax' => 4, 'cost_pp' => 3000], ['pax' => 6, 'cost_pp' => 2500]]], 'options' => [['name' => 'Option 1', 'stays' => []]]];
        $with = $base + ['reach' => ['from' => 'Delhi', 'items' => [$f + ['schedule_id' => $f['id']], ['kind' => 'train', 'title' => 'Typed for this quote', 'from_name' => 'Delhi', 'to_name' => 'Kalka', 'days' => []]]]];
        $a = $this->postJson('/api/quote-proposals/compute', ['proposal' => $base])->assertOk()->json();
        $b = $this->postJson('/api/quote-proposals/compute', ['proposal' => $with])->assertOk()->json();
        $this->assertEquals($a['computed'], $b['computed']);                                       // identical prices, tiers, warnings
        $this->assertEqualsWithDelta(13800, $b['computed']['options'][0]['actual']['total'], 0.01);

        $items = $b['proposal']['reach']['items'];
        $this->assertCount(2, $items);
        $this->assertSame(7, count($items[1]['days']));                                            // a one-off with no days runs every day
        $junk = ProposalPricing::clean($base + ['reach' => ['items' => [['kind' => 'bus', 'title' => 'x', 'from_name' => 'a', 'to_name' => 'b'], ['kind' => 'train', 'title' => '', 'from_name' => 'a', 'to_name' => 'b'],
            ['kind' => 'flight', 'title' => 'ok', 'from_name' => 'a', 'to_name' => 'b', 'departs' => '99:99', 'price' => 5000, 'fare' => 1]]]]);
        $this->assertCount(1, $junk['reach']['items']);
        $this->assertNull($junk['reach']['items'][0]['departs']);                                  // a bad time is dropped
        $this->assertArrayNotHasKey('price', $junk['reach']['items'][0]);                          // and a price cannot be smuggled in
        $this->assertArrayNotHasKey('fare', $junk['reach']['items'][0]);
        $this->assertCount(12, ProposalPricing::clean($base + ['reach' => ['items' => array_fill(0, 20, ['kind' => 'flight', 'title' => 't', 'from_name' => 'a', 'to_name' => 'b'])]])['reach']['items']);
    }

    public function test_the_customer_block_reads_clearly_with_no_price_and_general_wording_hides_names(): void
    {
        $item = ['kind' => 'flight', 'title' => 'Air India AI 123', 'from_name' => 'Delhi', 'to_name' => 'Kullu-Manali', 'departs' => '09:10', 'arrives' => '10:35', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'classes' => 'Economy', 'note' => 'The airport is about 40 km from Manali.'];
        $train = ['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'to_name' => 'Kalka', 'departs' => '07:40', 'arrives' => '11:45', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'classes' => 'CC, EC'];
        $p = ProposalPricing::clean(['trip' => ['destination' => 'Manali'], 'reach' => ['items' => [$item, $train]]]);
        $r = TransportReach::build($p);
        $this->assertSame('How to reach Manali: suggestions', $r['heading']);
        $this->assertSame(['FLIGHT', 'TRAIN'], array_column($r['items'], 'label'));
        $this->assertSame('Air India AI 123', $r['items'][0]['title']);
        $this->assertSame('Delhi → Kullu-Manali', $r['items'][0]['route']);
        $this->assertSame('09:10 → 10:35', $r['items'][0]['time']);
        $this->assertSame('Daily', $r['items'][0]['runs_on']);
        $this->assertSame('Daily except Sun', $r['items'][1]['runs_on']);
        $this->assertStringContainsString('carry no price', $r['disclaimer']);
        $this->assertStringContainsString('confirmed at the time of booking', $r['disclaimer']);
        $this->assertDoesNotMatchRegularExpression('/₹|price":|fare|cost/i', json_encode(array_diff_key($r, ['disclaimer' => 1])));

        $g = TransportReach::build(ProposalPricing::clean(['trip' => ['destination' => 'Manali'], 'transport' => ['wording' => 'general'], 'reach' => ['items' => [$item]]]));
        $this->assertSame('Flight', $g['items'][0]['title']);                                      // no airline or train name
        $this->assertNull(TransportReach::build(ProposalPricing::clean(['trip' => ['destination' => 'Manali']])));
    }

    public function test_a_saved_quotation_keeps_the_suggestions_and_the_pdf_shows_how_to_reach(): void
    {
        $sales = $this->as(User::factory()->sales()->create());
        $c = Customer::create(['customer_no' => 'IRC-FT1', 'name' => 'Ramesh Kumar', 'owner_id' => $sales->id]);
        $p = ['trip' => ['destination' => 'Manali', 'start_date' => '2026-12-05', 'nights' => 3, 'adults' => 2], 'days' => [['title' => 'Arrival', 'bullets' => ['Check in']]], 'gst_rate' => 5,
            'land' => ['label' => 'Cab', 'rows' => [['pax' => 2, 'cost_pp' => 3000]]], 'options' => [['name' => 'Option 1', 'stays' => []]],
            'reach' => ['items' => [['kind' => 'train', 'title' => 'Shatabdi 12011', 'from_name' => 'New Delhi', 'to_name' => 'Kalka', 'departs' => '07:40', 'arrives' => '11:45', 'days' => ['mon', 'tue'], 'note' => 'Then a road transfer to Manali.']]]];
        $saved = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'title' => 'Manali', 'proposal' => $p])->assertOk();
        $id = $saved->json('quote.id');
        $total = $saved->json('quote.total');

        $show = $this->getJson("/api/quote-proposals/{$id}")->assertOk();                          // reopen it
        $this->assertSame('Shatabdi 12011', $show->json('proposal.reach.items.0.title'));
        $this->assertSame('How to reach Manali: suggestions', $show->json('reach.heading'));
        $this->assertEquals($total, $show->json('quote.total'));

        $html = view('pdf.proposal', ProposalPdf::data(Quote::findOrFail($id)))->render();
        $this->assertStringContainsString('How to reach Manali: suggestions', $html);
        $this->assertStringContainsString('Shatabdi 12011', $html);
        $this->assertStringContainsString('New Delhi → Kalka', $html);
        $this->assertStringContainsString('07:40 → 11:45', $html);
        $this->assertStringContainsString('Mon, Tue', $html);
        $this->assertStringContainsString('carry no price', $html);
        $this->assertStringStartsWith('%PDF', $this->get("/api/quotes/{$id}/pdf")->assertOk()->getContent());

        $none = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'proposal' => array_diff_key($p, ['reach' => 1])])->assertOk()->json('quote.id');
        $this->assertNull($this->getJson("/api/quote-proposals/{$none}")->json('reach'));
        $this->assertStringNotContainsString('How to reach', view('pdf.proposal', ProposalPdf::data(Quote::findOrFail($none)))->render());
    }

    public function test_the_sample_list_loads_and_reloads_and_says_it_is_a_sample(): void
    {
        $this->artisan('transport:load-schedules-sample')->assertSuccessful();
        $this->artisan('transport:load-schedules-sample')->assertSuccessful();
        $this->assertSame(6, TransportSchedule::count());
        $this->assertSame(3, TransportSchedule::where('kind', 'flight')->count());
        $this->assertSame(3, TransportSchedule::where('kind', 'train')->count());
        $this->assertSame(0, TransportSchedule::where('note', 'not like', '%Sample schedule%')->count());
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'sat', 'sun'], TransportSchedule::where('title', 'Vande Bharat 22447')->first()->days);
    }
}
