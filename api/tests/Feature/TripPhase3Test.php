<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\Supplier;
use App\Models\TripTask;
use App\Models\User;
use App\Services\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SpaRequests;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class TripPhase3Test extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private FakeMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        Storage::fake('trip_documents');
        $this->mail = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $this->mail);
        config(['crm.public_url' => 'https://erp.example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role, string $browser): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->loginAs($u, $browser);

        return $u;
    }

    /** a confirmed domestic trip leaving in $days days, one traveller chosen */
    private function trip(User $owner, int $days = 30): array
    {
        $c = Customer::create(['customer_no' => 'IRC-P'.random_int(100, 999), 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'owner_id' => $owner->id]);
        $deal = Deal::create(['deal_no' => 'IRD-P'.random_int(100, 999), 'customer_id' => $c->id, 'name' => 'Goa', 'destination' => 'Goa', 'stage' => 'won', 'trip_type' => 'domestic',
            'travel_date' => now()->addDays($days)->toDateString(), 'owner_id' => $owner->id, 'selling_amount' => 50000, 'trip_confirmed_at' => now(), 'trip_status' => 'confirmed']);
        $t = CustomerTraveller::create(['customer_id' => $c->id, 'name' => 'Ravi Rao', 'dob' => '1985-03-01', 'position' => 0]);
        DB::table('trip_travellers')->insert(['deal_id' => $deal->id, 'traveller_id' => $t->id]);

        return [$deal, $t];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('aadhaar.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    public function test_customer_uploads_through_a_secret_link_without_logging_in(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal] = $this->trip($ops);
        $link = $this->api('POST', "/api/deals/{$deal->id}/trip/upload-link", ['days' => 10], 'o')->assertOk()->json();
        $this->assertStringStartsWith('https://erp.example.test/upload/', $link['url']);
        $token = basename($link['url']);
        $this->assertSame(0, DB::table('trip_upload_links')->where('token_hash', $token)->count());               // the secret itself is not stored
        $this->assertSame(1, DB::table('trip_upload_links')->where('token_hash', hash('sha256', $token))->count());

        $page = $this->getJson("/api/public/trip-upload/$token")->assertOk();
        $this->assertSame('Goa', $page->json('trip.destination'));
        $this->assertSame(['Ravi Rao'], collect($page->json('travellers'))->pluck('name')->all());
        $this->assertStringNotContainsString('passport_no', $page->getContent());
        $slot = collect($page->json('slots'))->firstWhere('label', 'ID proof (Aadhaar, voter ID or driving licence)');
        $this->assertSame(['pending', true, 0], [$slot['status'], $slot['can_upload'], $slot['files']]);

        $up = $this->post("/api/public/trip-upload/$token/slots/{$slot['id']}/files", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['received', 1], [collect($up->json('slots'))->firstWhere('id', $slot['id'])['status'], collect($up->json('slots'))->firstWhere('id', $slot['id'])['files']]);
        $this->assertNull(DB::table('trip_document_files')->value('uploaded_by'));                                   // uploaded by the customer, not a user
        $this->assertDatabaseHas('activity_log', ['action' => 'customer_upload_trip_document']);
        $this->assertNotNull(DB::table('trip_upload_links')->value('last_used_at'));

        // staff see it and can verify; after that the customer can no longer change it
        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'verified'], 'o')->assertOk();
        $this->post("/api/public/trip-upload/$token/slots/{$slot['id']}/files", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(409);
        $this->post("/api/public/trip-upload/$token/slots/99999/files", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(404);
        $bad = collect($page->json('slots'))->firstWhere('label', 'Signed booking terms');
        $this->post("/api/public/trip-upload/$token/slots/{$bad['id']}/files", ['file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_links_expire_get_revoked_and_new_links_replace_old_ones(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal] = $this->trip($ops);
        $a = basename($this->api('POST', "/api/deals/{$deal->id}/trip/upload-link", ['days' => 5], 'o')->json('url'));
        $this->getJson("/api/public/trip-upload/$a")->assertOk();
        $b = basename($this->api('POST', "/api/deals/{$deal->id}/trip/upload-link", [], 'o')->json('url'));
        $this->getJson("/api/public/trip-upload/$a")->assertStatus(410);                                             // replaced by the newer link
        $this->getJson("/api/public/trip-upload/$b")->assertOk();
        $this->getJson('/api/public/trip-upload/not-a-real-token-at-all-0000')->assertStatus(410);
        $st = $this->api('GET', "/api/deals/{$deal->id}/trip/upload-link", [], 'o')->assertOk();
        $this->assertTrue($st->json('active'));
        $this->assertSame('asha@example.com', $st->json('customer_email'));

        DB::table('trip_upload_links')->where('token_hash', hash('sha256', $b))->update(['expires_at' => now()->subMinute()]);      // past its expiry
        $this->getJson("/api/public/trip-upload/$b")->assertStatus(410);
        DB::table('trip_upload_links')->where('token_hash', hash('sha256', $b))->update(['expires_at' => now()->addDays(3)]);
        $this->api('DELETE', "/api/deals/{$deal->id}/trip/upload-link", [], 'o')->assertOk();
        $this->getJson("/api/public/trip-upload/$b")->assertStatus(410);
        $this->assertFalse($this->api('GET', "/api/deals/{$deal->id}/trip/upload-link", [], 'o')->json('active'));
    }

    public function test_the_request_can_be_e_mailed_to_the_customer_with_the_missing_documents(): void
    {
        $ops = $this->as('operations', 'o');
        $sales = $this->as('sales', 's');
        [$deal] = $this->trip($ops);
        $this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->assertOk();                             // creates the document rows
        $this->api('POST', "/api/deals/{$deal->id}/trip/upload-link/email", ['to' => ['asha@example.com'], 'days' => 7], 'o')->assertOk();
        $this->assertCount(1, $this->mail->sent);
        $html = $this->mail->sent[0]->body;
        $this->assertStringContainsString('https://erp.example.test/upload/', $html);
        $this->assertStringContainsString('Ravi Rao: ID proof', $html);
        $this->assertStringContainsString('Signed booking terms', $html);
        $this->assertSame('asha@example.com', $this->mail->sent[0]->to[0]);
        $this->assertSame('manual', DB::table('trip_doc_reminders')->value('trigger'));

        $this->mail->failSend = 'down';
        $this->api('POST', "/api/deals/{$deal->id}/trip/upload-link/email", ['to' => ['asha@example.com']], 'o')->assertStatus(502);
        $this->api('POST', "/api/deals/{$deal->id}/trip/upload-link/email", ['to' => ['bad']], 'o')->assertStatus(422);
        $mine = $this->trip($sales)[0];                                                                              // sales may request documents for their own trips
        $this->mail->failSend = null;
        $this->api('POST', "/api/deals/{$mine->id}/trip/upload-link/email", ['to' => ['asha@example.com']], 's')->assertOk();
        $this->api('POST', "/api/deals/{$deal->id}/trip/upload-link", [], 's')->assertStatus(404);                   // not their deal
    }

    public function test_daily_job_makes_one_task_per_person_and_trip_and_keeps_it_current(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal] = $this->trip($ops, 1);                                                                              // leaving tomorrow
        $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'Book the cab', 'due_date' => '2026-10-14', 'assigned_to' => (string) $ops->id, 'critical' => true], 'o')->assertOk();
        $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'Print vouchers', 'due_date' => '2026-10-15'], 'o')->assertOk();           // unassigned
        $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'Far away', 'due_date' => '2026-12-01'], 'o')->assertOk();

        Artisan::call('trips:remind', ['--dry-run' => true]);
        $this->assertSame(0, Activity::count());
        Artisan::call('trips:remind');
        $acts = Activity::where('deal_id', $deal->id)->get();
        $this->assertCount(1, $acts);                                                                                // the cab (assigned to ops) and the voucher item (unassigned: goes to the deal owner, also ops) in one task
        $this->assertStringStartsWith('Trip prep: 2 items due', $acts[0]->title);
        $this->assertSame([(string) $ops->id, 'high'], [(string) $acts[0]->assigned_to, $acts[0]->priority]);
        $this->assertStringContainsString('Book the cab', $acts[0]->details);
        $this->assertStringContainsString('Print vouchers', $acts[0]->details);
        $this->assertStringNotContainsString('Far away', $acts[0]->details);

        Artisan::call('trips:remind');                                                                               // running again changes nothing
        $this->assertSame(1, Activity::where('deal_id', $deal->id)->whereNull('completed_at')->count());

        foreach (TripTask::where('deal_id', $deal->id)->where('due_date', '<=', '2026-10-17')->get() as $t) {
            $this->api('PATCH', "/api/trip-tasks/{$t->id}", ['status' => 'done'], 'o')->assertOk();
        }
        Artisan::call('trips:remind');
        $this->assertSame(0, Activity::where('deal_id', $deal->id)->whereNull('completed_at')->count());             // nothing due: closed
    }

    public function test_customer_document_reminders_are_off_by_default_and_go_out_once_per_milestone(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal] = $this->trip($ops, 7);
        $this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->assertOk();
        Artisan::call('trips:remind');
        $this->assertCount(0, $this->mail->sent);                                                                    // off unless enabled

        config(['crm.trip.auto_doc_reminders' => true]);
        Artisan::call('trips:remind');
        $this->assertCount(1, $this->mail->sent);
        Artisan::call('trips:remind');
        $this->assertCount(1, $this->mail->sent);                                                                    // same milestone: not again
        $this->assertSame([7], DB::table('trip_doc_reminders')->where('trigger', 'auto')->pluck('days_before')->map(fn ($v) => (int) $v)->all());

        Carbon::setTestNow('2026-10-16 10:00:00');                                                                   // 6 days before: not a milestone
        Artisan::call('trips:remind');
        $this->assertCount(1, $this->mail->sent);
        Carbon::setTestNow('2026-10-19 10:00:00');                                                                   // 3 days before
        Artisan::call('trips:remind');
        $this->assertCount(2, $this->mail->sent);

        DB::table('customers')->update(['email' => null]);                                                           // no address: nothing to send
        Carbon::setTestNow('2026-10-15 10:00:00');
        DB::table('trip_doc_reminders')->delete();
        Artisan::call('trips:remind');
        $this->assertCount(2, $this->mail->sent);
    }

    public function test_supplier_and_cost_on_items_are_for_operations_and_feed_the_margin(): void
    {
        $ops = $this->as('operations', 'o');
        $sales = $this->as('sales', 's');
        [$deal] = $this->trip($sales);
        $sup = Supplier::create(['name' => 'Sunrise Travels', 'email' => 's@x.test']);
        $task = TripTask::create(['deal_id' => $deal->id, 'status' => 'pending', 'category' => 'transport', 'title' => 'Flights', 'position' => 1]);

        $r = $this->api('PATCH', "/api/trip-tasks/{$task->id}", ['cost' => 18000, 'supplier_id' => (string) $sup->id], 'o')->assertOk();
        $this->assertSame([18000.0, 'Sunrise Travels'], [(float) $r->json('cost'), $r->json('supplier.name')]);
        $this->api('PATCH', "/api/trip-tasks/{$task->id}", ['supplier_id' => '99999'], 'o')->assertStatus(422);
        $this->api('PATCH', "/api/trip-tasks/{$task->id}", ['cost' => -5], 'o')->assertStatus(422);
        $this->api('PATCH', "/api/trip-tasks/{$task->id}", ['cost' => 5000], 's')->assertStatus(403);                // sales cannot set costs

        $t = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->assertOk();
        $this->assertSame([18000.0, 1, 32000.0], [(float) $t->json('costs.planned'), $t->json('costs.items'), (float) $t->json('costs.margin')]);   // 50,000 trip value less 18,000
        $this->assertCount(1, $t->json('suppliers'));
        $s = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 's')->assertOk();                                  // sales: no costs, no supplier names
        $this->assertNull($s->json('costs'));
        $this->assertSame([], $s->json('suppliers'));
        $this->assertStringNotContainsString('Sunrise', $s->getContent());
        $this->assertArrayNotHasKey('cost', $s->json('tasks.0'));
    }

    public function test_readiness_lists_what_is_still_blocking_the_trip(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops, 5);
        $r = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->assertOk();
        $this->assertFalse($r->json('readiness.ready'));
        $this->assertContains('No checklist yet', $r->json('readiness.blockers'));

        $this->api('POST', "/api/deals/{$deal->id}/trip/tasks", ['title' => 'Tickets', 'critical' => true], 'o')->assertOk();
        $b = $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->json('readiness.blockers');
        $this->assertContains('1 critical item open', $b);
        $this->assertContains('₹50,000 balance unpaid', $b);

        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => []], 'o')->assertOk();
        $this->assertContains('Travellers not chosen', $this->api('GET', "/api/deals/{$deal->id}/trip", [], 'o')->json('readiness.blockers'));
        $board = $this->api('GET', '/api/trips', [], 'o')->assertOk();
        $this->assertSame(1, $board->json('attention'));                                                             // leaving in 5 days and not ready
    }
}
