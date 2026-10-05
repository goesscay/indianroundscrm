<?php

namespace Tests\Feature;

use App\Events\SupplierReplyReceived;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\MailHistory;
use App\Models\MailSyncState;
use App\Models\RsqRequest;
use App\Models\Supplier;
use App\Models\SupplierReply;
use App\Models\User;
use App\Services\Mail\AttachmentData;
use App\Services\Mail\AttachmentStore;
use App\Services\Mail\GraphMailTransport;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\MailTransportException;
use App\Services\Mail\MailTransportResolver;
use App\Services\Mail\OutgoingMail;
use App\Services\Mail\ReplyClassifier;
use App\Services\Mail\ReplyMatcher;
use App\Services\Mail\ReplyPoller;
use App\Services\Mail\ReplyText;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class SupplierMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('supplier_replies');
        config(['services.microsoft' => array_merge(config('services.microsoft'), [
            'transport' => null, 'tenant_id' => null, 'client_id' => null, 'client_secret' => null,
            'mailbox' => 'enquiry@indianrounds.in', 'processed_category' => 'ERP-Processed',
            'retry_max_wait' => 0, 'max_retries' => 2,
        ])]);
        $this->fake = new FakeMailTransport;
    }

    // ------------------------------------------------------------------ helpers

    private function useFake(): FakeMailTransport
    {
        $this->app->instance(MailTransport::class, $this->fake);

        return $this->fake;
    }

    private function useGraphConfig(): void
    {
        config(['services.microsoft' => array_merge(config('services.microsoft'), [
            'transport' => 'graph', 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'secret-1',
        ])]);
    }

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function lead(?User $owner, string $id = 'IRMH10023', string $customer = 'Asha Rao'): Lead
    {
        return Lead::create([
            'lead_id' => $id, 'customer_name' => $customer, 'phone' => '9'.random_int(100000000, 999999999),
            'destination' => 'Vietnam', 'staff_id' => $owner?->id, 'staff_name' => $owner?->name ?? '',
        ]);
    }

    private function supplier(string $email, string $name = 'Sunrise Travels'): Supplier
    {
        return Supplier::create(['name' => $name, 'email' => $email]);
    }

    private function tracker(string $leadId, Supplier $s, array $over = []): LeadSupplier
    {
        return LeadSupplier::create(array_merge([
            'lead_id' => $leadId, 'supplier_id' => $s->id, 'supplier_name' => $s->name, 'supplier_email' => $s->email,
            'sequence' => 1, 'status' => 'Email Sent', 'emails_sent' => 1, 'first_email_at' => now(), 'last_email_at' => now(),
            'reply_received' => false, 'quote_received' => false, 'followups' => 0, 'last_update' => now(), 'created_at' => now(),
        ], $over));
    }

    private function mailRow(string $leadId, array $over = []): MailHistory
    {
        return MailHistory::create(array_merge([
            'lead_id' => $leadId, 'subject' => "Trip Enquiry - $leadId - Vietnam", 'body' => 'x', 'sent_by' => 'a@b.c',
            'sent_at' => now(), 'status' => 'sent', 'provider' => 'graph',
        ], $over));
    }

    private function msg(array $o = []): InboundMessage
    {
        static $n = 0;
        $n++;

        return new InboundMessage(
            id: $o['id'] ?? "AAMk-$n",
            internetMessageId: $o['internetMessageId'] ?? "<reply-$n@supplier.test>",
            conversationId: $o['conversationId'] ?? null,
            inReplyTo: $o['inReplyTo'] ?? null,
            references: $o['references'] ?? [],
            fromEmail: $o['from'] ?? 'agent@sunrise.test',
            fromName: $o['fromName'] ?? 'Sunrise Agent',
            to: $o['to'] ?? ['enquiry@indianrounds.in'],
            cc: $o['cc'] ?? [],
            subject: $o['subject'] ?? 'Re: Trip Enquiry - IRMH10023 - Vietnam',
            body: $o['body'] ?? 'Hello, our quote is USD 1200 per person.',
            bodyType: $o['bodyType'] ?? 'text',
            receivedAt: $o['received'] ?? Carbon::now('UTC'),
            headers: $o['headers'] ?? [],
            categories: $o['categories'] ?? [],
            hasAttachments: $o['hasAttachments'] ?? false,
            isDraft: false,
        );
    }

    private function poll(): array
    {
        return app(ReplyPoller::class)->run();
    }

    /** owner (sales) + ops manager + a lead with one tracked supplier */
    private function world(): array
    {
        $owner = User::factory()->sales()->create(['name' => 'Olive Owner']);
        $ops = User::factory()->operations()->create(['name' => 'Ops Olga']);
        $lead = $this->lead($owner);
        $sup = $this->supplier('agent@sunrise.test');
        $tr = $this->tracker($lead->lead_id, $sup);
        RsqRequest::upsertFor($lead->lead_id, ['status' => 'pending_reply', 'sent_at' => now()]);

        return [$owner, $ops, $lead, $sup, $tr];
    }

    // ------------------------------------------------------------------ sending

    public function test_graph_send_creates_draft_then_sends_and_stores_ids(): void
    {
        $this->useGraphConfig();
        $ops = User::factory()->operations()->create();
        $lead = $this->lead($ops);
        $s1 = $this->supplier('one@sup.test', 'One');
        $s2 = $this->supplier('two@sup.test', 'Two');

        $calls = [];
        Http::fake(function (Request $r) use (&$calls) {
            $u = $r->url();
            $calls[] = $r->method().' '.preg_replace('~^https://[^/]+~', '', strtok($u, '?'));
            if (str_contains($u, '/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]);
            }
            if ($r->method() === 'POST' && str_ends_with($u, '/messages')) {
                return Http::response(['id' => 'DRAFT1'], 201);
            }
            if ($r->method() === 'GET' && str_contains($u, '/messages/DRAFT1')) {
                return Http::response(['id' => 'DRAFT1', 'conversationId' => 'CONV-X', 'internetMessageId' => '<abc@outlook.com>']);
            }
            if ($r->method() === 'POST' && str_ends_with($u, '/DRAFT1/send')) {
                return Http::response('', 202);
            }

            return Http::response('', 404);
        });

        $this->as($ops);
        $res = $this->postJson('/api/supplier-mail/send', [
            'lead_id' => $lead->lead_id, 'supplier_ids' => [(string) $s1->id, (string) $s2->id],
            'cc' => ['boss@indianrounds.in'], 'bcc' => ['audit@indianrounds.in'],
            'subject' => 'Trip Enquiry - '.$lead->lead_id.' - Vietnam', 'body' => 'Please quote.',
        ])->assertOk();

        $res->assertJsonPath('status', 'sent')->assertJsonPath('provider', 'graph')
            ->assertJsonPath('conversation_id', 'CONV-X')->assertJsonPath('internet_message_id', '<abc@outlook.com>')
            ->assertJsonPath('graph_message_id', 'DRAFT1')->assertJsonPath('send_result.status', 'sent');
        $this->assertSame([
            'POST /v1.0/users/enquiry%40indianrounds.in/messages',
            'GET /v1.0/users/enquiry%40indianrounds.in/messages/DRAFT1',
            'POST /v1.0/users/enquiry%40indianrounds.in/messages/DRAFT1/send',
        ], array_values(array_filter($calls, fn ($c) => ! str_contains($c, 'oauth2'))));

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/messages')) {
                return false;
            }
            $b = $r->data();

            return $b['from']['emailAddress']['address'] === 'enquiry@indianrounds.in'
                && $b['replyTo'][0]['emailAddress']['address'] === 'enquiry@indianrounds.in'
                && $b['body']['contentType'] === 'Text' && $b['body']['content'] === 'Please quote.'
                && array_column(array_column($b['toRecipients'], 'emailAddress'), 'address') === ['one@sup.test', 'two@sup.test']
                && $b['ccRecipients'][0]['emailAddress']['address'] === 'boss@indianrounds.in'
                && $b['bccRecipients'][0]['emailAddress']['address'] === 'audit@indianrounds.in'
                && $r->hasHeader('Authorization', 'Bearer tok-1');
        });

        $mh = MailHistory::first();
        $this->assertSame('sent', $mh->status);
        $this->assertSame('graph', $mh->provider);
        $this->assertSame(['DRAFT1', '<abc@outlook.com>', 'CONV-X'], [$mh->graph_message_id, $mh->internet_message_id, $mh->conversation_id]);
        $this->assertSame(2, LeadSupplier::where('lead_id', $lead->lead_id)->where('conversation_id', 'CONV-X')->count());
        $this->assertSame('pending_reply', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));
    }

    public function test_send_failure_is_recorded_not_500_and_orphan_draft_is_deleted(): void
    {
        $this->useGraphConfig();
        $ops = User::factory()->operations()->create();
        $lead = $this->lead($ops);
        $s = $this->supplier('one@sup.test');
        $deleted = false;
        Http::fake(function (Request $r) use (&$deleted) {
            $u = $r->url();
            if (str_contains($u, '/oauth2/')) {
                return Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }
            if ($r->method() === 'POST' && str_ends_with($u, '/messages')) {
                return Http::response(['id' => 'D9'], 201);
            }
            if ($r->method() === 'GET') {
                return Http::response(['conversationId' => 'C9', 'internetMessageId' => '<i9>']);
            }
            if ($r->method() === 'DELETE') {
                $deleted = true;

                return Http::response('', 204);
            }

            return Http::response(['error' => ['code' => 'ErrorSendAsDenied', 'message' => 'Not allowed to send as mailbox']], 403);
        });
        $this->as($ops);
        $res = $this->postJson('/api/supplier-mail/send', [
            'lead_id' => $lead->lead_id, 'supplier_ids' => [(string) $s->id], 'subject' => 'Enquiry '.$lead->lead_id, 'body' => 'b',
        ])->assertOk();
        $res->assertJsonPath('status', 'failed')->assertJsonPath('provider', 'graph');
        $this->assertStringContainsString('ErrorSendAsDenied', $res->json('error'));
        $this->assertTrue($deleted);
        $this->assertSame('failed', MailHistory::first()->status);
        $this->assertNull(MailHistory::first()->conversation_id);
    }

    public function test_transient_graph_errors_are_retried_and_429_is_honoured(): void
    {
        $this->useGraphConfig();
        $n = 0;
        Http::fake(function (Request $r) use (&$n) {
            if (str_contains($r->url(), '/oauth2/')) {
                return Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }
            $n++;

            return $n === 1 ? Http::response('', 429, ['Retry-After' => '7']) : ($n === 2 ? Http::response('', 503) : Http::response(['categories' => ['ERP-Processed']]));
        });
        (new GraphMailTransport)->markProcessed('M1'); // already categorised -> only GET
        $this->assertSame(3, $n);
    }

    public function test_graph_retries_are_bounded(): void
    {
        $this->useGraphConfig();
        $n = 0;
        Http::fake(function (Request $r) use (&$n) {
            if (str_contains($r->url(), '/oauth2/')) {
                return Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }
            $n++;

            return Http::response('', 500);
        });
        $this->expectException(MailTransportException::class);
        try {
            (new GraphMailTransport)->markProcessed('M1');
        } finally {
            $this->assertSame(3, $n); // 1 + max_retries(2)
        }
    }

    public function test_log_transport_never_touches_the_network(): void
    {
        Http::fake();
        $this->assertInstanceOf(\App\Services\Mail\LogMailTransport::class, MailTransportResolver::make());
        $ops = User::factory()->operations()->create();
        $lead = $this->lead($ops);
        $s = $this->supplier('one@sup.test');
        $this->as($ops);
        $res = $this->postJson('/api/supplier-mail/send', [
            'lead_id' => $lead->lead_id, 'supplier_ids' => [(string) $s->id], 'subject' => 'Enquiry', 'body' => 'b',
        ])->assertOk();
        $res->assertJsonPath('status', 'simulated_sent')->assertJsonPath('provider', 'log');
        Http::assertNothingSent();
        // the subject always carries the lead id even when the user removed it
        $this->assertSame('Enquiry - '.$lead->lead_id, MailHistory::first()->subject);
        $this->getJson('/api/supplier-mail/preview/'.$lead->lead_id)->assertOk()
            ->assertJsonPath('subject', "Trip Enquiry - {$lead->lead_id} - Vietnam");
        $this->getJson('/api/supplier-mail/'.$lead->lead_id)->assertOk()->assertJsonCount(1);
    }

    public function test_transport_selection_by_env(): void
    {
        $this->assertSame('log', MailTransportResolver::make()->name());
        $this->useGraphConfig();
        config(['services.microsoft.transport' => null]);
        $this->assertSame('graph', MailTransportResolver::make()->name());
        config(['services.microsoft.transport' => 'log']);
        $this->assertSame('log', MailTransportResolver::make()->name());
    }

    public function test_token_is_cached_and_refreshed_after_401(): void
    {
        $this->useGraphConfig();
        $tokenCalls = 0;
        $graph401 = true;
        Http::fake(function (Request $r) use (&$tokenCalls, &$graph401) {
            if (str_contains($r->url(), '/oauth2/')) {
                $tokenCalls++;

                return Http::response(['access_token' => 'tok-'.$tokenCalls, 'expires_in' => 3600]);
            }
            if ($graph401 && $r->hasHeader('Authorization', 'Bearer tok-1')) {
                return Http::response(['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'expired']], 401);
            }

            return Http::response(['categories' => ['ERP-Processed']]);
        });
        $t = new GraphMailTransport;
        $t->markProcessed('a');
        $this->assertSame(2, $tokenCalls); // 401 with tok-1 -> forced refresh -> tok-2
        $graph401 = false;
        $t->markProcessed('b');
        $t->markProcessed('c');
        $this->assertSame(2, $tokenCalls); // cached
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth2/') && $r['grant_type'] === 'client_credentials'
            && $r['scope'] === 'https://graph.microsoft.com/.default' && str_contains($r->url(), '/tenant-1/'));
        Cache::flush();
        $t->markProcessed('d');
        $this->assertSame(3, $tokenCalls); // cache expired -> re-acquired
    }

    // ------------------------------------------------------------------ Graph reading (HTTP level)

    public function test_graph_fetch_pages_filters_and_parses(): void
    {
        $this->useGraphConfig();
        $seen = [];
        Http::fake(function (Request $r) use (&$seen) {
            $u = $r->url();
            if (str_contains($u, '/oauth2/')) {
                return Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }
            $seen[] = $u;
            $row = fn ($id, $when) => [
                'id' => $id, 'internetMessageId' => "<$id@x>", 'conversationId' => 'CV', 'subject' => 'Re: IRMH10023',
                'from' => ['emailAddress' => ['address' => 'Agent@Sunrise.test', 'name' => 'Agent']],
                'toRecipients' => [['emailAddress' => ['address' => 'enquiry@indianrounds.in']]], 'ccRecipients' => [],
                'receivedDateTime' => $when, 'body' => ['contentType' => 'text', 'content' => 'hi'], 'hasAttachments' => false,
                'categories' => [], 'isDraft' => false,
                'internetMessageHeaders' => [
                    ['name' => 'In-Reply-To', 'value' => '<orig@outlook.com>'], ['name' => 'References', 'value' => '<a@x> <orig@outlook.com>'],
                    ['name' => 'Auto-Submitted', 'value' => 'auto-replied'],
                ],
            ];
            if (str_contains($u, 'page2')) {
                return Http::response(['value' => [$row('M3', '2026-10-05T10:02:00Z')]]);
            }

            return Http::response(['value' => [$row('M1', '2026-10-05T10:00:00Z'), $row('M2', '2026-10-05T10:01:00Z')],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/x/mailFolders/Inbox/messages?page2=1']);
        });

        $batch = (new GraphMailTransport)->fetchNewMessages(Carbon::parse('2026-10-05T09:50:00Z'), 100);
        $this->assertCount(3, $batch->messages);
        $this->assertFalse($batch->hasMore);
        $this->assertStringContainsString('/mailFolders/Inbox/messages', $seen[0]);
        $this->assertStringContainsString('receivedDateTime%20ge%202026-10-05T09%3A50%3A00Z', str_replace('+', '%20', $seen[0]));
        $this->assertStringContainsString('receivedDateTime%20asc', str_replace('+', '%20', $seen[0]));
        $m = $batch->messages[0];
        $this->assertSame('agent@sunrise.test', $m->fromEmail);
        $this->assertSame('<orig@outlook.com>', $m->inReplyTo);
        $this->assertSame(['<a@x>', '<orig@outlook.com>'], $m->references);
        $this->assertSame('auto-replied', $m->header('Auto-Submitted'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Inbox/messages') && $r->hasHeader('Prefer', 'outlook.body-content-type="text"'));
    }

    public function test_graph_mark_processed_adds_category_and_keeps_existing(): void
    {
        $this->useGraphConfig();
        $patched = null;
        Http::fake(function (Request $r) use (&$patched) {
            if (str_contains($r->url(), '/oauth2/')) {
                return Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }
            if ($r->method() === 'PATCH') {
                $patched = $r->data();

                return Http::response([]);
            }

            return Http::response(['categories' => ['Red category']]);
        });
        (new GraphMailTransport)->markProcessed('M1');
        $this->assertSame(['categories' => ['Red category', 'ERP-Processed']], $patched);
    }

    public function test_test_graph_command_reports_ok_and_fail(): void
    {
        $this->artisan('mail:test-graph')->expectsOutputToContain('FAIL - not configured')->assertExitCode(1);
        $this->useGraphConfig();
        $bad = false;
        Http::fake(function (Request $r) use (&$bad) {
            if (str_contains($r->url(), '/oauth2/')) {
                return $bad
                    ? Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret'], 401)
                    : Http::response(['access_token' => 't', 'expires_in' => 3600]);
            }

            return Http::response(['displayName' => 'Inbox', 'totalItemCount' => 12]);
        });
        $this->artisan('mail:test-graph')->expectsOutputToContain('OK - Microsoft Graph mail is working.')->assertExitCode(0);
        $bad = true;
        Cache::flush();
        $this->artisan('mail:test-graph')->expectsOutputToContain('FAIL')->assertExitCode(1);
    }

    // ------------------------------------------------------------------ ingestion

    public function test_poll_ingests_text_and_html_only_marks_category_and_advances_checkpoint(): void
    {
        [, , $lead] = $this->world();
        $f = $this->useFake();
        $t1 = Carbon::now('UTC')->subMinutes(5);
        $t2 = Carbon::now('UTC')->subMinutes(2);
        $f->inbox = [
            $this->msg(['id' => 'T1', 'received' => $t1, 'body' => "Quote is USD 900.\r\n\r\nRegards"]),
            $this->msg(['id' => 'H1', 'received' => $t2, 'bodyType' => 'html',
                'body' => '<html><head><style>p{color:red}</style></head><body><p>Hi&nbsp;team</p><script>alert(1)</script><div>Price: <b>USD&nbsp;800</b> &amp; transfers</div></body></html>']),
        ];
        $sum = $this->poll();
        $this->assertSame(['fetched' => 2, 'new' => 2, 'matched' => 2, 'unmatched' => 0, 'errors' => 0], $sum);

        $text = SupplierReply::where('graph_message_id', 'T1')->first();
        $this->assertSame($lead->lead_id, $text->lead_id);
        $this->assertStringContainsString('Quote is USD 900.', $text->body_text);
        $html = SupplierReply::where('graph_message_id', 'H1')->first();
        $this->assertStringNotContainsString('<', $html->body_text);
        $this->assertStringNotContainsString('alert(1)', $html->body_text);
        $this->assertStringNotContainsString('color:red', $html->body_text);
        $this->assertStringContainsString('Price: USD 800 & transfers', $html->body_text);
        $this->assertSame(['T1', 'H1'], $f->processed);
        $this->assertSame($t2->copy()->utc()->toIso8601String(), MailSyncState::get('inbox_checkpoint'));
        $this->assertNotNull(MailSyncState::get('last_success_at'));
        $this->assertNull(MailSyncState::get('last_error'));
    }

    public function test_poll_is_idempotent_and_ignores_the_mailboxes_own_mail(): void
    {
        $this->world();
        $f = $this->useFake();
        $f->inbox = [
            $this->msg(['id' => 'R1', 'received' => now()->subMinute()]),
            $this->msg(['id' => 'OWN', 'from' => 'ENQUIRY@indianrounds.in', 'received' => now()->subMinute()]),
        ];
        $this->assertSame(1, $this->poll()['new']);
        $again = $this->poll();
        $this->assertSame(0, $again['new']);
        $this->assertSame(1, SupplierReply::count());
        $this->assertGreaterThan(0, DB::table('supplier_reply_reads')->where('reply_id', SupplierReply::first()->id)->whereNull('read_at')->count());
        $this->assertSame(1, Activity::count()); // side effects only once
        $this->assertNull(SupplierReply::where('graph_message_id', 'OWN')->first());
    }

    public function test_poll_retries_marking_and_records_errors(): void
    {
        $this->world();
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'R1', 'received' => now()->subMinute()])];
        $f->failMark = 'Graph PATCH failed';
        $this->poll();
        $this->assertSame([], $f->processed);       // reply stored, mark failed (logged) ...
        $f->failMark = null;
        $this->poll();
        $this->assertSame(['R1'], $f->processed);   // ... and retried on the next run
        $this->assertSame(1, SupplierReply::count());

        $f->failFetch = 'Microsoft token request failed: bad secret';
        $sum = $this->poll();
        $this->assertSame(1, $sum['errors']);
        $this->assertStringContainsString('bad secret', MailSyncState::get('last_error'));
        $this->assertNotNull(MailSyncState::get('last_poll_at'));
    }

    public function test_poll_command_exit_codes(): void
    {
        $this->world();
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'C1', 'received' => now()->subMinute()])];
        $this->artisan('mail:poll-replies')->expectsOutputToContain('new=1 matched=1')->assertExitCode(0);
        $f->failFetch = 'boom';
        $this->artisan('mail:poll-replies')->assertExitCode(1);
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command, 'mail:poll-replies') && $e->expression === '* * * * *' && $e->withoutOverlapping));
    }

    public function test_attachments_allowed_blocked_oversize_and_total_cap(): void
    {
        [, , $lead] = $this->world();
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'A1', 'received' => now()->subMinute(), 'hasAttachments' => true])];
        $mb = 1024 * 1024;
        $f->attachments['A1'] = [
            new AttachmentData('1', 'Quote ../../etc/passwd.pdf', 'application/pdf', 9, content: '%PDF-1.4'),
            new AttachmentData('2', 'run.exe', 'application/x-msdownload', 10, content: 'MZ'),
            new AttachmentData('3', 'trick.pdf', 'application/x-msdownload', 10, content: 'MZ'),   // type not on the allowlist
            new AttachmentData('4', 'huge.pdf', 'application/pdf', 16 * $mb),                       // never downloaded
            new AttachmentData('5', 'logo.png', 'image/png', 5, isInline: true, content: 'png'),     // inline: ignored
            new AttachmentData('6', 'mail.msg', 'application/octet-stream', 5, isFile: false),
            new AttachmentData('7', 'big1.txt', 'text/plain', 14 * $mb, content: str_repeat('a', 14 * $mb)),
            new AttachmentData('8', 'big2.txt', 'text/plain', 14 * $mb, content: str_repeat('b', 14 * $mb)),
            new AttachmentData('9', 'late.txt', 'text/plain', 3 * $mb, content: str_repeat('c', 3 * $mb)),
            new AttachmentData('10', 'sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 4, content: 'PK'),
        ];
        $this->poll();
        $reply = SupplierReply::first();
        $rows = $reply->attachments->keyBy(fn ($a) => $a->filename);

        $stored = $rows->first(fn ($a) => $a->content_type === 'application/pdf' && ! $a->skipped);
        $this->assertNotNull($stored);
        $this->assertSame('passwd.pdf', $stored->filename); // path components are stripped
        $this->assertStringNotContainsString('/', $stored->filename);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-z0-9]{40}$#', $stored->storage_path);
        $this->assertSame('%PDF-1.4', Storage::disk('supplier_replies')->get($stored->storage_path));
        $this->assertTrue((bool) $rows['run.exe']->skipped);
        $this->assertSame('File type not allowed', $rows['run.exe']->skip_reason);
        $this->assertSame('File type not allowed', $rows['trick.pdf']->skip_reason);
        $this->assertSame('File larger than 15 MB', $rows['huge.pdf']->skip_reason);
        $this->assertNull($rows['huge.pdf']->storage_path);
        $this->assertFalse($rows->has('logo.png'));
        $this->assertSame('Not a file attachment', $rows['mail.msg']->skip_reason);
        $this->assertFalse((bool) $rows['big1.txt']->skipped);
        $this->assertFalse((bool) $rows['big2.txt']->skipped);
        $this->assertSame('Message attachments exceed 30 MB', $rows['late.txt']->skip_reason);
        $this->assertFalse((bool) $rows['sheet.xlsx']->skipped);
        $this->assertSame('x.pdf', AttachmentStore::sanitizeName("C:\\temp\\..\\x.pdf"));
        $this->assertSame('attachment', AttachmentStore::sanitizeName('///'));
    }

    public function test_reply_text_helpers(): void
    {
        $this->assertSame("a\n\nb", ReplyText::toPlain("a\r\n\r\n\r\n\r\nb\x00", 'text'));
        $plain = ReplyText::toPlain('<p>One</p><p>Two<br>Three</p><!-- c --><img src=x onerror=alert(1)>', 'html');
        $this->assertSame("One\nTwo\nThree", $plain);
        $p = ReplyText::preview("Thanks, rate is 500.\n\nOn Mon, 5 Oct 2026, Olive wrote:\n> please quote\n", 300);
        $this->assertSame('Thanks, rate is 500.', $p);
        $this->assertSame(300, mb_strlen(ReplyText::preview(str_repeat('word ', 200))));
    }

    // ------------------------------------------------------------------ matcher

    public function test_matcher_priority_thread_reference_subject_sender(): void
    {
        $owner = User::factory()->sales()->create();
        $leadA = $this->lead($owner, 'IRMH10023');
        $leadB = $this->lead($owner, 'IRAB20045');
        $leadC = $this->lead($owner, 'IRZZ30067');
        $s1 = $this->supplier('one@sup.test', 'One');
        $s2 = $this->supplier('two@sup.test', 'Two');
        $tA1 = $this->tracker($leadA->lead_id, $s1);
        $tA2 = $this->tracker($leadA->lead_id, $s2, ['sequence' => 2]);
        $this->tracker($leadB->lead_id, $s1);
        $this->tracker($leadC->lead_id, $s2);
        $mailA = $this->mailRow($leadA->lead_id, ['conversation_id' => 'CONV-A', 'internet_message_id' => '<out-a@outlook.com>']);
        $m = new ReplyMatcher;

        // (a) thread beats a lead id in the subject and the sender
        $r = $m->match($this->msg(['conversationId' => 'CONV-A', 'subject' => 'Re: IRAB20045', 'from' => 'two@sup.test']));
        $this->assertSame([$leadA->lead_id, 'thread', (int) $tA2->id, (int) $mailA->id], [$r->leadId, $r->matchedBy, $r->leadSupplierId, $r->mailHistoryId]);

        // (a) via lead_suppliers.conversation_id only (no mail_history row)
        $tA1->update(['conversation_id' => 'CONV-ONLY-TRACKER']);
        $r = $m->match($this->msg(['conversationId' => 'CONV-ONLY-TRACKER', 'from' => 'zzz@else.test', 'subject' => 'x']));
        $this->assertSame([$leadA->lead_id, 'thread', (int) $tA1->id], [$r->leadId, $r->matchedBy, $r->leadSupplierId]);

        // (b) In-Reply-To / References (with or without angle brackets) beat subject
        $r = $m->match($this->msg(['conversationId' => 'unknown', 'inReplyTo' => 'out-a@outlook.com', 'subject' => 'Re: IRAB20045', 'from' => 'one@sup.test']));
        $this->assertSame([$leadA->lead_id, 'reference', (int) $tA1->id], [$r->leadId, $r->matchedBy, $r->leadSupplierId]);
        $r = $m->match($this->msg(['references' => ['<zzz@x>', '<out-a@outlook.com>'], 'subject' => 'no id', 'from' => 'nobody@else.test']));
        $this->assertSame([$leadA->lead_id, 'reference'], [$r->leadId, $r->matchedBy]);

        // (c) lead id in the subject (must exist; unknown ids are skipped); sender picks the tracker row
        $r = $m->match($this->msg(['subject' => 'Re: Enquiry IRXX99999 / irab20045 / thanks', 'from' => 'ONE@sup.test']));
        $this->assertSame([$leadB->lead_id, 'subject'], [$r->leadId, $r->matchedBy]);
        $this->assertSame((int) $s1->id, $r->supplierId);

        // (d) sender only: unambiguous lead
        $solo = $this->supplier('solo@sup.test', 'Solo');
        $leadD = $this->lead($owner, 'IRQQ40089');
        $tD = $this->tracker($leadD->lead_id, $solo);
        $r = $m->match($this->msg(['subject' => 'hello', 'from' => 'Solo@Sup.test']));
        $this->assertSame([$leadD->lead_id, 'sender', (int) $tD->id], [$r->leadId, $r->matchedBy, $r->leadSupplierId]);

        // (d) ambiguous sender (tracked on 2 leads) -> unmatched
        $r = $m->match($this->msg(['subject' => 'hello', 'from' => 'one@sup.test']));
        $this->assertFalse($r->found());
        $this->assertNull($m->match($this->msg(['subject' => 'nothing', 'from' => 'stranger@x.test']))->matchedBy);

        // lead known but sender not a tracker: single recipient of the matched mail is chosen
        \App\Models\MailRecipient::create(['mail_history_id' => $mailA->id, 'type' => 'to', 'supplier_id' => $s2->id, 'email' => $s2->email]);
        $r = $m->match($this->msg(['conversationId' => 'CONV-A', 'from' => 'colleague@two-sup.test']));
        $this->assertSame((int) $tA2->id, $r->leadSupplierId);
    }

    public function test_classifier_auto_reply_bounce_and_normal(): void
    {
        $c = new ReplyClassifier;
        $this->assertSame('normal', $c->classify($this->msg(['headers' => ['auto-submitted' => 'no']])));
        $this->assertSame('auto_reply', $c->classify($this->msg(['headers' => ['auto-submitted' => 'auto-replied']])));
        $this->assertSame('auto_reply', $c->classify($this->msg(['headers' => ['x-auto-response-suppress' => 'All']])));
        $this->assertSame('auto_reply', $c->classify($this->msg(['headers' => ['precedence' => 'bulk']])));
        $this->assertSame('auto_reply', $c->classify($this->msg(['headers' => ['precedence' => 'auto_reply']])));
        foreach (['Automatic reply: Trip IRMH10023', 'Out of office until Monday', 'Autoreply: hello'] as $s) {
            $this->assertSame('auto_reply', $c->classify($this->msg(['subject' => $s])), $s);
        }
        $this->assertSame('bounce', $c->classify($this->msg(['from' => 'MAILER-DAEMON@outlook.com', 'subject' => 'x'])));
        $this->assertSame('bounce', $c->classify($this->msg(['from' => 'postmaster@sup.test', 'subject' => 'x'])));
        foreach (['Undeliverable: Trip Enquiry', 'Delivery Status Notification (Failure)', 'Mail delivery failed: returning message'] as $s) {
            $this->assertSame('bounce', $c->classify($this->msg(['subject' => $s, 'headers' => ['auto-submitted' => 'auto-replied']])), $s);
        }
        $this->assertSame('bounce', $c->classify($this->msg(['headers' => ['content-type' => 'multipart/report; report-type=delivery-status']])));
    }

    // ------------------------------------------------------------------ side effects

    public function test_matched_reply_updates_tracker_rsq_notifies_and_creates_activity_once(): void
    {
        [$owner, $ops, $lead, , $tr] = $this->world();
        $bystander = User::factory()->sales()->create();                       // no rsq.manage, not the owner
        $inactiveOps = User::factory()->operations()->create(['account_status' => 'suspended']);
        $admin = User::factory()->admin()->create();
        Event::fake([SupplierReplyReceived::class]);
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'R1', 'received' => now()->subMinute(), 'body' => 'Our price is USD 700 pp.']),
            $this->msg(['id' => 'R2', 'received' => now(), 'body' => 'Second mail from the same supplier.'])];
        $this->poll();

        $tr->refresh();
        $this->assertTrue($tr->reply_received);
        $this->assertSame('Awaiting Quote', $tr->status);
        $this->assertNotNull($tr->reply_date);
        $this->assertStringContainsString('Second mail', $tr->inbound_snippet);
        $this->assertSame('response_received', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));
        $this->assertSame('response_received', $lead->fresh()->rsq_status);

        // unread rows: owner + rsq.manage holders (ops, admin) - nobody else, nobody inactive
        $r1 = SupplierReply::where('graph_message_id', 'R1')->first();
        $users = DB::table('supplier_reply_reads')->where('reply_id', $r1->id)->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$owner->id, $ops->id, $admin->id])->map(fn ($i) => (int) $i)->sort()->values()->all(), array_map('intval', $users));
        $this->assertNotContains($bystander->id, $users);
        $this->assertNotContains($inactiveOps->id, $users);

        Event::assertDispatchedTimes(SupplierReplyReceived::class, 2);
        Event::assertDispatched(SupplierReplyReceived::class, function (SupplierReplyReceived $e) use ($r1, $owner, $ops, $admin, $lead) {
            if ($e->reply['id'] !== (string) $r1->id) {
                return false;
            }
            $names = array_map(fn ($c) => $c->name, $e->broadcastOn());
            sort($names);
            $want = array_map(fn ($id) => 'private-notifications.user.'.$id, collect([$owner->id, $ops->id, $admin->id])->sort()->values()->all());
            sort($want);
            $this->assertSame($want, $names);
            $this->assertSame('supplier.reply', $e->broadcastAs());
            $p = $e->broadcastWith();
            $this->assertSame(['id', 'lead_id', 'customer_name', 'supplier_name', 'subject', 'preview', 'received_at', 'kind'], array_keys($p['reply']));
            $this->assertSame([$lead->lead_id, 'Asha Rao', 'Sunrise Travels'], [$p['reply']['lead_id'], $p['reply']['customer_name'], $p['reply']['supplier_name']]);

            return true;
        });

        $acts = Activity::all();
        $this->assertCount(2, $acts); // one per reply
        $a = Activity::whereKey(SupplierReply::where('graph_message_id', 'R1')->value('activity_id'))->first();
        $this->assertSame('email', $a->type);
        $this->assertSame('Review reply from Sunrise Travels — '.$lead->lead_id, $a->title);
        $this->assertSame('medium', $a->priority);
        $this->assertSame((string) $owner->id, (string) $a->assigned_to);
        $this->assertNull($a->created_by);
        $this->assertSame($lead->lead_id, $a->lead_id);
        $this->assertNotNull($a->due_at);

        // owner bell shows it, bystander does not
        $this->as($owner);
        $al = collect($this->getJson('/api/notifications')->assertOk()->json())->where('kind', 'supplier_reply')->values();
        $this->assertCount(2, $al);
        $this->assertSame('Reply from Sunrise Travels', $al[0]['title']);
        $this->assertSame($lead->lead_id.' · Asha Rao', $al[0]['detail']);
        $this->assertSame($lead->lead_id, $al[0]['lead_id']);
        $this->assertArrayHasKey('icon', $al[0]);
        $this->assertArrayHasKey('reply_id', $al[0]);
        $this->as($bystander);
        $this->assertCount(0, collect($this->getJson('/api/notifications')->json())->where('kind', 'supplier_reply'));
    }

    public function test_reply_never_downgrades_a_further_tracker_state_and_inactive_owner_gets_unassigned_activity(): void
    {
        $owner = User::factory()->sales()->create(['active' => false, 'account_status' => 'inactive']);
        $lead = $this->lead($owner);
        $s = $this->supplier('agent@sunrise.test');
        $tr = $this->tracker($lead->lead_id, $s, ['status' => 'Quote Received', 'quote_received' => true, 'quote_amount' => 500]);
        RsqRequest::upsertFor($lead->lead_id, ['status' => 'completed']);
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'Q1', 'received' => now()])];
        $this->poll();
        $tr->refresh();
        $this->assertSame('Quote Received', $tr->status);
        $this->assertTrue($tr->reply_received);
        $this->assertSame('completed', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));
        $this->assertNull(Activity::first()->assigned_to);
    }

    public function test_auto_reply_is_stored_without_notification_or_transition(): void
    {
        [, , $lead, , $tr] = $this->world();
        Event::fake([SupplierReplyReceived::class]);
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'AR1', 'subject' => 'Automatic reply: Re: Trip Enquiry - IRMH10023', 'headers' => ['auto-submitted' => 'auto-replied']])];
        $this->poll();
        $r = SupplierReply::first();
        $this->assertSame(['auto_reply', $lead->lead_id], [$r->status, $r->lead_id]);
        $this->assertFalse($tr->fresh()->reply_received);
        $this->assertSame('Email Sent', $tr->fresh()->status);
        $this->assertSame('pending_reply', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, DB::table('supplier_reply_reads')->count());
        Event::assertNotDispatched(SupplierReplyReceived::class);
        $this->assertSame(['AR1'], $f->processed);
    }

    public function test_bounce_flags_tracker_and_alerts_owner(): void
    {
        [$owner, , $lead, , $tr] = $this->world();
        Event::fake([SupplierReplyReceived::class]);
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'B1', 'from' => 'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@x.onmicrosoft.com',
            'subject' => 'Undeliverable: Trip Enquiry - IRMH10023 - Vietnam', 'headers' => ['auto-submitted' => 'auto-generated'],
            'body' => "Delivery has failed to these recipients:\nagent@sunrise.test\nThe email address couldn't be found."])];
        $this->poll();
        $r = SupplierReply::first();
        $this->assertSame(['bounce', $lead->lead_id, (int) $tr->id], [$r->status, $r->lead_id, (int) $r->lead_supplier_id]);
        $tr->refresh();
        $this->assertSame('bounced', $tr->delivery_status);
        $this->assertFalse($tr->reply_received);
        $this->assertSame('Email Sent', $tr->status);
        $this->assertSame(0, Activity::count());
        Event::assertDispatched(SupplierReplyReceived::class, fn ($e) => $e->reply['kind'] === 'supplier_bounce');
        $this->as($owner);
        $al = collect($this->getJson('/api/notifications')->json())->firstWhere('kind', 'supplier_bounce');
        $this->assertSame('Delivery failed: Sunrise Travels', $al['title']);

        // bounce with no lead found at all -> unmatched
        $f->inbox = [$this->msg(['id' => 'B2', 'from' => 'mailer-daemon@x.com', 'subject' => 'Undeliverable: something else'])];
        $this->poll();
        $this->assertSame('unmatched', SupplierReply::where('graph_message_id', 'B2')->value('status'));
    }

    // ------------------------------------------------------------------ unmatched -> manual link

    public function test_unmatched_reply_manual_link_and_ignore_flow(): void
    {
        [$owner, $ops, $lead, $sup, $tr] = $this->world();
        $f = $this->useFake();
        $f->inbox = [
            $this->msg(['id' => 'U1', 'from' => 'newperson@elsewhere.test', 'subject' => 'Quote attached', 'received' => now()->subMinute()]),
            $this->msg(['id' => 'U2', 'from' => 'spam@elsewhere.test', 'subject' => 'Buy now', 'received' => now()]),
        ];
        $this->poll();
        $u1 = SupplierReply::where('graph_message_id', 'U1')->first();
        $this->assertSame('unmatched', $u1->status);
        $this->assertNull($u1->lead_id);
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, DB::table('supplier_reply_reads')->count());

        $this->as($ops);
        $inbox = $this->getJson('/api/supplier-replies?status=unmatched')->assertOk();
        $inbox->assertJsonPath('total', 2)->assertJsonPath('counts.unmatched', 2)->assertJsonPath('counts.all', 2);

        $this->postJson("/api/supplier-replies/{$u1->id}/link", ['lead_id' => 'NOPE1'])->assertStatus(404);
        $other = $this->supplier('x@y.test', 'Not tracked');
        $this->postJson("/api/supplier-replies/{$u1->id}/link", ['lead_id' => $lead->lead_id, 'supplier_id' => (string) $other->id])->assertStatus(422);

        $res = $this->postJson("/api/supplier-replies/{$u1->id}/link", ['lead_id' => $lead->lead_id, 'supplier_id' => (string) $sup->id])->assertOk();
        $res->assertJsonPath('status', 'matched')->assertJsonPath('matched_by', 'manual')->assertJsonPath('lead_id', $lead->lead_id)
            ->assertJsonPath('supplier.id', (string) $sup->id)->assertJsonPath('customer_name', 'Asha Rao');
        $tr->refresh();
        $this->assertTrue($tr->reply_received);
        $this->assertSame('Awaiting Quote', $tr->status);
        $this->assertSame('response_received', RsqRequest::where('lead_id', $lead->lead_id)->value('status'));
        $this->assertSame(1, Activity::count());
        $this->assertGreaterThan(0, DB::table('supplier_reply_reads')->where('reply_id', $u1->id)->count());
        $this->assertDatabaseHas('activity_log', ['action' => 'link_supplier_reply', 'entity' => 'supplier_reply', 'entity_id' => (string) $u1->id]);
        // linking twice is refused, and does not duplicate effects
        $this->postJson("/api/supplier-replies/{$u1->id}/link", ['lead_id' => $lead->lead_id])->assertStatus(409);
        $this->assertSame(1, Activity::count());

        $u2 = SupplierReply::where('graph_message_id', 'U2')->first();
        $this->postJson("/api/supplier-replies/{$u2->id}/ignore")->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame((int) $ops->id, (int) $u2->fresh()->handled_by);
        $this->getJson('/api/supplier-replies')->assertJsonPath('counts.ignored', 1)->assertJsonPath('counts.matched', 1)->assertJsonPath('counts.unmatched', 0);
        $this->assertDatabaseHas('activity_log', ['action' => 'ignore_supplier_reply', 'entity_id' => (string) $u2->id]);
        $this->assertStringNotContainsString('Buy now', json_encode(DB::table('activity_log')->get()));
    }

    // ------------------------------------------------------------------ permissions / scoping

    public function test_lead_replies_scoping_and_inbox_permissions(): void
    {
        [$owner, $ops, $lead] = $this->world();
        $other = User::factory()->sales()->create();
        $otherLead = $this->lead($other, 'IRXY50001', 'Someone');
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'S1', 'received' => now()->subMinute()])];
        $this->poll();
        $reply = SupplierReply::first();

        // owner (sales: rsq.request) sees their lead's replies; another sales user gets 403; nothing for unknown lead
        $this->as($owner);
        $list = $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertOk();
        $list->assertJsonCount(1);
        $this->assertSame(['id', 'lead_id', 'customer_name', 'supplier', 'from_email', 'from_name', 'subject', 'body_text', 'preview', 'received_at', 'status', 'matched_by', 'read', 'attachments'], array_keys($list->json(0)));
        $list->assertJsonPath('0.read', false)->assertJsonPath('0.status', 'matched')->assertJsonPath('0.matched_by', 'subject')
            ->assertJsonPath('0.supplier.name', 'Sunrise Travels')->assertJsonPath('0.id', (string) $reply->id);
        $this->getJson('/api/leads/NOPE/supplier-replies')->assertStatus(404);
        $this->as($other);
        $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertStatus(403);
        $this->getJson("/api/leads/{$otherLead->lead_id}/supplier-replies")->assertOk()->assertJsonCount(0);
        // sales cannot use the inbox
        $this->getJson('/api/supplier-replies')->assertStatus(403);
        $this->postJson("/api/supplier-replies/{$reply->id}/link", ['lead_id' => $lead->lead_id])->assertStatus(403);
        $this->postJson("/api/supplier-replies/{$reply->id}/ignore")->assertStatus(403);
        // ... nor read someone else's reply
        $this->postJson("/api/supplier-replies/{$reply->id}/read")->assertStatus(403);
        // a user without rsq.request/manage cannot read lead replies at all
        $noPerm = User::factory()->create(['role' => 'accounts']);
        $this->as($noPerm);
        $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertStatus(403);

        // ops (rsq.manage) sees the inbox with the contract shape
        $this->as($ops);
        $inbox = $this->getJson('/api/supplier-replies?page=1&per_page=10')->assertOk();
        $inbox->assertJsonStructure(['items', 'total', 'page', 'per_page', 'pages', 'counts' => ['all', 'matched', 'unmatched', 'bounce', 'auto_reply', 'ignored', 'unread']])
            ->assertJsonPath('counts.unread', 1)->assertJsonPath('total', 1)->assertJsonPath('items.0.read', false);
        $this->getJson('/api/supplier-replies?status=bogus')->assertStatus(400);
        $this->getJson('/api/supplier-replies?q=asha')->assertJsonPath('total', 1);
        $this->getJson('/api/supplier-replies?q=IRMH100')->assertJsonPath('total', 1);
        $this->getJson('/api/supplier-replies?q=agent@sunrise')->assertJsonPath('total', 1);
        $this->getJson('/api/supplier-replies?q=nomatch')->assertJsonPath('total', 0);
        $this->getJson('/api/supplier-replies?status=unmatched')->assertJsonPath('total', 0);

        // read / read-all only touch the caller's own rows
        $this->postJson("/api/supplier-replies/{$reply->id}/read")->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/api/supplier-replies')->assertJsonPath('counts.unread', 0)->assertJsonPath('items.0.read', true);
        $this->as($owner);
        $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertJsonPath('0.read', false); // owner's own unread unaffected
        $this->postJson('/api/supplier-replies/read-all?lead_id=IRNOPE')->assertOk();
        $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertJsonPath('0.read', false);
        $this->postJson('/api/supplier-replies/read-all?lead_id='.$lead->lead_id)->assertOk()->assertJson(['ok' => true]);
        $this->getJson("/api/leads/{$lead->lead_id}/supplier-replies")->assertJsonPath('0.read', true);
        $this->assertCount(0, collect($this->getJson('/api/notifications')->json())->where('kind', 'supplier_reply'));
    }

    public function test_endpoints_require_authentication(): void
    {
        foreach ([['getJson', '/api/supplier-replies'], ['getJson', '/api/leads/IRX1/supplier-replies'], ['postJson', '/api/supplier-replies/1/read'],
            ['getJson', '/api/mail/status'], ['postJson', '/api/mail/poll'], ['getJson', '/api/supplier-replies/attachments/1/download']] as [$m, $u]) {
            $this->{$m}($u)->assertStatus(401);
        }
    }

    public function test_attachment_download_authorisation_and_headers(): void
    {
        [$owner, $ops, $lead] = $this->world();
        $other = User::factory()->sales()->create();
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'D1', 'received' => now()->subMinute(), 'hasAttachments' => true])];
        $f->attachments['D1'] = [new AttachmentData('1', 'Quote Vietnam.pdf', 'application/pdf', 8, content: '%PDF-1.4'),
            new AttachmentData('2', 'bad.exe', 'application/x-msdownload', 2, content: 'MZ')];
        $this->poll();
        $good = SupplierReply::first()->attachments->firstWhere('skipped', false);
        $bad = SupplierReply::first()->attachments->firstWhere('skipped', true);
        // unmatched reply with an attachment: managers only
        $f->inbox = [$this->msg(['id' => 'D2', 'from' => 'who@else.test', 'subject' => 'hi', 'received' => now(), 'hasAttachments' => true])];
        $f->attachments['D2'] = [new AttachmentData('1', 'x.txt', 'text/plain', 3, content: 'abc')];
        $this->poll();
        $orphan = SupplierReply::where('graph_message_id', 'D2')->first()->attachments->first();

        $this->as($owner);
        $res = $this->get("/api/supplier-replies/attachments/{$good->id}/download")->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Quote Vietnam.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('%PDF-1.4', $res->streamedContent());
        $this->get("/api/supplier-replies/attachments/{$bad->id}/download")->assertStatus(404);      // skipped
        $this->get("/api/supplier-replies/attachments/{$orphan->id}/download")->assertStatus(403);  // unmatched: managers only
        $this->get('/api/supplier-replies/attachments/99999/download')->assertStatus(404);
        $this->as($other);
        $this->get("/api/supplier-replies/attachments/{$good->id}/download")->assertStatus(403);
        $this->as($ops);
        $this->get("/api/supplier-replies/attachments/{$good->id}/download")->assertOk();
        $this->get("/api/supplier-replies/attachments/{$orphan->id}/download")->assertOk();
        Storage::disk('supplier_replies')->delete($good->storage_path);
        $this->get("/api/supplier-replies/attachments/{$good->id}/download")->assertStatus(404);      // file missing
    }

    // ------------------------------------------------------------------ mail admin

    public function test_mail_status_and_manual_poll_require_mail_manage(): void
    {
        [$owner, $ops] = $this->world();
        $admin = User::factory()->admin()->create();
        $f = $this->useFake();
        $f->inbox = [$this->msg(['id' => 'P1', 'received' => now()->subMinute()]), $this->msg(['id' => 'P2', 'from' => 'zz@else.test', 'subject' => 'zzz', 'received' => now()])];

        $this->as($ops);
        $this->getJson('/api/mail/status')->assertStatus(403);
        $this->postJson('/api/mail/poll')->assertStatus(403);
        $this->as($owner);
        $this->getJson('/api/mail/status')->assertStatus(403);

        $this->as($admin);
        $this->getJson('/api/mail/status')->assertOk()->assertJsonPath('transport', 'graph')->assertJsonPath('configured', true)
            ->assertJsonPath('mailbox', 'enquiry@indianrounds.in')->assertJsonPath('last_poll_at', null)->assertJsonPath('last_error', null)
            ->assertJsonPath('unprocessed_count', 2)->assertJsonPath('replies_total', 0)->assertJsonPath('unmatched_count', 0);
        $this->postJson('/api/mail/poll')->assertOk()->assertExactJson(['fetched' => 2, 'new' => 2, 'matched' => 1, 'unmatched' => 1, 'errors' => 0]);
        $s = $this->getJson('/api/mail/status')->assertOk();
        $s->assertJsonPath('replies_total', 2)->assertJsonPath('unmatched_count', 1)->assertJsonPath('unprocessed_count', 0);
        $this->assertNotNull($s->json('last_poll_at'));
        $this->assertNotNull($s->json('last_success_at'));

        $f->failFetch = 'Graph GET failed (403 ErrorAccessDenied)';
        $this->postJson('/api/mail/poll')->assertOk()->assertJsonPath('errors', 1);
        $this->getJson('/api/mail/status')->assertJsonPath('last_error', 'Graph GET failed (403 ErrorAccessDenied)');
    }

    public function test_status_with_log_transport_is_unconfigured(): void
    {
        $this->as(User::factory()->admin()->create());
        $this->getJson('/api/mail/status')->assertOk()->assertJsonPath('transport', 'log')->assertJsonPath('configured', false);
        $this->postJson('/api/mail/poll')->assertOk()->assertExactJson(['fetched' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0]);
    }

    public function test_mail_manage_is_in_catalog_and_admin_only_by_default(): void
    {
        $this->assertTrue(\App\Support\Permissions::isValid('mail.manage'));
        foreach (['sales', 'operations'] as $role) {
            $this->assertNotContains('mail.manage', \App\Support\Permissions::forRole($role));
        }
        $this->assertContains('mail.manage', \App\Support\Permissions::forRole('admin'));
    }

    public function test_notification_channel_authorises_own_id_only(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $e = new SupplierReplyReceived([$a->id, $b->id, $a->id], ['id' => '1']);
        $this->assertSame(['private-notifications.user.'.$a->id, 'private-notifications.user.'.$b->id], array_map(fn ($c) => $c->name, $e->broadcastOn()));
        $src = file_get_contents(base_path('routes/channels.php'));
        $this->assertStringContainsString("notifications.user.{userId}", $src);
    }

    public function test_no_resend_leftovers(): void
    {
        $this->assertNull(config('services.resend.key')); // framework default stub only; the app never configures or reads RESEND_*
        $this->postJson('/api/webhooks/resend', ['type' => 'email.received'])->assertStatus(404);
        $this->assertFalse(class_exists('Resend\\Resend'));
    }
}
