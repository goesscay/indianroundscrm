<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\User;
use App\Services\HotelPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HotelMasterTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function hotelWithRate(array $room = [], array $rate = []): array
    {
        $h = $this->postJson('/api/hotels', ['name' => 'Mareema Hotel Manali', 'destination' => 'Manali', 'stars' => 3, 'google_rating' => 4.7, 'google_reviews' => 1063])->assertOk()->json();
        $r = $this->postJson("/api/hotels/{$h['id']}/rooms", $room + ['name' => 'Deluxe Room with Balcony View', 'max_adults' => 2])->assertOk()->json();
        $rate = $this->postJson("/api/hotel-rooms/{$r['id']}/rates", $rate + ['meal_plan' => 'MAP', 'season' => 'Peak', 'start_date' => '2026-12-20', 'end_date' => '2027-01-05',
            'double' => 4200, 'triple' => 5200, 'extra_adult' => 1500, 'child_with_bed' => 1300, 'child_no_bed' => 700])->assertOk()->json();

        return [$h, $r, $rate];
    }

    public function test_operations_manage_the_master_and_sales_only_look_hotels_up_without_costs(): void
    {
        $ops = User::factory()->operations()->create();
        $sales = User::factory()->sales()->create();
        $this->assertTrue($ops->hasPermission('hotels.manage'));
        $this->assertTrue($sales->hasPermission('hotels.view'));
        $this->assertFalse($sales->hasPermission('hotels.manage'));

        $this->as($ops);
        [$h, $r] = $this->hotelWithRate();
        $this->postJson('/api/hotels', ['name' => 'mareema hotel manali', 'destination' => 'Manali'])->assertStatus(400);        // same hotel, same place
        $d = $this->getJson("/api/hotels/{$h['id']}")->assertOk()->json();
        $this->assertSame(1, count($d['rooms'][0]['rates']));
        $this->assertSame(15.0, (float) $d['margin']['value']);                                                                  // the default margin
        $this->assertSame('default', $d['margin']['source']);

        $this->as($sales);
        $list = $this->getJson('/api/hotels')->assertOk()->json();
        $this->assertSame('Mareema Hotel Manali', $list[0]['name']);
        $this->assertArrayNotHasKey('margin', $list[0]);
        $sd = $this->getJson("/api/hotels/{$h['id']}")->assertOk()->json();
        $this->assertSame([], $sd['rooms'][0]['rates']);                                                                         // rates are hidden
        $this->assertArrayNotHasKey('margin', $sd);
        $this->postJson('/api/hotels', ['name' => 'X', 'destination' => 'Y'])->assertStatus(403);
        $this->getJson("/api/hotels/{$h['id']}/estimate?room_id={$r['id']}&check_in=2026-12-24&nights=1")->assertStatus(403);
    }

    public function test_rates_cannot_overlap_for_the_same_room_and_meal_plan(): void
    {
        $this->as(User::factory()->operations()->create());
        [, $r] = $this->hotelWithRate();
        $this->postJson("/api/hotel-rooms/{$r['id']}/rates", ['meal_plan' => 'MAP', 'start_date' => '2027-01-03', 'end_date' => '2027-01-10', 'double' => 3000])->assertStatus(400);
        $this->postJson("/api/hotel-rooms/{$r['id']}/rates", ['meal_plan' => 'CP', 'start_date' => '2027-01-03', 'end_date' => '2027-01-10', 'double' => 3000])->assertOk();   // another meal plan
        $ok = $this->postJson("/api/hotel-rooms/{$r['id']}/rates", ['meal_plan' => 'MAP', 'season' => 'Regular', 'start_date' => '2027-01-06', 'end_date' => '2027-03-31', 'double' => 3200])->assertOk()->json();
        $this->postJson("/api/hotel-rooms/{$r['id']}/rates", ['meal_plan' => 'MAP', 'start_date' => '2027-02-01', 'end_date' => '2027-01-01', 'double' => 1])->assertStatus(422);
        $this->postJson("/api/hotel-rooms/{$r['id']}/rates", ['meal_plan' => 'MAP', 'start_date' => '2028-01-01', 'end_date' => '2028-02-01'])->assertStatus(422);       // a rate is needed
        $this->patchJson("/api/hotel-rates/{$ok['id']}", ['meal_plan' => 'MAP', 'start_date' => '2027-01-06', 'end_date' => '2027-04-30', 'double' => 3300])->assertOk();    // its own row is not a clash
        $this->deleteJson("/api/hotel-rates/{$ok['id']}")->assertOk();
    }

    public function test_the_price_is_worked_out_night_by_night_with_the_margin_that_applies(): void
    {
        $this->as(User::factory()->operations()->create());
        [$h, $r] = $this->hotelWithRate();
        $room = HotelRoom::find($r['id']);

        $e = HotelPricing::estimate($room, 'MAP', '2026-12-24', 2, 2);
        $this->assertSame(8400.0, $e['cost']);                                  // 2 nights x one double at 4,200
        $this->assertSame(1260.0, $e['margin_amount']);                         // 15% default
        $this->assertSame(9660.0, $e['selling']);
        $this->assertSame(4830.0, $e['per_person']);
        $this->assertSame(['double'], $e['rooms']);

        // 4 adults take two doubles; 3 adults in a room that sleeps 2 take a double and a single (single falls back to the double rate)
        $this->assertSame(16800.0, HotelPricing::estimate($room, 'MAP', '2026-12-24', 2, 4)['cost']);
        $this->assertSame(['double', 'single'], HotelPricing::estimate($room, 'MAP', '2026-12-24', 1, 3)['rooms']);
        $this->assertSame(8400.0, HotelPricing::estimate($room, 'MAP', '2026-12-24', 1, 3)['cost']);
        // a room for three uses the triple rate
        $room->update(['max_adults' => 3]);
        $e3 = HotelPricing::estimate($room->fresh(), 'MAP', '2026-12-24', 1, 3);
        $this->assertSame(['triple'], $e3['rooms']);
        $this->assertSame(5200.0, $e3['cost']);
        // children
        $kids = HotelPricing::estimate($room->fresh(), 'MAP', '2026-12-24', 1, 2, 1, 1);
        $this->assertSame(4200.0 + 1300.0 + 700.0, $kids['cost']);
        $this->assertSame(4, $kids['persons']);

        // a night outside the season is flagged, not guessed
        $gap = HotelPricing::estimate($room->fresh(), 'MAP', '2027-01-04', 3, 2);
        $this->assertSame(2, count(array_filter($gap['nights'], fn ($n) => $n['cost'] !== null)));
        $this->assertStringContainsString('2027-01-06', $gap['warnings'][0]);
        $this->assertSame([], HotelPricing::estimate($room->fresh(), 'MAP', '2026-12-24', 1, 2)['warnings']);

        // margin order: the hotel's own, then the destination's, then the default
        $this->putJson('/api/quote-settings', ['margin_default' => ['type' => 'percent', 'value' => 10], 'margin_destinations' => ['Manali' => ['type' => 'percent', 'value' => 20]]])->assertOk();
        $m = HotelPricing::margin(Hotel::find($h['id']));
        $this->assertSame(['destination', 20.0], [$m['source'], $m['value']]);
        $this->patchJson("/api/hotels/{$h['id']}", ['margin_type' => 'amount', 'margin_value' => 500])->assertOk();
        $own = HotelPricing::estimate($room->fresh(), 'MAP', '2026-12-24', 2, 2);
        $this->assertSame(1000.0, $own['margin_amount']);                        // 500 for each of the 2 people
        $this->getJson("/api/hotels/{$h['id']}/estimate?room_id={$r['id']}&meal_plan=MAP&check_in=2026-12-24&nights=2&adults=2")->assertOk()->assertJsonPath('selling', 9400);
    }

    public function test_hotels_rooms_and_rates_can_be_imported_from_a_sheet_after_a_dry_run(): void
    {
        $this->as(User::factory()->operations()->create());
        $csv = "hotel,destination,city,stars,room,max_adults,meal_plan,season,start_date,end_date,single,double,triple,extra_adult,child_with_bed,child_no_bed\n"
            ."Hotel A,Goa,Calangute,4,Deluxe,2,CP,Peak,2026-12-20,2027-01-05,,6000,7500,2000,1800,900\n"
            ."Hotel A,Goa,Calangute,4,Deluxe,2,CP,Lean,06/01/2027,31/03/2027,,4000,,,,\n"
            ."Hotel B,Goa,,3,Standard,2,XX,Peak,2026-12-20,2027-01-05,,3000,,,,\n"            // bad meal plan
            ."Hotel B,Goa,,3,Standard,2,EP,Peak,2026-12-20,2027-01-05,,,,,,\n";              // no rate
        $file = fn () => UploadedFile::fake()->createWithContent('rates.csv', $csv);

        $dry = $this->post('/api/hotels/import', ['file' => $file(), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame([1, 2, 2], [$dry['hotels_new'], $dry['rates_new'], count($dry['errors'])]);
        $this->assertSame(0, Hotel::count());                                                              // nothing was saved

        $real = $this->post('/api/hotels/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([1, 2], [$real['hotels_new'], $real['rates_new']]);
        $room = HotelRoom::first();
        $this->assertSame(['2026-12-20', '2027-01-06'], $room->rates->map(fn ($x) => $x->start_date->toDateString())->all());     // 06/01/2027 read as 6 Jan

        $again = $this->post('/api/hotels/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();                  // the same sheet again updates, never duplicates
        $this->assertSame([0, 0, 2], [$again['hotels_new'], $again['rates_new'], $again['rates_updated']]);
        $this->assertSame(1, Hotel::count());
        $this->get('/api/hotels/import-template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->post('/api/hotels/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "name\nA\n")], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_photos_are_scaled_stored_privately_and_the_first_becomes_the_cover(): void
    {
        Storage::fake('media');
        $this->as(User::factory()->operations()->create());
        [$h] = $this->hotelWithRate();

        $img = UploadedFile::fake()->image('room.jpg', 3000, 2000);
        $photos = $this->post("/api/hotels/{$h['id']}/photos", ['files' => [$img, UploadedFile::fake()->image('front.png', 800, 600)]], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertCount(2, $photos);
        $this->assertTrue($photos[0]['is_cover']);
        $this->assertFalse($photos[1]['is_cover']);
        $this->assertSame(1800, $photos[0]['width']);                                                        // scaled down for the PDF
        $this->assertStringStartsWith('/api/media/', $photos[0]['url']);

        $res = $this->get($photos[0]['url'])->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertLessThan(481, getimagesizefromstring($this->get($photos[0]['thumb_url'])->getContent())[0]);

        $this->patchJson('/api/media/'.$photos[1]['id'], ['is_cover' => true])->assertOk();
        $d = $this->getJson("/api/hotels/{$h['id']}")->json();
        $this->assertSame($photos[1]['id'], $d['cover']['id']);
        $this->assertSame([true, false], collect($d['photos'])->pluck('is_cover')->all());
        $this->deleteJson('/api/media/'.$photos[1]['id'])->assertOk();
        $this->assertTrue($this->getJson("/api/hotels/{$h['id']}")->json('photos.0.is_cover'));            // the cover passes to the next picture

        $this->post("/api/hotels/{$h['id']}/photos", ['files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertStatus(422);

        // the destination library
        $this->post('/api/media', ['destination' => 'Manali', 'kind' => 'cover', 'files' => [UploadedFile::fake()->image('hadimba.jpg', 1200, 800)]], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, $this->getJson('/api/media?destination=Manali')->json());
        $this->assertSame(['Manali'], $this->getJson('/api/media/destinations')->json());
    }

    public function test_the_standard_quotation_text_is_seeded_and_editable(): void
    {
        $this->as(User::factory()->operations()->create());
        $all = collect($this->getJson('/api/quote-content')->assertOk()->json());
        $this->assertEqualsCanonicalizing(['exclusions', 'cancellation', 'notes', 'terms', 'closing'], $all->pluck('kind')->unique()->all());
        $canc = $all->firstWhere('kind', 'cancellation');
        $this->assertSame(25.0, (float) $canc['tiers'][0]['percent']);
        $this->assertSame('Less than 20 days before departure / no show', $canc['tiers'][3]['label']);

        $b = $this->postJson('/api/quote-content', ['kind' => 'inclusions', 'title' => 'Manali land package', 'destination' => 'Manali', 'items' => ['2 nights stay', '', 'A/C Volvo transfers'], 'is_default' => true])->assertOk()->json();
        $this->assertSame(['2 nights stay', 'A/C Volvo transfers'], $b['items']);
        $this->patchJson("/api/quote-content/{$b['id']}", ['tiers' => [['label' => '10+ days', 'percent' => 50]]])->assertOk();
        $this->postJson('/api/quote-content', ['kind' => 'nonsense', 'title' => 'x'])->assertStatus(422);
        $this->deleteJson("/api/quote-content/{$b['id']}")->assertOk();
        $this->assertSame(15.0, (float) $this->getJson('/api/quote-settings')->json('margin_default.value'));
    }
}
