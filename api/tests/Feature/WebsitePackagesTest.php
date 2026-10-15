<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PackageDeparture;
use App\Models\TravelPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsitePackagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crm.website.url' => 'https://web.test']);
    }

    private function tour(string $slug, array $o = []): array
    {
        return array_merge([
            'id' => crc32($slug) % 1000, 'slug' => $slug, 'title' => ucwords(str_replace('-', ' ', $slug)), 'summary' => "About $slug.",
            'tour_type' => 'custom', 'duration_nights' => 4, 'duration_days' => 5, 'price' => 36000, 'original_price' => null,
            'image_url' => "/photos/$slug.jpg", 'featured' => false, 'tags' => [], 'destination' => ['name' => 'Bali', 'slug' => 'bali', 'region' => 'international'],
            'next_batch' => null, 'pickup' => 'Denpasar airport', 'drop_point' => 'Denpasar airport', 'overview' => "Overview of $slug.",
            'highlights' => ['Beach day', 'Temple visit'], 'itinerary' => [['day' => 1, 'title' => 'Arrive', 'description' => 'Transfer to the hotel.'], ['day' => 2, 'title' => 'Ubud tour', 'description' => null]],
            'inclusions' => ['4 nights hotel', 'Breakfast'], 'exclusions' => ['Airfare'], 'notes' => ['Passport valid for 6 months'], 'must_carry' => ['Passport'],
            'gallery' => [], 'variants' => [], 'batches' => [],
        ], $o);
    }

    /** A fake website: the tours list (paged) and one detail per slug. $broken slugs answer with an error. */
    private function site(array $tours, array $broken = [], bool $listDown = false): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());   // a fresh website each time: a second fake would not replace the first
        Http::fake(function (HttpRequest $req) use ($tours, $broken, $listDown) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            if (preg_match('~/api/v1/tours/([^/]+)$~', $path, $m)) {
                $slug = rawurldecode($m[1]);
                if (in_array($slug, $broken, true) || ! isset($tours[$slug])) {
                    return Http::response(['message' => 'boom'], 500);
                }

                return Http::response(['data' => $tours[$slug]]);
            }
            if ($path === '/api/v1/tours') {
                if ($listDown) {
                    return Http::response('down', 503);
                }
                parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                $per = (int) ($q['per_page'] ?? 20);
                $page = (int) ($q['page'] ?? 1);
                $all = array_values($tours);

                return Http::response(['data' => array_slice($all, ($page - 1) * $per, $per), 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($all) / $per))]]);
            }

            return Http::response('', 404);
        });
    }

    private function sync(): array
    {
        return app(\App\Services\WebsiteTours::class)->run();
    }

    // ------------------------------------------------------------------ the sync

    public function test_the_first_sync_brings_every_tour_in_with_its_itinerary_departures_and_a_website_tag(): void
    {
        $tours = [];
        foreach (range(1, 45) as $i) {      // more than two pages of 20
            $tours["tour-$i"] = $this->tour("tour-$i");
        }
        $tours['bali-group'] = $this->tour('bali-group', ['tour_type' => 'group', 'price' => 52000, 'batches' => [
            ['id' => 11, 'starts_on' => '2027-02-06', 'ends_on' => '2027-02-12', 'seats_left' => 20, 'status' => 'open'],
            ['id' => 12, 'starts_on' => '2027-02-20', 'ends_on' => '2027-02-26', 'seats_left' => 4, 'status' => 'open'],
        ]]);
        $tours['kerala-india'] = $this->tour('kerala-india', ['destination' => ['name' => 'Kerala', 'slug' => 'kerala', 'region' => 'india'], 'price' => null]);
        $this->site($tours);

        $r = $this->sync();
        $this->assertSame(47, $r['tours']);
        $this->assertSame(47, $r['created']);
        $this->assertSame(47, TravelPackage::where('source', 'website')->count());

        $p = TravelPackage::where('source_ref', 'tour-1')->first();
        $this->assertSame('WEB-tour-1', $p->code);
        $this->assertSame('Tour 1', $p->name);
        $this->assertSame('Bali', $p->destination);
        $this->assertSame('international', $p->category);
        $this->assertSame([4, 5], [$p->nights, $p->days]);
        $this->assertEquals(36000, $p->adult_price);
        $this->assertSame("4 nights hotel\nBreakfast", $p->inclusions);
        $this->assertSame('Airfare', $p->exclusions);
        $this->assertStringContainsString('Pick-up: Denpasar airport', $p->terms);
        $this->assertStringContainsString("PLEASE CARRY\nPassport", $p->terms);
        $this->assertSame('https://web.test/photos/tour-1.jpg', $p->image_url);
        $this->assertSame('https://web.test/tours/tour-1', $p->website_url);
        $this->assertTrue($p->active);
        $this->assertSame(['Arrive', 'Ubud tour'], $p->itineraryDays()->pluck('title')->all());
        $this->assertSame('Transfer to the hotel.', $p->itineraryDays()->first()->description);

        $g = TravelPackage::where('source_ref', 'bali-group')->first();
        $this->assertSame('group', $g->category);
        $this->assertSame(['2027-02-06', '2027-02-20'], PackageDeparture::where('package_id', $g->id)->orderBy('start_date')->get()->map(fn ($d) => $d->start_date->format('Y-m-d'))->all());
        $this->assertSame('domestic', TravelPackage::where('source_ref', 'kerala-india')->value('category'));
        $this->assertEquals(0, TravelPackage::where('source_ref', 'kerala-india')->value('adult_price'));   // no website price: zero until the team sets one
        $this->assertNull(TravelPackage::where('source_ref', 'kerala-india')->value('source_price'));
    }

    public function test_a_second_sync_changes_only_what_changed_and_never_touches_what_the_team_owns(): void
    {
        $this->site(['a' => $this->tour('a'), 'b' => $this->tour('b')]);
        $this->sync();
        $a = TravelPackage::where('source_ref', 'a')->first();
        $a->forceFill(['child_price' => 21000, 'adult_cost' => 30000, 'child_cost' => 15000, 'gst_rate' => 12, 'active' => false])->save();
        $assignee = User::factory()->sales()->create();
        DB::table('package_user')->insert(['package_id' => $a->id, 'user_id' => $assignee->id, 'created_at' => now()]);

        $this->site(['a' => $this->tour('a', ['title' => 'A renamed', 'price' => 40000, 'inclusions' => ['Everything']]), 'b' => $this->tour('b')]);
        $r = $this->sync();
        $this->assertSame(1, $r['updated']);
        $this->assertSame(1, $r['unchanged']);
        $a->refresh();
        $this->assertSame('A renamed', $a->name);
        $this->assertEquals(40000, $a->adult_price);
        $this->assertSame('Everything', $a->inclusions);
        $this->assertEquals([21000, 30000, 15000, 12], [$a->child_price, $a->adult_cost, $a->child_cost, $a->gst_rate]);   // the team's figures stay
        $this->assertFalse($a->active);                                                                                     // so does hiding it
        $this->assertSame(1, DB::table('package_user')->where('package_id', $a->id)->count());                              // and who sees it
    }

    public function test_a_tour_that_leaves_the_website_is_hidden_not_deleted_and_comes_back_when_it_returns(): void
    {
        $this->site(['a' => $this->tour('a'), 'b' => $this->tour('b')]);
        $this->sync();
        $this->site(['a' => $this->tour('a')]);
        $r = $this->sync();
        $this->assertSame(1, $r['deactivated']);
        $b = TravelPackage::where('source_ref', 'b')->first();
        $this->assertFalse($b->active);
        $this->assertNotNull($b->source_removed_at);

        $this->site(['a' => $this->tour('a'), 'b' => $this->tour('b')]);
        $r = $this->sync();
        $this->assertSame(1, $r['reactivated']);
        $this->assertTrue($b->fresh()->active);
        $this->assertNull($b->fresh()->source_removed_at);
    }

    public function test_a_broken_answer_never_hides_or_deletes_anything(): void
    {
        $this->site(['a' => $this->tour('a'), 'b' => $this->tour('b')]);
        $this->sync();

        $this->site(['a' => $this->tour('a'), 'b' => $this->tour('b')], broken: ['b']);   // one detail fails
        $r = $this->sync();
        $this->assertSame(1, $r['failed']);
        $this->assertTrue(TravelPackage::where('source_ref', 'b')->value('active'));      // not treated as gone

        $this->site([], listDown: false);                                                  // the website says it has no tours at all
        try {
            $this->sync();
            $this->fail('an empty website should stop the sync');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no tours', $e->getMessage());
        }
        $this->site(['a' => $this->tour('a')], listDown: true);                            // the website is down
        try {
            $this->sync();
            $this->fail('a website that is down should stop the sync');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not answer', $e->getMessage());
        }
        $this->assertSame(2, TravelPackage::where('source', 'website')->where('active', true)->count());
    }

    public function test_the_website_fields_are_locked_until_a_manager_unlocks_the_package_and_an_unlocked_package_is_left_alone(): void
    {
        $this->site(['a' => $this->tour('a'), 'u' => $this->tour('u', ['price' => null])]);
        $this->sync();
        Sanctum::actingAs(User::factory()->operations()->create(), ['*'], 'sanctum');
        $a = TravelPackage::where('source_ref', 'a')->first();

        $res = $this->patchJson("/api/packages/{$a->id}", ['name' => 'Hacked', 'adult_price' => 1, 'child_price' => 22000, 'adult_cost' => 29000, 'active' => false])->assertOk();
        $res->assertJsonPath('name', 'A')->assertJsonPath('child_price', 22000);                      // the team's own fields are saved
        $this->assertEquals(36000, $a->fresh()->adult_price);                                          // the website price is not
        $this->assertContains('adult_price', $res->json('locked'));
        $this->assertFalse($a->fresh()->active);

        // a tour with no website price: the adult price stays editable
        $u = TravelPackage::where('source_ref', 'u')->first();
        $this->patchJson("/api/packages/{$u->id}", ['adult_price' => 45000])->assertOk();
        $this->assertEquals(45000, $u->fresh()->adult_price);
        $this->assertNotContains('adult_price', $this->getJson("/api/packages/{$u->id}")->json('locked'));
        $this->site(['a' => $this->tour('a'), 'u' => $this->tour('u', ['price' => null, 'title' => 'U changed'])]);
        $this->sync();
        $this->assertEquals(45000, $u->fresh()->adult_price);                                          // a sync does not wipe it either

        $this->deleteJson("/api/packages/{$a->id}")->assertStatus(409);                                // it would only come back

        $this->postJson("/api/packages/{$a->id}/sync-lock", ['unlocked' => true])->assertOk()->assertJsonPath('locked', []);
        $this->patchJson("/api/packages/{$a->id}", ['name' => 'Our own name', 'adult_price' => 39000])->assertOk()->assertJsonPath('name', 'Our own name');
        $this->site(['a' => $this->tour('a', ['title' => 'Website new title']), 'u' => $this->tour('u')]);
        $r = $this->sync();
        $this->assertSame(1, $r['skipped_unlocked']);
        $this->assertSame('Our own name', $a->fresh()->name);                                          // the sync leaves it alone

        $this->postJson("/api/packages/{$a->id}/sync-lock", ['unlocked' => false])->assertOk();
        $this->sync();
        $this->assertSame('Website new title', $a->fresh()->name);                                     // handed back: the website decides again
        $local = TravelPackage::create(['code' => 'LOC-1', 'name' => 'Local', 'category' => 'domestic']);
        $this->postJson("/api/packages/{$local->id}/sync-lock", ['unlocked' => true])->assertStatus(409);
    }

    public function test_the_sync_button_needs_package_management_and_reports_what_happened(): void
    {
        $this->site(['a' => $this->tour('a')]);
        Sanctum::actingAs(User::factory()->sales()->create(), ['*'], 'sanctum');
        $this->postJson('/api/packages/sync-website')->assertStatus(403);

        Sanctum::actingAs(User::factory()->operations()->create(), ['*'], 'sanctum');
        $this->postJson('/api/packages/sync-website')->assertOk()->assertJsonPath('created', 1);
        $this->getJson('/api/packages/options')->assertOk()->assertJsonPath('website.packages', 1);

        $this->site([], listDown: true);
        $this->postJson('/api/packages/sync-website')->assertStatus(502);
        $this->artisan('packages:sync-website')->assertFailed();
        config(['crm.website.sync' => false]);
        $this->artisan('packages:sync-website')->assertSuccessful();                                    // paused: does nothing
    }

    // ------------------------------------------------------------------ who sees which package

    public function test_sales_see_only_the_packages_assigned_to_them_while_operations_and_admin_see_all(): void
    {
        $bali = TravelPackage::create(['code' => 'P1', 'name' => 'Bali Escape', 'destination' => 'Bali', 'category' => 'international']);
        $goa = TravelPackage::create(['code' => 'P2', 'name' => 'Goa Break', 'destination' => 'Goa', 'category' => 'domestic']);
        $sales = User::factory()->sales()->create();
        DB::table('package_user')->insert(['package_id' => $bali->id, 'user_id' => $sales->id, 'created_at' => now()]);

        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $names = fn () => collect($this->getJson('/api/packages')->assertOk()->json('items') ?? $this->getJson('/api/packages')->json())->pluck('name')->all();
        $this->assertSame(['Bali Escape'], $names());
        $this->getJson("/api/packages/{$bali->id}")->assertOk();
        $this->getJson("/api/packages/{$goa->id}")->assertStatus(404);
        $this->getJson("/api/packages/{$goa->id}/price")->assertStatus(404);
        $this->assertSame(['Bali'], $this->getJson('/api/packages/options')->json('destinations'));      // not even the destination list leaks
        $this->assertArrayNotHasKey('assignees', $this->getJson("/api/packages/{$bali->id}")->json());

        foreach ([User::factory()->operations()->create(), User::factory()->admin()->create()] as $u) {
            Sanctum::actingAs($u, ['*'], 'sanctum');
            $this->assertEqualsCanonicalizing(['Bali Escape', 'Goa Break'], $names());
            $this->getJson("/api/packages/{$goa->id}")->assertOk();
        }
    }

    public function test_a_salesperson_can_only_quote_a_package_they_can_see(): void
    {
        $bali = TravelPackage::create(['code' => 'P1', 'name' => 'Bali Escape', 'destination' => 'Bali', 'category' => 'international']);
        $goa = TravelPackage::create(['code' => 'P2', 'name' => 'Goa Break', 'destination' => 'Goa', 'category' => 'domestic']);
        $sales = User::factory()->sales()->create();
        DB::table('package_user')->insert(['package_id' => $bali->id, 'user_id' => $sales->id, 'created_at' => now()]);
        $cust = Customer::create(['name' => 'Asha', 'customer_no' => 'C-1', 'owner_id' => $sales->id, 'created_by' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $body = ['customer_id' => $cust->id, 'title' => 'Trip', 'lines' => [['description' => 'Stay', 'quantity' => 1, 'unit_price' => 1000]]];
        $this->postJson('/api/quotes', $body + ['package_id' => $goa->id])->assertStatus(422);
        $id = $this->postJson('/api/quotes', $body + ['package_id' => $bali->id])->assertStatus(200)->json('id');

        DB::table('package_user')->where('user_id', $sales->id)->delete();                              // the assignment is withdrawn later
        $this->patchJson("/api/quotes/$id", ['title' => 'Trip (renamed)', 'package_id' => $bali->id])->assertOk();   // the quote keeps its own package
        $this->patchJson("/api/quotes/$id", ['package_id' => $goa->id])->assertStatus(422);
    }

    public function test_managers_assign_packages_one_by_one_in_bulk_or_by_destination(): void
    {
        $bali = TravelPackage::create(['code' => 'P1', 'name' => 'Bali Escape', 'destination' => 'Bali', 'category' => 'international']);
        $bali2 = TravelPackage::create(['code' => 'P2', 'name' => 'Bali Luxe', 'destination' => 'bali', 'category' => 'international']);
        $goa = TravelPackage::create(['code' => 'P3', 'name' => 'Goa Break', 'destination' => 'Goa', 'category' => 'domestic']);
        $a = User::factory()->sales()->create(['destinations' => ['Bali']]);
        $b = User::factory()->sales()->create();
        $ops = User::factory()->operations()->create();

        Sanctum::actingAs($a, ['*'], 'sanctum');
        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id], 'package_ids' => [$goa->id], 'mode' => 'add'])->assertStatus(403);   // sales cannot give themselves packages
        $this->putJson("/api/packages/{$goa->id}/assignees", ['user_ids' => [$a->id]])->assertStatus(403);

        Sanctum::actingAs($ops, ['*'], 'sanctum');
        $people = collect($this->getJson('/api/packages/assignable-users')->assertOk()->json());
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $people->pluck('id')->all());   // sales only, never Operations or Admin
        $this->assertSame(['Bali'], $people->firstWhere('id', (string) $a->id)['destinations']);

        // by destination: every Bali package, whatever the capitalisation
        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id], 'destinations' => ['Bali'], 'mode' => 'add'])->assertOk()->assertJson(['packages' => 2, 'users' => 1, 'changed' => 2]);
        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id], 'destinations' => ['Bali'], 'mode' => 'add'])->assertOk()->assertJson(['changed' => 0]);   // nothing twice
        $this->assertSame(2, DB::table('package_user')->where('user_id', $a->id)->count());

        // several people, chosen packages
        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id, $b->id, $ops->id], 'package_ids' => [$goa->id], 'mode' => 'add'])->assertOk()->assertJson(['users' => 2, 'changed' => 2]);   // Operations is ignored
        $this->assertSame(0, DB::table('package_user')->where('user_id', $ops->id)->count());

        // exactly this set on one package
        $res = $this->putJson("/api/packages/{$goa->id}/assignees", ['user_ids' => [$b->id]])->assertOk()->json('assignees');
        $this->assertSame([(string) $b->id], array_column($res, 'id'));
        $this->assertSame([(string) $b->id], array_column($this->getJson("/api/packages/{$goa->id}")->json('assignees'), 'id'));
        $this->assertFalse(DB::table('package_user')->where('package_id', $goa->id)->where('user_id', $a->id)->exists());

        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id], 'destinations' => ['Bali'], 'mode' => 'remove'])->assertOk()->assertJson(['changed' => 2]);
        $this->assertSame(0, DB::table('package_user')->where('user_id', $a->id)->count());
        $this->postJson('/api/packages/assign', ['user_ids' => [$a->id], 'mode' => 'add'])->assertStatus(422);   // choose packages or destinations
        $this->assertSame(3, collect($this->getJson('/api/packages')->json('items') ?? $this->getJson('/api/packages')->json())->count());   // Operations still see all
    }
}
