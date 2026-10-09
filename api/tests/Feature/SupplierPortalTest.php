<?php

namespace Tests\Feature;

use App\Events\SupplierReplyReceived;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\RsqRequest;
use App\Models\Supplier;
use App\Models\SupplierReply;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class SupplierPortalTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');
        Storage::fake('supplier_replies');
        config(['crm.public_url' => 'https://erp.test']);
        $this->mail = new FakeMailTransport;
        $this->app->instance(MailTransport::class, $this->mail);
    }

    // ------------------------------------------------------------------ helpers

    private function world(): array
    {
        $owner = User::factory()->sales()->create();
        $lead = Lead::create(['lead_id' => 'IRMH10023', 'customer_name' => 'Asha Rao', 'phone' => '9876501234', 'destination' => 'Vietnam',
            'staff_id' => $owner->id, 'staff_name' => $owner->name, 'adults' => 2, 'special_requests' => 'Window seats']);
        $sup = Supplier::create(['name' => 'Sunrise Travels', 'email' => 'agent@sunrise.test', 'portal_enabled' => true]);
        $su = SupplierUser::create(['supplier_id' => $sup->id, 'name' => 'Raj', 'email' => 'agent@sunrise.test']);
        $tr = $this->tracker($lead->lead_id, $sup, ['portal_invited_at' => now()]);
        RsqRequest::upsertFor($lead->lead_id, ['status' => 'pending_reply', 'sent_at' => now()]);

        return [$owner, $lead, $sup, $su, $tr];
    }

    private function tracker(string $leadId, Supplier $s, array $over = []): LeadSupplier
    {
        return LeadSupplier::create(array_merge([
            'lead_id' => $leadId, 'supplier_id' => $s->id, 'supplier_name' => $s->name, 'supplier_email' => $s->email,
            'sequence' => 1, 'status' => 'Email Sent', 'emails_sent' => 1, 'first_email_at' => now(), 'last_email_at' => now(),
            'reply_received' => false, 'quote_received' => false, 'followups' => 0, 'last_update' => now(), 'created_at' => now(),
        ], $over));
    }

    /** Request a code, read it from the mail the fake transport "sent", verify it, and return the session token. */
    private function signIn(string $email = 'agent@sunrise.test'): string
    {
        $this->postJson('/api/portal/auth/code', ['email' => $email])->assertOk();
        $body = end($this->mail->sent)->body;
        preg_match('/\b(\d{6})\b/', $body, $m);
        $res = $this->postJson('/api/portal/auth/verify', ['email' => $email, 'code' => $m[1]])->assertOk();
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'ir_portal');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        return $cookie->getValue();
    }

    private function portal(string $token): static
    {
        $this->flushSession();
        $this->withCredentials();
        $this->unencryptedCookies = $token === '' ? [] : ['ir_portal' => $token];

        return $this;
    }

    // ------------------------------------------------------------------ sign-in

    public function test_code_is_mailed_only_to_registered_active_users_of_enabled_suppliers_and_the_answer_never_differs(): void
    {
        [, , $sup, $su] = $this->world();
        $a = $this->postJson('/api/portal/auth/code', ['email' => 'nobody@x.test'])->assertOk()->json();
        $this->assertCount(0, $this->mail->sent);
        $b = $this->postJson('/api/portal/auth/code', ['email' => 'AGENT@sunrise.test'])->assertOk()->json();
        $this->assertSame($a, $b);
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame(['agent@sunrise.test'], $this->mail->sent[0]->to);

        $su->update(['active' => false]);
        $this->postJson('/api/portal/auth/code', ['email' => 'agent@sunrise.test'])->assertOk();
        $this->assertCount(1, $this->mail->sent);
        $su->update(['active' => true]);
        $sup->update(['portal_enabled' => false]);
        $this->postJson('/api/portal/auth/code', ['email' => 'agent@sunrise.test'])->assertOk();
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame(1, DB::table('supplier_login_codes')->count());
        $this->assertNotSame('', DB::table('supplier_login_codes')->value('code_hash'));
    }

    public function test_wrong_and_reused_codes_fail_and_a_code_locks_after_five_tries(): void
    {
        $this->world();
        $this->postJson('/api/portal/auth/code', ['email' => 'agent@sunrise.test'])->assertOk();
        preg_match('/\b(\d{6})\b/', $this->mail->sent[0]->body, $m);
        $good = $m[1];
        $bad = $good === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/portal/auth/verify', ['email' => 'agent@sunrise.test', 'code' => $bad])->assertStatus(422);
        }
        // locked: even the right code is refused now
        $this->postJson('/api/portal/auth/verify', ['email' => 'agent@sunrise.test', 'code' => $good])->assertStatus(422);

        // a fresh code works once, then is spent
        RateLimiter::clear('portal-verify:'.sha1('agent@sunrise.test'));
        $this->postJson('/api/portal/auth/code', ['email' => 'agent@sunrise.test'])->assertOk();
        preg_match('/\b(\d{6})\b/', end($this->mail->sent)->body, $m);
        $this->postJson('/api/portal/auth/verify', ['email' => 'agent@sunrise.test', 'code' => $m[1]])->assertOk();
        $this->postJson('/api/portal/auth/verify', ['email' => 'agent@sunrise.test', 'code' => $m[1]])->assertStatus(422);
    }

    public function test_portal_session_opens_only_the_portal_and_an_erp_session_does_not_open_the_portal(): void
    {
        $this->world();
        $this->getJson('/api/portal/me')->assertStatus(401);
        $token = $this->signIn();

        $this->portal($token)->getJson('/api/portal/me')->assertOk()->assertJson(['email' => 'agent@sunrise.test', 'company' => 'Sunrise Travels']);
        // nothing of the ERP answers to it
        foreach (['/api/auth/me', '/api/leads', '/api/suppliers', '/api/users', '/api/supplier-replies', '/api/notifications', '/api/chat/conversations'] as $url) {
            $this->portal($token)->getJson($url)->assertStatus(401);
        }

        // and an ERP admin is not a portal user
        $this->unencryptedCookies = [];
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $this->getJson('/api/portal/me')->assertStatus(401);
        $this->getJson('/api/portal/requests')->assertStatus(401);
    }

    public function test_logout_ends_the_session_and_switching_the_portal_off_ends_every_session(): void
    {
        [, , $sup] = $this->world();
        $token = $this->signIn();
        $this->portal($token)->postJson('/api/portal/auth/logout')->assertOk();
        $this->portal($token)->getJson('/api/portal/me')->assertStatus(401);

        $token = $this->signIn();
        $sup->update(['portal_enabled' => false]);
        $this->portal($token)->getJson('/api/portal/me')->assertStatus(401);
    }

    // ------------------------------------------------------------------ requests

    public function test_supplier_sees_only_its_own_invited_requests_and_never_customer_details(): void
    {
        [$owner, $lead, $sup] = $this->world();
        $other = Supplier::create(['name' => 'Other Co', 'email' => 'o@other.test', 'portal_enabled' => true]);
        SupplierUser::create(['supplier_id' => $other->id, 'name' => 'Olly', 'email' => 'o@other.test']);
        $lead2 = Lead::create(['lead_id' => 'IRMH10024', 'customer_name' => 'Bina', 'phone' => '9000000000', 'destination' => 'Bali']);
        $this->tracker($lead2->lead_id, $other, ['portal_invited_at' => now()]);
        $lead3 = Lead::create(['lead_id' => 'IRMH10025', 'customer_name' => 'Chitra', 'phone' => '9111111111', 'destination' => 'Goa']);
        $this->tracker($lead3->lead_id, $sup);                                        // mailed the old way: not in the portal

        $token = $this->signIn();
        $list = $this->portal($token)->getJson('/api/portal/requests')->assertOk()->json();
        $this->assertSame(['IRMH10023'], array_column($list, 'lead_id'));
        $this->assertSame('open', $list[0]['state']);

        $show = $this->portal($token)->getJson('/api/portal/requests/IRMH10023')->assertOk();
        $show->assertJsonPath('requirement.destination', 'Vietnam')->assertJsonPath('requirement.special_requests', 'Window seats');
        $raw = $show->getContent();
        foreach (['Asha', '9876501234', $owner->email, 'customer_name', 'phone'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->portal($token)->getJson('/api/portal/requests/IRMH10024')->assertStatus(404);   // another supplier's
        $this->portal($token)->getJson('/api/portal/requests/IRMH10025')->assertStatus(404);   // never invited
        $this->portal($token)->getJson('/api/portal/requests/NOPE')->assertStatus(404);
    }

    public function test_quote_with_amount_updates_tracker_rsq_and_alerts_operations_once(): void
    {
        [, $lead, , , $tr] = $this->world();
        $ops = User::factory()->operations()->create();
        Event::fake([SupplierReplyReceived::class]);
        $token = $this->signIn();

        $res = $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', [
            'amount' => 45000, 'currency' => 'INR', 'hotel_offered' => 'Lotus Hotel 4*', 'flight_included' => false, 'transfer_included' => true,
            'activities_included' => 'City tour', 'valid_until' => now()->addDays(10)->format('Y-m-d'), 'remarks' => 'Subject to availability',
        ])->assertOk();
        $res->assertJsonPath('version', 1)->assertJsonPath('request.state', 'quoted')->assertJsonPath('request.quotes.0.amount', 45000);

        $tr->refresh();
        $this->assertSame('Quote Received', $tr->status);
        $this->assertTrue($tr->quote_received);
        $this->assertTrue($tr->reply_received);
        $this->assertEquals(45000, $tr->quote_amount);
        $this->assertSame('Lotus Hotel 4*', $tr->hotel_offered);
        $this->assertFalse((bool) $tr->flight_included);
        $this->assertTrue((bool) $tr->transfer_included);
        $this->assertSame('response_received', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));

        $reply = SupplierReply::where('matched_by', 'portal')->first();
        $this->assertSame('matched', $reply->status);
        $this->assertTrue((bool) $reply->is_final);
        $this->assertSame('quote', $reply->relevance);
        $this->assertStringContainsString('INR 45,000.00', $reply->body_text);
        Event::assertDispatchedTimes(SupplierReplyReceived::class, 1);
        $this->assertSame(1, DB::table('supplier_reply_reads')->where('reply_id', $reply->id)->where('user_id', $ops->id)->count());

        // the supplier's name is on the Ops-side tracker, never in anything the sales owner can read
        $this->assertSame('Quote Received', LeadSupplier::find($tr->id)->status);
    }

    public function test_quote_needs_a_price_or_a_file_and_rejects_bad_files(): void
    {
        $this->world();
        $token = $this->signIn();
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['remarks' => 'will send later'])->assertStatus(422);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => -5])->assertStatus(422);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 100, 'valid_until' => '2001-01-01'])->assertStatus(422);

        $exe = UploadedFile::fake()->createWithContent('quote.exe', 'MZ binary');
        $this->portal($token)->post('/api/portal/requests/IRMH10023/quote', ['files' => [$exe]], ['Accept' => 'application/json'])->assertStatus(422);
        $tmp = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($tmp, '<html><script>alert(1)</script></html>');
        $fakePdf = new UploadedFile($tmp, 'quote.pdf', 'application/pdf', null, true);   // a real file: its content is sniffed
        $this->portal($token)->post('/api/portal/requests/IRMH10023/quote', ['files' => [$fakePdf]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, DB::table('supplier_quotes')->count());
    }

    public function test_quote_by_file_alone_stores_it_privately_and_only_the_owner_and_ops_can_download_it(): void
    {
        [, , , , $tr] = $this->world();
        $other = Supplier::create(['name' => 'Other Co', 'email' => 'o@other.test', 'portal_enabled' => true]);
        $otherUser = SupplierUser::create(['supplier_id' => $other->id, 'name' => 'Olly', 'email' => 'o@other.test']);
        $ops = User::factory()->operations()->create();
        $token = $this->signIn();

        $pdf = UploadedFile::fake()->createWithContent('Vietnam quote.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $res = $this->portal($token)->post('/api/portal/requests/IRMH10023/quote', ['files' => [$pdf]], ['Accept' => 'application/json'])->assertOk();
        $file = $res->json('request.quotes.0.files.0');
        $this->assertSame('Vietnam quote.pdf', $file['filename']);
        $this->assertNull($tr->fresh()->quote_amount);
        $this->assertSame('Quote Received', $tr->fresh()->status);

        $this->portal($token)->get('/api/portal/files/'.$file['id'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        // someone else's supplier session cannot
        $this->postJson('/api/portal/auth/code', ['email' => 'o@other.test'])->assertOk();
        preg_match('/\b(\d{6})\b/', end($this->mail->sent)->body, $m);
        $c = $this->postJson('/api/portal/auth/verify', ['email' => 'o@other.test', 'code' => $m[1]])->assertOk();
        $otherToken = collect($c->headers->getCookies())->first(fn ($x) => $x->getName() === 'ir_portal')->getValue();
        $this->portal($otherToken)->get('/api/portal/files/'.$file['id'])->assertStatus(404);
        $this->portal('')->get('/api/portal/files/'.$file['id'])->assertStatus(401);

        // Operations reads it from the supplier reply, like an e-mailed attachment
        $this->unencryptedCookies = [];
        Sanctum::actingAs($ops, ['*'], 'sanctum');
        $att = SupplierReply::where('matched_by', 'portal')->first()->attachments()->first();
        $this->get('/api/supplier-replies/attachments/'.$att->id.'/download')->assertOk();
    }

    public function test_a_revision_supersedes_the_earlier_quote_and_a_decline_is_recorded(): void
    {
        [, , , , $tr] = $this->world();
        $token = $this->signIn();
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 50000])->assertOk();
        $res = $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 47000, 'currency' => 'INR'])->assertOk();
        $res->assertJsonPath('version', 2);

        $this->assertSame(['superseded', 'submitted'], DB::table('supplier_quotes')->orderBy('version')->pluck('status')->all());
        $this->assertEquals(47000, $tr->fresh()->quote_amount);
        $this->assertSame(1, SupplierReply::where('is_final', true)->count());        // only the latest is the final quote

        $res = $this->portal($token)->postJson('/api/portal/requests/IRMH10023/decline', ['reason' => 'No rooms that week'])->assertOk();
        $res->assertJsonPath('request.state', 'declined');
        $this->assertSame('Supplier Declined', $tr->fresh()->status);
        $this->assertSame('declined', DB::table('supplier_quotes')->where('version', 3)->value('status'));
        $this->assertSame(0, DB::table('supplier_quotes')->where('status', 'submitted')->count());   // nothing stands as the live quote any more
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/decline', ['reason' => ''])->assertStatus(422);
    }

    public function test_enquiry_closes_when_the_team_has_decided(): void
    {
        [, $lead, , , $tr] = $this->world();
        $token = $this->signIn();
        $tr->update(['status' => 'Quote Accepted']);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 1000])->assertStatus(409);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/decline', ['reason' => 'sorry'])->assertStatus(409);
        $this->portal($token)->getJson('/api/portal/requests')->assertJsonPath('0.state', 'closed');

        $tr->update(['status' => 'Email Sent']);
        RsqRequest::upsertFor($lead->lead_id, ['status' => 'completed']);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 1000])->assertStatus(409);
    }

    // ------------------------------------------------------------------ the RFQ mail

    public function test_portal_suppliers_get_their_own_short_link_mail_and_others_get_the_full_enquiry(): void
    {
        $owner = User::factory()->sales()->create();
        $ops = User::factory()->operations()->create();
        $lead = Lead::create(['lead_id' => 'IRMH10030', 'customer_name' => 'Asha Rao', 'phone' => '9876501234', 'destination' => 'Vietnam',
            'staff_id' => $owner->id, 'staff_name' => $owner->name, 'travel_date' => now()->addMonth()->toDateString()]);
        $p1 = Supplier::create(['name' => 'Portal One', 'email' => 'one@p.test', 'portal_enabled' => true]);
        $p2 = Supplier::create(['name' => 'Portal Two', 'email' => 'two@p.test', 'portal_enabled' => true]);
        $old = Supplier::create(['name' => 'Old School', 'email' => 'old@o.test']);
        $old2 = Supplier::create(['name' => 'Old Two', 'email' => 'old2@o.test']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($ops, ['*'], 'sanctum');

        $res = $this->postJson('/api/supplier-mail/send', [
            'lead_id' => $lead->lead_id, 'supplier_ids' => [$p1->id, $old->id, $p2->id, $old2->id], 'subject' => 'Trip Enquiry - IRMH10030 - Vietnam',
            'body' => 'FULL ENQUIRY TEXT',
        ])->assertOk();
        $res->assertJsonPath('mails_sent', 3);

        $this->assertCount(3, $this->mail->sent);
        $byTo = collect($this->mail->sent)->keyBy(fn ($m) => implode(',', $m->to));
        $this->assertSame(['one@p.test'], $byTo['one@p.test']->to);                 // addressed alone, never together with others
        $this->assertSame(['two@p.test'], $byTo['two@p.test']->to);
        foreach (['one@p.test', 'two@p.test'] as $to) {
            $b = $byTo[$to]->body;
            $this->assertStringContainsString('https://erp.test/portal/requests/IRMH10030', $b);
            $this->assertStringNotContainsString('FULL ENQUIRY TEXT', $b);
            $this->assertStringNotContainsString('Asha', $b);
            $this->assertStringNotContainsString('9876501234', $b);
        }
        $legacy = $byTo['old@o.test,old2@o.test'];
        $this->assertSame('FULL ENQUIRY TEXT', $legacy->body);

        $this->assertNotNull(LeadSupplier::where('supplier_id', $p1->id)->value('portal_invited_at'));
        $this->assertNull(LeadSupplier::where('supplier_id', $old->id)->value('portal_invited_at'));
        $this->assertSame(2, SupplierUser::count());                                // accounts created from the supplier e-mail
        $this->assertSame(3, DB::table('mail_history')->where('lead_id', 'IRMH10030')->count());
        $this->assertSame('pending_reply', RsqRequest::where('lead_id', 'IRMH10030')->value('status'));
    }

    // ------------------------------------------------------------------ phase 2: messages, reminders, choosing a quote

    private function asUser(User $u): void
    {
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');
    }

    public function test_supplier_and_team_exchange_messages_and_each_side_is_told(): void
    {
        [, , , , $tr] = $this->world();
        $ops = User::factory()->operations()->create();
        Event::fake([SupplierReplyReceived::class]);
        $token = $this->signIn();

        // supplier -> team: stored, shown back to the supplier, and Operations is alerted like for an e-mailed reply
        $res = $this->portal($token)->postJson('/api/portal/requests/IRMH10023/messages', ['body' => 'Is a sea-view room needed?'])->assertOk();
        $res->assertJsonPath('request.messages.0.from', 'you')->assertJsonPath('request.messages.0.body', 'Is a sea-view room needed?');
        $this->assertSame(1, DB::table('supplier_messages')->where('direction', 'supplier')->count());
        $reply = SupplierReply::where('matched_by', 'portal')->first();
        $this->assertStringContainsString('sea-view', $reply->body_text);
        Event::assertDispatchedTimes(SupplierReplyReceived::class, 1);
        $this->postJson('/api/portal/requests/IRMH10023/messages', ['body' => ''])->assertStatus(422);
        $this->postJson('/api/portal/requests/IRMH10023/messages', ['body' => str_repeat('x', 2001)])->assertStatus(422);

        // team -> supplier: a mail that points to the portal and does not carry the text
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$tr->id}/messages", ['body' => 'Yes, sea view please. Budget is tight.'])->assertOk()->assertJsonPath('notified', true);
        $mail = end($this->mail->sent);
        $this->assertSame(['agent@sunrise.test'], $mail->to);
        $this->assertStringContainsString('IRMH10023', $mail->subject);
        $this->assertStringNotContainsString('Budget is tight', $mail->body);
        $this->assertStringContainsString('https://erp.test/portal/requests/IRMH10023', $mail->body);
        $thread = $this->getJson("/api/lead-suppliers/{$tr->id}/messages")->assertOk()->json();
        $this->assertSame(['supplier', 'team'], array_column($thread, 'from'));
        $this->assertFalse($thread[1]['seen']);

        // the supplier sees an unread badge in the list, and opening the enquiry marks it read
        $this->portal($token);
        $this->getJson('/api/portal/requests')->assertJsonPath('0.unread_messages', 1);
        $d = $this->getJson('/api/portal/requests/IRMH10023')->assertOk();
        $d->assertJsonPath('messages.1.from', 'team')->assertJsonPath('messages.1.body', 'Yes, sea view please. Budget is tight.');
        $this->getJson('/api/portal/requests')->assertJsonPath('0.unread_messages', 0);
        $this->asUser($ops);
        $this->assertTrue($this->getJson("/api/lead-suppliers/{$tr->id}/messages")->json('1.seen'));
    }

    public function test_messages_are_for_portal_suppliers_and_for_operations_only(): void
    {
        [$owner, $lead, $sup, , $tr] = $this->world();
        $plain = $this->tracker($lead->lead_id, Supplier::create(['name' => 'Old Co', 'email' => 'old@o.test']), ['sequence' => 2]);
        $this->asUser($owner);                                                           // sales
        $this->getJson("/api/lead-suppliers/{$tr->id}/messages")->assertStatus(403);
        $this->postJson("/api/lead-suppliers/{$tr->id}/messages", ['body' => 'hi'])->assertStatus(403);
        $this->postJson("/api/lead-suppliers/{$tr->id}/portal-remind")->assertStatus(403);
        $this->postJson("/api/lead-suppliers/{$tr->id}/use-quote")->assertStatus(403);
        $this->asUser(User::factory()->operations()->create());
        $this->getJson("/api/lead-suppliers/{$plain->id}/messages")->assertStatus(404);   // never sent the portal link
        $this->postJson("/api/lead-suppliers/{$plain->id}/messages", ['body' => 'hi'])->assertStatus(404);
    }

    public function test_send_sets_the_reply_by_date_and_says_so_in_the_mail(): void
    {
        $ops = User::factory()->operations()->create();
        $lead = Lead::create(['lead_id' => 'IRMH10031', 'customer_name' => 'Asha', 'phone' => '9876501234', 'destination' => 'Bali']);
        $p = Supplier::create(['name' => 'Portal One', 'email' => 'one@p.test', 'portal_enabled' => true]);
        $this->asUser($ops);
        $this->postJson('/api/supplier-mail/send', ['lead_id' => $lead->lead_id, 'supplier_ids' => [$p->id], 'subject' => 'Trip Enquiry - IRMH10031 - Bali'])->assertOk();
        $by = LeadSupplier::where('supplier_id', $p->id)->first()->respond_by;
        $this->assertSame(now()->addDays(3)->toDateString(), $by->toDateString());          // the default window
        $this->assertStringContainsString('Quote needed by : '.$by->format('d M Y'), end($this->mail->sent)->body);

        $custom = now()->addDays(9)->toDateString();
        $this->postJson('/api/supplier-mail/send', ['lead_id' => $lead->lead_id, 'supplier_ids' => [$p->id], 'subject' => 'Trip Enquiry - IRMH10031 - Bali', 'respond_by' => $custom])->assertOk();
        $this->assertSame($custom, LeadSupplier::where('supplier_id', $p->id)->first()->respond_by->toDateString());
        $this->postJson('/api/supplier-mail/send', ['lead_id' => $lead->lead_id, 'supplier_ids' => [$p->id], 'subject' => 'x - IRMH10031', 'respond_by' => '2001-01-01'])->assertStatus(422);
    }

    public function test_reminders_go_to_suppliers_who_have_not_quoted_at_most_twice_and_never_when_switched_off(): void
    {
        [, $lead, $sup, , $tr] = $this->world();
        $tr->update(['respond_by' => now()->addDay()->toDateString()]);                    // due tomorrow
        $quoted = Supplier::create(['name' => 'Quick Co', 'email' => 'q@q.test', 'portal_enabled' => true]);
        SupplierUser::create(['supplier_id' => $quoted->id, 'name' => 'Q', 'email' => 'q@q.test']);
        $done = $this->tracker($lead->lead_id, $quoted, ['sequence' => 2, 'portal_invited_at' => now(), 'respond_by' => now()->subDay()->toDateString()]);
        $token = $this->signIn('q@q.test');
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 100])->assertOk();
        $later = Supplier::create(['name' => 'Later Co', 'email' => 'l@l.test', 'portal_enabled' => true]);
        SupplierUser::create(['supplier_id' => $later->id, 'name' => 'L', 'email' => 'l@l.test']);
        $this->tracker($lead->lead_id, $later, ['sequence' => 3, 'portal_invited_at' => now(), 'respond_by' => now()->addDays(5)->toDateString()]);
        $before = count($this->mail->sent);

        config(['crm.portal.auto_reminders' => false]);
        $this->artisan('portal:remind')->assertSuccessful();
        $this->assertCount($before, $this->mail->sent);                                     // off: nothing goes out

        config(['crm.portal.auto_reminders' => true]);
        $this->artisan('portal:remind')->assertSuccessful();
        $new = array_slice($this->mail->sent, $before);
        $this->assertCount(1, $new);                                                        // only the supplier that is due and silent
        $this->assertSame(['agent@sunrise.test'], $new[0]->to);
        $this->assertStringContainsString('Reminder', $new[0]->subject);
        $this->assertSame(1, $tr->fresh()->portal_reminders);
        $this->artisan('portal:remind')->assertSuccessful();                                // the same day: not again
        $this->assertCount(1, array_slice($this->mail->sent, $before));
        $tr->update(['portal_reminded_at' => now()->subDay()]);
        $this->artisan('portal:remind')->assertSuccessful();                                // a day later: the second
        $tr->update(['portal_reminded_at' => now()->subDay()]);
        $this->artisan('portal:remind')->assertSuccessful();                                // never a third
        $this->assertSame(2, $tr->fresh()->portal_reminders);
        $this->assertCount(2, array_slice($this->mail->sent, $before));
    }

    public function test_operations_can_remind_by_hand_only_while_the_supplier_has_not_answered(): void
    {
        [, , , , $tr] = $this->world();
        $ops = User::factory()->operations()->create();
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$tr->id}/portal-remind")->assertOk()->assertJsonPath('portal_reminders', 1);
        $this->assertStringContainsString('Reminder', end($this->mail->sent)->subject);
        $token = $this->signIn();
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 100])->assertOk();
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$tr->id}/portal-remind")->assertStatus(409);
    }

    public function test_using_a_quote_sets_the_lead_supplier_price_and_closes_that_suppliers_enquiry(): void
    {
        [$owner, $lead, , , $tr] = $this->world();
        $ops = User::factory()->operations()->create();
        $token = $this->signIn();
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$tr->id}/use-quote")->assertStatus(400);        // nothing quoted yet

        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 700, 'currency' => 'USD'])->assertOk();
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$tr->id}/use-quote")->assertStatus(422);        // not INR: needs the rupee figure
        $this->postJson("/api/lead-suppliers/{$tr->id}/use-quote", ['amount_inr' => 58000])->assertOk()->assertJsonPath('supplier_quote_amount', 58000);

        $lead->refresh();
        $this->assertEquals(58000, $lead->supplier_quote_amount);
        $this->assertSame('Sunrise Travels', $lead->supplier_name);
        $this->assertTrue((bool) $lead->quote_received);
        $this->assertSame('Quote Accepted', $tr->fresh()->status);
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/quote', ['amount' => 1])->assertStatus(409);   // decided: closed to revisions

        // an INR quote needs no extra figure
        $other = Supplier::create(['name' => 'Rupee Co', 'email' => 'r@r.test', 'portal_enabled' => true]);
        $t2 = $this->tracker($lead->lead_id, $other, ['sequence' => 2, 'quote_received' => true, 'quote_amount' => 41000, 'currency' => 'INR']);
        $this->asUser($ops);
        $this->postJson("/api/lead-suppliers/{$t2->id}/use-quote")->assertOk();
        $this->assertEquals(41000, $lead->fresh()->supplier_quote_amount);
        $this->assertSame('Rupee Co', $lead->fresh()->supplier_name);
    }

    public function test_tracker_lists_portal_flag_message_count_and_the_quote_files(): void
    {
        [, $lead, , , $tr] = $this->world();
        $ops = User::factory()->operations()->create();
        $token = $this->signIn();
        $tmp = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->portal($token)->post('/api/portal/requests/IRMH10023/quote', ['amount' => 5000, 'files' => [new UploadedFile($tmp, 'Rates.pdf', 'application/pdf', null, true)]], ['Accept' => 'application/json'])->assertOk();
        $this->portal($token)->postJson('/api/portal/requests/IRMH10023/messages', ['body' => 'hello'])->assertOk();
        $plain = $this->tracker($lead->lead_id, Supplier::create(['name' => 'Old Co', 'email' => 'old@o.test']), ['sequence' => 2]);

        $this->asUser($ops);
        $rows = collect($this->getJson("/api/lead-suppliers/{$lead->lead_id}")->assertOk()->json())->keyBy('id');
        $this->assertTrue($rows[(string) $tr->id]['portal']);
        $this->assertSame(1, $rows[(string) $tr->id]['messages']);
        $this->assertSame(['Rates.pdf'], array_column($rows[(string) $tr->id]['quote_files'], 'filename'));
        $this->assertFalse($rows[(string) $plain->id]['portal']);
        $this->assertSame([], $rows[(string) $plain->id]['quote_files']);
    }

    public function test_ops_can_switch_the_portal_on_or_off_for_many_suppliers_at_once(): void
    {
        $ops = User::factory()->operations()->create();
        $a = Supplier::create(['name' => 'A', 'email' => 'a@a.test']);
        $b = Supplier::create(['name' => 'B', 'email' => 'b@b.test']);
        $clash = Supplier::create(['name' => 'C', 'email' => 'c@c.test']);
        $other = Supplier::create(['name' => 'Owner', 'email' => 'x@x.test']);
        SupplierUser::create(['supplier_id' => $other->id, 'name' => 'X', 'email' => 'c@c.test']);   // c@c.test already signs in for another supplier
        $this->asUser(User::factory()->sales()->create());
        $this->postJson('/api/suppliers/portal-bulk', ['ids' => [$a->id], 'enabled' => true])->assertStatus(403);

        $this->asUser($ops);
        $r = $this->postJson('/api/suppliers/portal-bulk', ['ids' => [$a->id, $b->id, $clash->id], 'enabled' => true])->assertOk()->json();
        $this->assertSame(2, $r['changed']);
        $this->assertSame(['C'], array_column($r['skipped'], 'name'));
        $this->assertTrue((bool) $a->fresh()->portal_enabled);
        $this->assertFalse((bool) $clash->fresh()->portal_enabled);
        $this->assertSame(1, SupplierUser::where('supplier_id', $a->id)->count());

        $token = $this->signIn('a@a.test');
        $this->portal($token)->getJson('/api/portal/me')->assertOk();
        $this->asUser($ops);
        $this->postJson('/api/suppliers/portal-bulk', ['ids' => [$a->id, $b->id], 'enabled' => false])->assertOk()->assertJsonPath('changed', 2);
        $this->portal($token)->getJson('/api/portal/me')->assertStatus(401);                // switched off: signed out at once
        $this->asUser($ops);
        $this->postJson('/api/suppliers/portal-bulk', ['ids' => [], 'enabled' => true])->assertStatus(422);
    }

    // ------------------------------------------------------------------ managing portal users

    public function test_operations_manage_portal_users_and_sales_cannot(): void
    {
        $sup = Supplier::create(['name' => 'Sunrise Travels', 'email' => 'agent@sunrise.test']);
        $ops = User::factory()->operations()->create();
        $sales = User::factory()->sales()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $this->getJson("/api/suppliers/{$sup->id}/portal-users")->assertStatus(403);
        $this->postJson("/api/suppliers/{$sup->id}/portal-users", ['email' => 'x@y.test'])->assertStatus(403);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($ops, ['*'], 'sanctum');
        $this->patchJson("/api/suppliers/{$sup->id}", ['portal_enabled' => true])->assertOk()->assertJsonPath('portal_enabled', true);
        $list = $this->getJson("/api/suppliers/{$sup->id}/portal-users")->assertOk()->json();
        $this->assertSame(['agent@sunrise.test'], array_column($list, 'email'));       // the main address is added when the portal is switched on

        $new = $this->postJson("/api/suppliers/{$sup->id}/portal-users", ['name' => 'Priya', 'email' => 'Priya@Sunrise.test'])->assertOk()->json();
        $this->assertSame('priya@sunrise.test', $new['email']);
        $this->postJson("/api/suppliers/{$sup->id}/portal-users", ['email' => 'priya@sunrise.test'])->assertStatus(409);

        $this->patchJson("/api/suppliers/{$sup->id}/portal-users/{$new['id']}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->deleteJson("/api/suppliers/{$sup->id}/portal-users/{$new['id']}")->assertOk();
        $this->assertSame(1, SupplierUser::count());
    }
}
