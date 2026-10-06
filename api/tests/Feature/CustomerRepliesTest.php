<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerReply;
use App\Models\Deal;
use App\Models\SupplierReply;
use App\Models\User;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\ReplyPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class CustomerRepliesTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private FakeMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['services.microsoft.mailbox' => 'enquiry@indianrounds.in', 'crm.company.state' => 'Tamil Nadu', 'crm.company.gstin' => '33ABCDE1234F1Z5']);
        $this->mail = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $this->mail);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function msg(array $o): InboundMessage
    {
        static $n = 0;
        $n++;

        return new InboundMessage(
            id: $o['id'] ?? "CR-$n", internetMessageId: "<cust-$n@mail.test>", conversationId: $o['conversationId'] ?? null,
            inReplyTo: $o['inReplyTo'] ?? null, references: [], fromEmail: $o['from'] ?? 'asha@example.com', fromName: 'Asha Rao',
            to: ['enquiry@indianrounds.in'], cc: [], subject: $o['subject'] ?? 'Re: your quote', body: $o['body'] ?? 'Looks good, can we go ahead?',
            bodyType: 'text', receivedAt: Carbon::now('UTC'), headers: [], categories: [], hasAttachments: false, isDraft: false,
        );
    }

    private function poll(): array
    {
        return app(ReplyPoller::class)->run();
    }

    /** a customer with a deal and a quote e-mailed to them */
    private function world(): array
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $this->loginAs($owner, 's');
        $c = Customer::create(['customer_no' => 'IRC-R1', 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'owner_id' => $owner->id]);
        $deal = Deal::create(['deal_no' => 'IRD-0001', 'customer_id' => $c->id, 'name' => 'Goa trip', 'stage' => 'quoted', 'owner_id' => $owner->id]);
        $q = $this->api('POST', '/api/quotes', ['customer_id' => (string) $c->id, 'deal_id' => (string) $deal->id, 'title' => 'Goa', 'destination' => 'Goa',
            'lines' => [['kind' => 'package', 'description' => 'Goa', 'qty' => 1, 'unit_price' => 20000, 'tax_rate' => 5]]], 's')->assertOk()->json();
        $this->api('POST', "/api/quotes/{$q['id']}/send", ['to' => ['asha@example.com'], 'subject' => "Quote {$q['quote_no']}", 'message' => 'Hi', 'attach_pdf' => false], 's')->assertOk();

        return [$owner, $c, $deal, $q];
    }

    public function test_reply_in_the_same_thread_is_matched_to_the_quote_deal_and_customer(): void
    {
        [$owner, $c, $deal, $q] = $this->world();
        $this->mail->inbox = [$this->msg(['id' => 'R1', 'conversationId' => 'conv-1', 'from' => 'someone.else@gmail.com'])];   // a colleague replying from another address
        $sum = $this->poll();
        $this->assertSame(1, $sum['matched']);

        $r = CustomerReply::first();
        $this->assertSame([(int) $c->id, (int) $deal->id, (int) $q['id'], 'thread'], [$r->customer_id, $r->deal_id, $r->quote_id, $r->matched_by]);
        $this->assertSame(0, SupplierReply::count());                      // it is not a supplier reply
        $this->assertContains('R1', $this->mail->processed);               // and is marked processed in the mailbox

        $act = Activity::first();
        $this->assertSame((int) $owner->id, (int) $act->assigned_to);
        $this->assertSame([(int) $c->id, (int) $deal->id], [(int) $act->customer_id, (int) $act->deal_id]);   // it shows on both records
        $this->assertStringContainsString('Asha Rao', $act->title);
        $this->assertStringContainsString($q['quote_no'], $act->title);

        $this->poll();                                                       // polling again is harmless
        $this->assertSame(1, CustomerReply::count());
        $this->assertSame(1, Activity::count());

        // shown wherever the record is
        foreach (["/api/customers/{$c->id}/replies", "/api/deals/{$deal->id}/replies", "/api/quotes/{$q['id']}/replies"] as $url) {
            $this->api('GET', $url, [], 's')->assertOk()->assertJsonCount(1)->assertJsonPath('0.quote.quote_no', $q['quote_no']);
        }
    }

    public function test_reply_is_matched_by_in_reply_to_by_quote_number_and_by_sender(): void
    {
        [, $c, $deal, $q] = $this->world();
        $this->mail->inbox = [
            $this->msg(['id' => 'A', 'inReplyTo' => '<imid-1@outlook.test>', 'from' => 'x@y.test']),                       // the id our sent mail got
            $this->msg(['id' => 'B', 'subject' => "Question about {$q['quote_no']}", 'from' => 'x@y.test']),
            $this->msg(['id' => 'C', 'subject' => 'Hello', 'from' => 'ASHA@example.com']),                                // only the sender is known
        ];
        $this->poll();
        $by = CustomerReply::orderBy('id')->pluck('matched_by', 'graph_message_id')->all();
        $this->assertSame(['A' => 'reference', 'B' => 'subject', 'C' => 'sender'], $by);
        $this->assertSame((int) $deal->id, CustomerReply::where('graph_message_id', 'C')->value('deal_id'));   // her only open deal
    }

    public function test_sender_match_needs_one_customer_and_leaves_deal_empty_when_ambiguous(): void
    {
        [$owner, $c] = $this->world();
        Deal::create(['deal_no' => 'IRD-0002', 'customer_id' => $c->id, 'name' => 'Kerala', 'stage' => 'enquiry', 'owner_id' => $owner->id]);
        CustomerContact::create(['customer_id' => $c->id, 'name' => 'Raj', 'email' => 'raj@example.com']);
        $this->mail->inbox = [$this->msg(['id' => 'M', 'from' => 'raj@example.com', 'subject' => 'Hi'])];
        $this->poll();
        $r = CustomerReply::first();
        $this->assertSame((int) $c->id, $r->customer_id);                    // a contact's address counts
        $this->assertNull($r->deal_id);                                      // two open deals: not guessed

        // the same address on two customers is ambiguous: not matched to either
        Customer::create(['customer_no' => 'IRC-R2', 'name' => 'Other', 'email' => 'shared@example.com', 'owner_id' => $owner->id]);
        Customer::create(['customer_no' => 'IRC-R3', 'name' => 'Other 2', 'email' => 'shared@example.com', 'owner_id' => $owner->id]);
        $this->mail->inbox = [$this->msg(['id' => 'S', 'from' => 'shared@example.com', 'subject' => 'Hi'])];
        $sum = $this->poll();
        $this->assertSame(1, $sum['unmatched']);
        $this->assertSame(1, CustomerReply::count());
    }

    public function test_unknown_senders_stay_in_the_supplier_inbox_and_visibility_follows_the_record(): void
    {
        [, $c] = $this->world();
        $this->mail->inbox = [$this->msg(['id' => 'U', 'from' => 'nobody@nowhere.test', 'subject' => 'Hi there'])];
        $this->poll();
        $this->assertSame(0, CustomerReply::count());
        $this->assertSame('unmatched', SupplierReply::first()->status);

        $this->mail->inbox = [$this->msg(['id' => 'V', 'conversationId' => 'conv-1'])];
        $this->poll();
        $other = User::factory()->create(['role' => 'sales']);
        $this->loginAs($other, 'x');                                         // not the owner, no view-all
        $this->api('GET', "/api/customers/{$c->id}/replies", [], 'x')->assertStatus(404);
    }

    public function test_invoice_and_reminder_threads_match_the_invoice(): void
    {
        [, $c, $deal] = $this->world();
        $this->loginAs(User::factory()->create(['role' => 'operations']), 'o');
        $d = $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'deal_id' => (string) $deal->id, 'title' => 'Goa', 'travel_date' => '2026-12-20',
            'lines' => [['kind' => 'package', 'description' => 'Goa', 'qty' => 1, 'unit_price' => 20000, 'tax_rate' => 5]],
            'installments' => [['label' => 'Advance', 'due_date' => '2026-10-01', 'amount' => 21000]]], 'o')->assertOk()->json();
        $i = $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk()->json();
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => 'Invoice', 'message' => 'Hi', 'attach_pdf' => false], 'o')->assertOk();   // conv-2
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => 'Reminder', 'message' => 'Pay', 'reminder' => true], 'o')->assertOk();  // conv-3

        $this->mail->inbox = [$this->msg(['id' => 'I1', 'conversationId' => 'conv-2', 'from' => 'a@b.test']), $this->msg(['id' => 'I2', 'conversationId' => 'conv-3', 'from' => 'a@b.test'])];
        $this->poll();
        $this->assertSame([(int) $i['id'], (int) $i['id']], CustomerReply::orderBy('id')->pluck('invoice_id')->all());
        $this->api('GET', "/api/invoices/{$i['id']}/replies", [], 'o')->assertOk()->assertJsonCount(2);
    }
}
