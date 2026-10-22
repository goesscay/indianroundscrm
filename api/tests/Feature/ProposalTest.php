<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\Quote;
use App\Models\TravelPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    /** a Manali hotel with one double room at 4,200 a night on MAP over the New Year */
    private function hotel(): array
    {
        $h = Hotel::create(['name' => 'Mareema Hotel Manali', 'destination' => 'Manali', 'stars' => 3]);
        $r = HotelRoom::create(['hotel_id' => $h->id, 'name' => 'Deluxe Room with Balcony View', 'max_adults' => 2]);
        HotelRate::create(['room_id' => $r->id, 'meal_plan' => 'MAP', 'season' => 'Peak', 'start_date' => '2026-12-20', 'end_date' => '2027-01-05', 'double' => 4200, 'child_with_bed' => 1300]);

        return [$h, $r];
    }

    private function proposal(Hotel $h, HotelRoom $r, array $over = []): array
    {
        return $over + [
            'trip' => ['destination' => 'Manali', 'start_date' => '2027-01-02', 'nights' => 2, 'adults' => 2, 'children_bed' => 0, 'children_no_bed' => 0, 'group_sizes' => [2, 4, 6]],
            'days' => [['title' => 'Delhi to Manali (Volvo)', 'stay' => 'Journey', 'bullets' => ['17:00 Report at the metro station', 'Overnight journey'], 'media_id' => null],
                ['title' => 'Manali local sightseeing', 'stay' => 'Manali', 'bullets' => ['Hadimba Devi Temple', 'Mall Road'], 'media_id' => null]],
            'options' => [['name' => '3 Star', 'stays' => [['hotel_id' => $h->id, 'room_id' => $r->id, 'meal_plan' => 'MAP', 'start_offset' => 0, 'nights' => 2]]]],
            'land' => ['label' => 'Delhi-Manali-Delhi Volvo and sightseeing', 'rows' => [['pax' => 2, 'cost_pp' => 2000], ['pax' => 6, 'cost_pp' => 1500]]],
            'gst_rate' => 5, 'selected_option' => 0,
            'content' => ['inclusions' => ['2 nights stay', 'Volvo transfers'], 'exclusions' => ['GST 5%'], 'notes' => ['Rates valid for the dates only'], 'terms' => ['Advance confirms the booking'], 'closing' => ["Let's make this trip happen."],
                'cancellation' => ['tiers' => [['label' => '50+ days before departure', 'percent' => 25]], 'items' => ['Refunds in 15-31 working days']]],
        ];
    }

    public function test_the_price_per_person_changes_with_the_group_size_and_the_actual_group_is_priced_too(): void
    {
        [$h, $r] = $this->hotel();
        $sales = $this->as(User::factory()->sales()->create());
        $p = $this->proposal($h, $r);
        $p['trip']['children_bed'] = 1;                                         // the real group: 2 adults and a child

        $res = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json();
        $opt = $res['computed']['options'][0];
        $pp = array_column($opt['tiers'], 'per_person', 'pax');
        // 2 persons: hotel 8,400 + 15% = 9,660; land 2 x 2,000 = 4,000 + 15% = 4,600; total 14,260 -> 7,130 each
        $this->assertEquals(7130, $pp[2]);
        // 4 persons: two rooms 16,800 + 15% = 19,320; land 4 x 1,500 = 6,000 + 15% = 6,900; total 26,220 -> 6,555 each
        $this->assertEquals(6555, $pp[4]);
        $this->assertEquals(6555, $pp[6]);                                       // 6 persons: 28,980 + 10,350 = 39,330
        // the real group of 3: hotel (4,200 + 1,300) x 2 nights = 11,000 + 15%; land 3 x 2,000 + 15%
        $this->assertEquals(12650 + 6900, $opt['actual']['total']);
        $this->assertEquals(6517, $opt['actual']['per_person']);
        $this->assertSame([], $res['computed']['warnings']);

        // sales never see what the hotels cost or the margin
        $this->assertArrayNotHasKey('cost', $opt['tiers'][0]);
        $this->assertArrayNotHasKey('margin_amount', $opt['tiers'][0]);
        $this->assertArrayNotHasKey('cost', $opt['actual']['stays'][0]);
        $this->assertArrayNotHasKey('margin', $res['computed']['options'][0]['tiers'][0]);

        $this->as(User::factory()->operations()->create());
        $ops = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed.options.0');
        $this->assertEquals(8400 + 4000, $ops['tiers'][0]['cost']);              // Operations see the costs
        $this->assertEquals(1260 + 600, $ops['tiers'][0]['margin_amount']);
    }

    public function test_missing_rates_and_dates_are_flagged_not_guessed(): void
    {
        [$h, $r] = $this->hotel();
        $this->as(User::factory()->sales()->create());
        $p = $this->proposal($h, $r);
        $p['trip']['start_date'] = '2027-02-10';                                 // outside the season
        $w = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed.warnings');
        $this->assertNotEmpty($w);
        $this->assertStringContainsString('No MAP rate', $w[0]);
        $p['trip']['start_date'] = null;
        $w = $this->postJson('/api/quote-proposals/compute', ['proposal' => $p])->assertOk()->json('computed.warnings');
        $this->assertStringContainsString('travel date', $w[0]);

        // junk is cleaned, not trusted
        $clean = $this->postJson('/api/quote-proposals/compute', ['proposal' => ['trip' => ['adults' => -5, 'group_sizes' => [0, 99, 3, 3, 'x'], 'nights' => 500], 'options' => [], 'days' => [['title' => '', 'bullets' => []]]]])->assertOk()->json('proposal');
        $this->assertSame([3], $clean['trip']['group_sizes']);
        $this->assertSame([1, 90], [$clean['trip']['adults'], $clean['trip']['nights']]);
        $this->assertSame([], $clean['days']);
        $this->assertSame('Option 1', $clean['options'][0]['name']);
    }

    public function test_it_starts_from_a_package_with_the_standard_text_and_saves_as_a_draft_quote_with_lines(): void
    {
        [$h, $r] = $this->hotel();
        $sales = $this->as(User::factory()->sales()->create());
        $c = Customer::create(['customer_no' => 'IRC-PR1', 'name' => 'Asha Rao', 'owner_id' => $sales->id]);
        $deal = Deal::create(['deal_no' => 'IRD-PR1', 'customer_id' => $c->id, 'name' => 'Manali', 'destination' => 'Manali', 'owner_id' => $sales->id, 'stage' => 'enquiry', 'travel_date' => '2027-01-02', 'duration_days' => 3, 'adults' => 2, 'children' => 0]);
        $pkg = TravelPackage::create(['code' => 'MNL-1', 'name' => 'Manali 2N', 'destination' => 'Manali', 'nights' => 2, 'days' => 3, 'inclusions' => "Hotel stay\nTransfers", 'exclusions' => 'Air fare', 'adult_price' => 1, 'gst_rate' => 5, 'active' => true]);
        \Illuminate\Support\Facades\DB::table('package_days')->insert(['package_id' => $pkg->id, 'day_no' => 1, 'title' => 'Arrival', 'description' => "Check in\nEvening free"]);
        \Illuminate\Support\Facades\DB::table('package_user')->insert(['package_id' => $pkg->id, 'user_id' => $sales->id]);

        $seed = $this->getJson("/api/quote-proposals/seed?package_id={$pkg->id}&deal_id={$deal->id}")->assertOk()->json();
        $this->assertSame((string) $c->id, $seed['customer_id']);
        $sp = $seed['proposal'];
        $this->assertSame(['Manali', '2027-01-02', 2, 2], [$sp['trip']['destination'], $sp['trip']['start_date'], $sp['trip']['nights'], $sp['trip']['adults']]);
        $this->assertSame(['Check in', 'Evening free'], $sp['days'][0]['bullets']);
        $this->assertContains('Hotel stay', $sp['content']['inclusions']);
        $this->assertContains('Air fare', $sp['content']['exclusions']);
        $this->assertContains('Room heater.', $sp['content']['exclusions']);                      // the standard exclusions come along
        $this->assertSame(25.0, (float) $sp['content']['cancellation']['tiers'][0]['percent']);

        $p = $this->proposal($h, $r);
        $saved = $this->postJson('/api/quote-proposals', ['customer_id' => (string) $c->id, 'deal_id' => (string) $deal->id, 'title' => 'Manali 2N 3D', 'proposal' => $p])->assertOk()->json();
        $q = $saved['quote'];
        $this->assertTrue($q['is_proposal']);
        $this->assertSame('draft', $q['status']);
        $this->assertSame(['Manali', 3, '2027-01-02'], [$q['destination'], $q['duration_days'], $q['travel_date']]);
        $this->assertCount(2, $q['lines']);
        $this->assertSame('hotel', $q['lines'][0]['kind']);
        $this->assertStringContainsString('Mareema Hotel Manali', $q['lines'][0]['description']);
        $this->assertEquals(9660, $q['lines'][0]['unit_price']);
        $this->assertEquals(4600, $q['lines'][1]['unit_price']);
        $this->assertEquals(14260, $q['subtotal']);
        $this->assertEquals(713, $q['tax_total']);                                                  // 5% GST on top
        $this->assertEquals(14973, $q['total']);
        $this->assertSame('Delhi to Manali (Volvo)', $q['itinerary'][0]['title']);                  // the older quote page and PDF still have something to show
        $this->assertStringContainsString('2 nights stay', $q['inclusions']);
        $this->assertStringContainsString('25% cancellation charge', $q['terms']);

        // it opens again for editing, the price follows the change, and the lines are replaced
        $id = $q['id'];
        $this->getJson("/api/quote-proposals/$id")->assertOk()->assertJsonPath('proposal.options.0.name', '3 Star');
        $p['trip']['adults'] = 4;
        $up = $this->putJson("/api/quote-proposals/$id", ['proposal' => $p])->assertOk()->json();
        $this->assertCount(2, $up['quote']['lines']);
        $this->assertEquals(19320 + 6900, $up['quote']['subtotal']);                                // four people
        $this->assertSame(1, Quote::count());

        // a sent quote is closed to edits
        Quote::whereKey($id)->update(['status' => 'sent']);
        $this->putJson("/api/quote-proposals/$id", ['proposal' => $p])->assertStatus(409);
        // another salesperson cannot open it
        $this->as(User::factory()->sales()->create());
        $this->getJson("/api/quote-proposals/$id")->assertStatus(404);
        $this->postJson('/api/quote-proposals', ['proposal' => $p])->assertStatus(422);              // a customer is needed
    }

    public function test_sales_see_which_meal_plans_a_room_has_without_the_prices(): void
    {
        [$h] = $this->hotel();
        $this->as(User::factory()->sales()->create());
        $room = $this->getJson("/api/hotels/{$h->id}")->assertOk()->json('rooms.0');
        $this->assertSame(['MAP'], $room['meals']);
        $this->assertSame([], $room['rates']);
    }
}
