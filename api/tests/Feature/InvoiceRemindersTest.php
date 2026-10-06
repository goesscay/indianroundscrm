<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\SpaRequests;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class InvoiceRemindersTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private FakeMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['crm.company.state' => 'Tamil Nadu', 'crm.company.gstin' => '33ABCDE1234F1Z5']);
        $this->mail = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $this->mail);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function overdueInvoice(User $u, string $due = '2026-10-01', ?string $email = 'asha@example.com'): array
    {
        static $n = 0;
        $c = Customer::create(['customer_no' => 'IRC-R'.++$n, 'name' => 'Asha Rao', 'email' => $email, 'state' => 'Tamil Nadu', 'owner_id' => $u->id]);
        $d = $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'Goa trip', 'travel_date' => '2026-12-20',
            'lines' => [['kind' => 'package', 'description' => 'Goa', 'qty' => 2, 'unit_price' => 20000, 'tax_rate' => 5]],
            'installments' => [['label' => 'Advance', 'due_date' => $due, 'amount' => 20000], ['label' => 'Balance', 'due_date' => '2026-12-01', 'amount' => 22000]]], 'o')->assertOk()->json();

        return $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk()->json();
    }

    public function test_manual_reminder_preview_send_and_history(): void
    {
        $u = User::factory()->create(['role' => 'operations']);
        $this->loginAs($u, 'o');
        $i = $this->overdueInvoice($u);

        $pre = $this->api('GET', "/api/invoices/{$i['id']}/email-preview?reminder=1", [], 'o')->assertOk();
        $this->assertSame(['asha@example.com'], $pre->json('to'));
        $this->assertStringContainsString('Payment reminder', $pre->json('subject'));
        $this->assertStringContainsString('20,000.00', $pre->json('message'));   // only the overdue instalment is quoted
        $this->assertStringContainsString('overdue by 14 days', $pre->json('message'));

        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => $pre->json('subject'), 'message' => $pre->json('message'), 'reminder' => true], 'o')->assertOk();
        $this->assertCount(1, $this->mail->sent);
        $this->assertCount(1, $this->mail->sent[0]->attachments);

        $show = $this->api('GET', "/api/invoices/{$i['id']}", [], 'o')->assertOk();
        $this->assertSame(1, $show->json('reminder_count'));
        $this->assertNotNull($show->json('last_reminder_at'));
        $this->assertCount(1, $show->json('reminders'));
        $this->assertSame('manual', $show->json('reminders.0.trigger'));

        // the next reminder is firmer
        $this->assertStringContainsString('Second reminder', $this->api('GET', "/api/invoices/{$i['id']}/email-preview?reminder=1", [], 'o')->json('subject'));

        // a failed send is not counted
        $this->mail->failSend = 'down';
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => 's', 'message' => 'm', 'reminder' => true], 'o')->assertStatus(502);
        $this->assertSame(1, Invoice::find($i['id'])->reminder_count);
    }

    public function test_nothing_to_remind_when_paid_or_draft(): void
    {
        $u = User::factory()->create(['role' => 'operations']);
        $this->loginAs($u, 'o');
        $i = $this->overdueInvoice($u);
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 42000], 'o')->assertOk();
        $this->api('GET', "/api/invoices/{$i['id']}/email-preview?reminder=1", [], 'o')->assertStatus(409);
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['a@b.co'], 'subject' => 's', 'message' => 'm', 'reminder' => true], 'o')->assertStatus(409);
    }

    public function test_daily_job_is_off_by_default_then_follows_the_schedule(): void
    {
        $u = User::factory()->create(['role' => 'operations']);
        $this->loginAs($u, 'o');
        $due = $this->overdueInvoice($u);                                   // overdue since 2 Oct
        $this->overdueInvoice($u, '2026-10-20');                            // not overdue yet
        $this->overdueInvoice($u, '2026-10-01', null);                      // overdue but no e-mail address

        Artisan::call('invoices:remind');
        $this->assertCount(0, $this->mail->sent);                           // off unless CRM_AUTO_REMINDERS

        config(['crm.reminders.auto' => true]);
        Artisan::call('invoices:remind');
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame(1, Invoice::find($due['id'])->reminder_count);
        $this->assertSame('auto', $this->api('GET', "/api/invoices/{$due['id']}", [], 'o')->json('reminders.0.trigger'));

        Artisan::call('invoices:remind');                                   // same day: nothing more
        $this->assertCount(1, $this->mail->sent);

        $count = fn () => Invoice::find($due['id'])->reminder_count;
        Carbon::setTestNow('2026-10-22 10:00:00');                          // a week later: second reminder
        Artisan::call('invoices:remind');
        $this->assertSame(2, $count());
        Carbon::setTestNow('2026-10-29 10:00:00');
        Artisan::call('invoices:remind');
        $this->assertSame(3, $count());
        Carbon::setTestNow('2026-11-12 10:00:00');                          // limit of 3 reached
        Artisan::call('invoices:remind');
        $this->assertSame(3, Invoice::find($due['id'])->reminder_count);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $u = User::factory()->create(['role' => 'operations']);
        $this->loginAs($u, 'o');
        $i = $this->overdueInvoice($u);
        Artisan::call('invoices:remind', ['--force' => true, '--dry-run' => true]);
        $this->assertStringContainsString($i['invoice_no'], Artisan::output());
        $this->assertCount(0, $this->mail->sent);
        $this->assertSame(0, Invoice::find($i['id'])->reminder_count);
    }
}
