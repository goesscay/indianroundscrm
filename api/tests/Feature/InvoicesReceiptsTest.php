<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class InvoicesReceiptsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['crm.company.state' => 'Tamil Nadu', 'crm.company.gstin' => '33ABCDE1234F1Z5']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function customer(User $owner, array $a = []): Customer
    {
        static $n = 0;

        return Customer::create($a + ['customer_no' => 'IRC-I'.++$n, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'state' => 'Tamil Nadu', 'owner_id' => $owner->id]);
    }

    private function lines(): array
    {
        return [['kind' => 'package', 'description' => 'Goa 4N/5D', 'qty' => 2, 'unit_price' => 20000, 'discount' => 0, 'tax_rate' => 5]]; // 40000 + 2000 = 42000
    }

    private function draft(Customer $c, string $browser = 'o', array $over = []): array
    {
        return $this->api('POST', '/api/invoices', $over + ['customer_id' => (string) $c->id, 'title' => 'Goa trip', 'travel_date' => '2026-12-20', 'lines' => $this->lines()], $browser)->assertOk()->json();
    }

    private function issued(Customer $c, string $browser = 'o', array $over = []): array
    {
        $d = $this->draft($c, $browser, $over);

        return $this->api('POST', "/api/invoices/{$d['id']}/issue", [], $browser)->assertOk()->json();
    }

    // ---------------------------------------------------------------- drafts and issuing

    public function test_draft_has_no_number_until_issued_and_series_is_per_financial_year(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $d = $this->draft($c);
        $this->assertNull($d['invoice_no']);
        $this->assertSame('draft', $d['status']);
        $this->assertSame(42000.0, (float) $d['total']);
        $this->api('GET', "/api/invoices/{$d['id']}/pdf", [], 'o')->assertStatus(409);

        $i1 = $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk();
        $this->assertSame('INV/26-27/0001', $i1->json('invoice_no'));
        $this->assertSame('issued', $i1->json('status'));
        $this->assertSame('INV/26-27/0002', $this->issued($c)['invoice_no']);

        // a new financial year restarts the series; 31 Mar 20:00 UTC is already 1 Apr in India
        $this->assertSame('INV/27-28/0001', \App\Services\Billing::nextNumber('INV', Carbon::parse('2027-03-31 20:00:00', 'UTC')));
        $this->assertSame('INV/27-28/0002', \App\Services\Billing::nextNumber('INV', Carbon::parse('2027-06-01', 'Asia/Kolkata')));
        $this->assertSame('INV/26-27/0003', \App\Services\Billing::nextNumber('INV', Carbon::parse('2027-03-31 10:00:00', 'UTC')));
    }

    public function test_issued_invoice_is_frozen(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $i = $this->issued($c);
        $this->api('PATCH', "/api/invoices/{$i['id']}", ['title' => 'x'], 'o')->assertStatus(409);
        $this->api('DELETE', "/api/invoices/{$i['id']}", [], 'o')->assertStatus(409);
        $this->api('POST', "/api/invoices/{$i['id']}/issue", [], 'o')->assertStatus(409);

        // later customer edits do not change the invoice
        $c->update(['name' => 'Renamed', 'state' => 'Kerala']);
        $this->assertSame('Asha Rao', $this->api('GET', "/api/invoices/{$i['id']}", [], 'o')->json('bill_name'));
        $this->assertSame('Tamil Nadu', $this->api('GET', "/api/invoices/{$i['id']}", [], 'o')->json('place_of_supply'));
    }

    public function test_draft_edit_delete_and_validation(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $d = $this->draft($c);
        $up = $this->api('PATCH', "/api/invoices/{$d['id']}", ['title' => 'Goa v2', 'lines' => [['description' => 'All in', 'qty' => 1, 'unit_price' => 1000, 'tax_rate' => 18]]], 'o')->assertOk();
        $this->assertSame('Goa v2', $up->json('title'));
        $this->assertSame(1180.0, (float) $up->json('total'));
        $this->assertSame(1180.0, (float) array_sum(array_column($up->json('installments'), 'amount'))); // schedule follows the new total

        $this->api('POST', '/api/invoices', ['customer_id' => '99999', 'title' => 'x'], 'o')->assertStatus(422);
        $bad = $this->lines(); $bad[0]['discount'] = 999999;
        $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'x', 'lines' => $bad], 'o')->assertStatus(422);
        $empty = $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'Empty'], 'o')->assertOk()->json('id');
        $this->api('POST', "/api/invoices/$empty/issue", [], 'o')->assertStatus(422);

        $this->api('DELETE', "/api/invoices/$empty", [], 'o')->assertOk();
        $this->api('GET', "/api/invoices/$empty", [], 'o')->assertStatus(404);
    }

    public function test_default_and_custom_payment_schedule(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        // 50% advance today, balance 15 days before travel
        $s = $this->draft($c)['installments'];
        $this->assertCount(2, $s);
        $this->assertSame(['Advance', 'Balance'], array_column($s, 'label'));
        $this->assertSame('2026-10-15', $s[0]['due_date']);
        $this->assertSame('2026-12-05', $s[1]['due_date']);
        $this->assertSame(21000.0, (float) $s[0]['amount']);

        // no travel date: one payment a week out
        $one = $this->draft($c, 'o', ['travel_date' => null])['installments'];
        $this->assertCount(1, $one);
        $this->assertSame('2026-10-22', $one[0]['due_date']);

        // custom plan must add up
        $ok = $this->draft($c, 'o', ['installments' => [['label' => 'Booking', 'due_date' => '2026-10-16', 'amount' => 10000], ['label' => 'Rest', 'due_date' => '2026-11-20', 'amount' => 32000]]]);
        $this->assertCount(2, $ok['installments']);
        $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'x', 'lines' => $this->lines(),
            'installments' => [['label' => 'Only', 'due_date' => '2026-10-16', 'amount' => 100]]], 'o')->assertStatus(422);
    }

    public function test_gst_split_follows_the_states(): void
    {
        $u = $this->as('operations', 'o');
        $same = $this->issued($this->customer($u, ['state' => 'tamil nadu']));
        $this->assertSame('cgst_sgst', $same['gst_mode']);
        $this->assertSame(1000.0, (float) $same['cgst_total']);
        $this->assertSame(1000.0, (float) $same['sgst_total']);
        $this->assertSame(0.0, (float) $same['igst_total']);

        $other = $this->issued($this->customer($u, ['state' => 'Kerala']));
        $this->assertSame('igst', $other['gst_mode']);
        $this->assertSame(2000.0, (float) $other['igst_total']);

        $unknown = $this->issued($this->customer($u, ['state' => null]));
        $this->assertSame('unspecified', $unknown['gst_mode']);
        $this->assertSame(2000.0, (float) $unknown['tax_total']);
    }

    public function test_invoice_from_quote_copies_lines_and_blocks_duplicates(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $q = $this->api('POST', '/api/quotes', ['customer_id' => (string) $c->id, 'title' => 'Bali', 'destination' => 'Bali', 'travel_date' => '2027-01-10', 'adults' => 2, 'tcs_rate' => 5,
            'lines' => [['description' => 'Bali package', 'qty' => 2, 'unit_price' => 50000, 'tax_rate' => 5]], 'terms' => 'Pay on time'], 'o')->assertOk()->json();

        $inv = $this->api('POST', "/api/quotes/{$q['id']}/invoice", [], 'o')->assertOk();
        $this->assertSame('Bali', $inv->json('title'));
        $this->assertSame('Bali', $inv->json('destination'));
        $this->assertSame('2027-01-10', $inv->json('travel_date'));
        $this->assertSame((float) $q['total'], (float) $inv->json('total'));
        $this->assertSame(5.0, (float) $inv->json('tcs_rate'));
        $this->assertSame('Pay on time', $inv->json('terms'));
        $this->assertSame((string) $q['id'], $inv->json('quote_id'));
        $this->api('POST', "/api/quotes/{$q['id']}/invoice", [], 'o')->assertStatus(409);
        $this->api('POST', '/api/quotes/99999/invoice', [], 'o')->assertStatus(404);
    }

    // ---------------------------------------------------------------- receipts

    public function test_payments_update_status_and_book_the_deal(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $deal = Deal::create(['deal_no' => 'IRD-1', 'customer_id' => $c->id, 'name' => 'D', 'owner_id' => $u->id, 'stage' => 'quoted', 'probability' => 40]);
        $i = $this->issued($c, 'o', ['deal_id' => (string) $deal->id]);
        $id = $i['id'];
        $this->assertSame('unpaid', $i['payment_status']);

        $r1 = $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 21000, 'mode' => 'upi', 'reference' => 'UTR123'], 'o')->assertOk();
        $this->assertSame('RCT/26-27/0001', $r1->json('receipt.receipt_no'));
        $this->assertSame('partial', $r1->json('invoice.payment_status'));
        $this->assertSame(21000.0, (float) $r1->json('invoice.balance'));
        $this->assertSame('paid', $r1->json('invoice.installments.0.status'));
        $this->assertSame('won', $deal->fresh()->stage);
        $this->assertSame(42000.0, (float) $deal->fresh()->selling_amount);

        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 21000.5], 'o')->assertStatus(422); // more than the balance
        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 0], 'o')->assertStatus(422);
        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 10, 'received_date' => '2026-12-31'], 'o')->assertStatus(422); // future
        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 10, 'mode' => 'barter'], 'o')->assertStatus(422);

        $r2 = $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 21000, 'mode' => 'cash'], 'o')->assertOk();
        $this->assertSame('RCT/26-27/0002', $r2->json('receipt.receipt_no'));
        $this->assertSame('paid', $r2->json('invoice.payment_status'));
        $this->assertSame(0.0, (float) $r2->json('invoice.balance'));
        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 1], 'o')->assertStatus(422);

        // cancelling a receipt re-opens the balance
        $rid = $r2->json('receipt.id');
        $this->api('POST', "/api/receipts/$rid/cancel", [], 'o')->assertStatus(422);                                   // a reason is always needed
        $c2 = $this->api('POST', "/api/receipts/$rid/cancel", ['reason' => 'Cheque bounced'], 'o')->assertOk();        // operations can cancel too
        $this->assertSame('cancelled', $c2->json('status'));
        $this->assertSame(21000.0, (float) $this->api('GET', "/api/invoices/$id", [], 'o')->json('balance'));
        $this->api('POST', "/api/receipts/$rid/cancel", ['reason' => 'again'], 'o')->assertStatus(409);
    }

    public function test_overdue_uses_first_in_first_out_allocation(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        // advance due long ago and balance far in the future
        $a = $this->issued($c, 'o', ['installments' => [['label' => 'Advance', 'due_date' => '2026-10-01', 'amount' => 20000], ['label' => 'Balance', 'due_date' => '2026-12-01', 'amount' => 22000]]]);
        $this->assertSame('overdue', $a['payment_status']);
        $this->assertCount(1, $this->api('GET', '/api/invoices?status=overdue', [], 'o')->json());

        $this->api('POST', "/api/invoices/{$a['id']}/receipts", ['amount' => 20000], 'o')->assertOk()->assertJsonPath('invoice.payment_status', 'partial');
        $this->assertCount(0, $this->api('GET', '/api/invoices?status=overdue', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/invoices?status=unpaid', [], 'o')->json());
        $this->assertSame('2026-12-01', $this->api('GET', "/api/invoices/{$a['id']}", [], 'o')->json('next_due'));
    }

    // ---------------------------------------------------------------- credit notes, refunds, cancel

    public function test_credit_note_and_refund(): void
    {
        $u = $this->as('admin', 'a');
        $c = $this->customer($u);
        $i = $this->issued($c, 'a');
        $id = $i['id'];
        $this->api('POST', "/api/invoices/$id/receipts", ['amount' => 42000], 'a')->assertOk();

        $cn = $this->api('POST', "/api/invoices/$id/credit-notes", ['amount' => 10500, 'reason' => 'Guest cancelled'], 'a')->assertOk();
        $this->assertSame('CN/26-27/0001', $cn->json('credit_notes.0.credit_note_no'));
        $this->assertSame(500.0, (float) $cn->json('credit_notes.0.tax_part')); // 10500 * 2000/42000
        $this->assertSame(10500.0, (float) $cn->json('credited_total'));
        $this->assertSame(10500.0, (float) $cn->json('refundable'));
        $this->assertSame(0.0, (float) $cn->json('balance'));
        $this->assertSame('paid', $cn->json('payment_status'));

        $this->api('POST', "/api/invoices/$id/credit-notes", ['amount' => 40000, 'reason' => 'too much'], 'a')->assertStatus(422);
        $this->api('POST', "/api/invoices/$id/refunds", ['amount' => 20000], 'a')->assertStatus(422);
        $rf = $this->api('POST', "/api/invoices/$id/refunds", ['amount' => 10500, 'mode' => 'bank_transfer', 'reference' => 'REF1'], 'a')->assertOk();
        $this->assertSame('RFD/26-27/0001', $rf->json('receipt.receipt_no'));
        $this->assertSame(0.0, (float) $rf->json('invoice.refundable'));
        $this->assertSame(31500.0, (float) $rf->json('invoice.paid_total'));

        // crediting the rest marks the invoice credited
        $full = $this->api('POST', "/api/invoices/$id/credit-notes", ['amount' => 31500, 'reason' => 'Trip cancelled'], 'a')->assertOk();
        $this->assertSame('credited', $full->json('payment_status'));
        $this->assertSame(31500.0, (float) $full->json('refundable'));
    }

    public function test_cancel_only_without_money(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $a = $this->issued($c);
        $b = $this->issued($c);
        $this->api('POST', "/api/invoices/{$a['id']}/cancel", [], 'o')->assertStatus(422); // reason needed
        $x = $this->api('POST', "/api/invoices/{$a['id']}/cancel", ['reason' => 'Wrong customer'], 'o')->assertOk();
        $this->assertSame('cancelled', $x->json('status'));
        $this->assertSame('INV/26-27/0001', $x->json('invoice_no')); // keeps its number
        $this->api('POST', "/api/invoices/{$a['id']}/receipts", ['amount' => 5], 'o')->assertStatus(409);

        $this->api('POST', "/api/invoices/{$b['id']}/receipts", ['amount' => 100], 'o')->assertOk();
        $this->api('POST', "/api/invoices/{$b['id']}/cancel", ['reason' => 'x'], 'o')->assertStatus(409);
    }

    // ---------------------------------------------------------------- advances

    public function test_advances_paid_before_the_invoice_go_onto_it_when_it_is_issued(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $other = $this->customer($u, ['name' => 'Someone Else']);
        $adv = $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 15000, 'mode' => 'upi', 'reference' => 'ADV1'], 'o')->assertOk();
        $this->assertNull($adv->json('invoice_id'));
        $this->assertSame('RCT/26-27/0001', $adv->json('receipt_no'));
        $this->assertSame(15000.0, (float) $this->api('GET', "/api/customers/{$c->id}", [], 'o')->json('advances'));
        $this->assertCount(1, $this->api('GET', '/api/receipts?advances=1', [], 'o')->json());

        $i = $this->issued($c);                                                                        // total 42,000
        $this->assertEquals(['count' => 1, 'amount' => 15000.0], $i['advances_applied']);
        $this->assertSame(27000.0, (float) $i['balance']);                                              // right from the start
        $this->assertSame(0.0, (float) $this->api('GET', "/api/customers/{$c->id}", [], 'o')->json('advances'));
        $this->assertSame($i['id'], $this->api('GET', "/api/receipts/{$adv->json('id')}", [], 'o')->json('invoice_id'));
        $this->api('POST', "/api/receipts/{$adv->json('id')}/apply", ['invoice_id' => $i['id']], 'o')->assertStatus(409);   // already applied

        // a bigger advance, applied by hand: what fits goes onto the invoice, the rest stays an advance
        $big = $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 90000], 'o')->assertOk()->json('id');
        $inv = $this->api('GET', "/api/invoices/{$i['id']}", [], 'o')->assertOk()->json();
        $this->assertSame([$big], array_column($inv['unapplied_advances'], 'id'));
        $this->assertSame(90000.0, (float) $inv['unapplied_advances'][0]['amount']);
        $applied = $this->api('POST', "/api/receipts/$big/apply", ['invoice_id' => $i['id']], 'o')->assertOk();
        $this->assertSame(27000.0, (float) $applied->json('amount'));
        $this->assertSame(0.0, (float) $this->api('GET', "/api/invoices/{$i['id']}", [], 'o')->json('balance'));
        $this->assertSame(63000.0, (float) $this->api('GET', "/api/customers/{$c->id}", [], 'o')->json('advances'));   // the remainder is still held
        $rest = collect($this->api('GET', '/api/receipts?advances=1', [], 'o')->json())->first();
        $this->assertSame(63000.0, (float) $rest['amount']);
        $this->assertStringContainsString('RCT/26-27/0002', (string) $rest['notes']);
        $this->api('POST', "/api/receipts/{$rest['id']}/apply", ['invoice_id' => $i['id']], 'o')->assertStatus(422);       // nothing left to pay

        $j = $this->issued($other);
        $this->api('POST', "/api/receipts/{$rest['id']}/apply", ['invoice_id' => $j['id']], 'o')->assertStatus(422);        // another customer

        // the books stay square through the split: debits equal credits, and the advances account holds exactly what is left
        $this->assertEqualsWithDelta((float) \Illuminate\Support\Facades\DB::table('journal_lines')->sum('debit'), (float) \Illuminate\Support\Facades\DB::table('journal_lines')->sum('credit'), 0.005);
        $adv = \Illuminate\Support\Facades\DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', '2100')->selectRaw('sum(credit) - sum(debit) as net')->value('net');
        $this->assertEqualsWithDelta(63000.0, (float) $adv, 0.005);
    }

    public function test_advances_are_not_guessed_when_the_customer_has_two_open_invoices(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $this->issued($c);                                                                             // first open invoice
        $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 5000], 'o')->assertOk();
        $second = $this->issued($c);                                                                   // second one: which trip is the 5,000 for?
        $this->assertEquals(['count' => 0, 'amount' => 0.0], $second['advances_applied']);
        $this->assertSame(5000.0, (float) $this->api('GET', "/api/customers/{$c->id}", [], 'o')->json('advances'));
        $this->assertCount(1, $this->api('GET', "/api/invoices/{$second['id']}", [], 'o')->json('unapplied_advances'));    // offered on the invoice page instead
    }

    public function test_a_payment_that_looks_like_a_repeat_asks_for_confirmation(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 5000, 'mode' => 'upi'], 'o')->assertOk();

        $dup = $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 5000, 'mode' => 'upi'], 'o')->assertStatus(409);
        $this->assertSame('possible_duplicate', $dup->json('detail.code'));
        $this->assertSame('RCT/26-27/0001', $dup->json('detail.receipt_no'));
        $this->assertCount(1, $this->api('GET', '/api/receipts?advances=1', [], 'o')->json());

        $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 5000, 'mode' => 'cash'], 'o')->assertOk();                  // another mode: fine
        $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 5000, 'mode' => 'upi', 'confirm_duplicate' => true], 'o')->assertOk();   // yes, it really is another
        $this->assertCount(3, $this->api('GET', '/api/receipts?advances=1', [], 'o')->json());

        $i = $this->issued($c);                                                                        // the same check on an invoice
        $rid = $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 1000, 'mode' => 'upi'], 'o')->assertOk()->json('receipt.id');
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 1000, 'mode' => 'upi'], 'o')->assertStatus(409);
        $this->api('POST', "/api/receipts/$rid/cancel", ['reason' => 'duplicate'], 'o')->assertOk();                                      // and it can be undone by operations
    }

    // ---------------------------------------------------------------- documents and mail

    public function test_pdfs_and_emails(): void
    {
        $fake = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $fake);
        $u = $this->as('operations', 'o');
        $c = $this->customer($u, ['gstin' => '29ABCDE1234F1Z5', 'state' => 'Kerala', 'city' => 'Kochi']);
        $i = $this->issued($c);
        $rec = $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 21000, 'mode' => 'upi', 'reference' => 'UTR9'], 'o')->assertOk()->json('receipt');

        foreach (["/api/invoices/{$i['id']}/pdf", "/api/receipts/{$rec['id']}/pdf"] as $url) {
            $res = $this->spa('o')->get($url);
            $res->assertOk();
            $this->assertStringStartsWith('%PDF', $res->getContent());
            $this->assertGreaterThan(1500, strlen($res->getContent()));
        }

        $pre = $this->api('GET', "/api/invoices/{$i['id']}/email-preview", [], 'o')->assertOk();
        $this->assertSame(['asha@example.com'], $pre->json('to'));
        $this->assertStringContainsString('21,000.00', $pre->json('message'));
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => 'Invoice', 'message' => 'Hello'], 'o')->assertOk();
        $this->api('POST', "/api/receipts/{$rec['id']}/send", ['to' => ['asha@example.com'], 'subject' => 'Receipt', 'message' => 'Thanks', 'attach_pdf' => false], 'o')->assertOk();
        $this->assertCount(2, $fake->sent);
        $this->assertTrue($fake->sent[0]->html);
        $this->assertCount(1, $fake->sent[0]->attachments);
        $this->assertStringStartsWith('%PDF', $fake->sent[0]->attachments[0]['bytes']);
        $this->assertCount(0, $fake->sent[1]->attachments);

        $fake->failSend = 'down';
        $this->api('POST', "/api/invoices/{$i['id']}/send", ['to' => ['asha@example.com'], 'subject' => 'Invoice', 'message' => 'Hello'], 'o')->assertStatus(502);
        $draft = $this->draft($c);
        $this->api('POST', "/api/invoices/{$draft['id']}/send", ['to' => ['a@b.co'], 'subject' => 's', 'message' => 'm'], 'o')->assertStatus(409);
    }

    // ---------------------------------------------------------------- lists, customer totals and access

    public function test_lists_totals_and_customer_summary(): void
    {
        $u = $this->as('operations', 'o');
        $c = $this->customer($u);
        $a = $this->issued($c);
        $b = $this->issued($c);
        $this->draft($c);
        $this->api('POST', "/api/invoices/{$a['id']}/receipts", ['amount' => 42000], 'o')->assertOk();
        $this->api('POST', "/api/invoices/{$b['id']}/receipts", ['amount' => 2000], 'o')->assertOk();

        $paged = $this->api('GET', '/api/invoices?page=1&per_page=10', [], 'o')->assertOk();
        $this->assertSame(3, $paged->json('total'));
        $this->assertSame(40000.0, (float) $paged->json('outstanding'));
        $this->assertCount(1, $this->api('GET', '/api/invoices?status=paid', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/invoices?status=draft', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/invoices?q=0002', [], 'o')->json());

        $rp = $this->api('GET', '/api/receipts?page=1', [], 'o')->assertOk();
        $this->assertSame(2, $rp->json('total'));
        $this->assertSame(44000.0, (float) $rp->json('totals.received'));
        $this->assertCount(1, $this->api('GET', '/api/receipts?mode=bank_transfer&q=0001', [], 'o')->json());

        $cust = $this->api('GET', "/api/customers/{$c->id}", [], 'o')->assertOk();
        $this->assertSame(84000.0, (float) $cust->json('invoiced'));
        $this->assertSame(40000.0, (float) $cust->json('outstanding'));
    }

    public function test_permissions_and_visibility(): void
    {
        $sales = $this->as('sales', 's');
        $c = $this->customer($sales);
        $d = $this->draft($c, 's');                                         // sales may create drafts
        $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 's')->assertStatus(403);
        $this->as('operations', 'o');
        $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk();
        $this->api('POST', "/api/invoices/{$d['id']}/receipts", ['amount' => 10], 's')->assertStatus(403);
        $this->api('POST', "/api/invoices/{$d['id']}/credit-notes", ['amount' => 10, 'reason' => 'x'], 'o')->assertStatus(403);

        $other = $this->as('sales', 'b');
        $this->api('GET', "/api/invoices/{$d['id']}", [], 'b')->assertStatus(404);
        $this->assertCount(0, $this->api('GET', '/api/invoices', [], 'b')->json());
        $this->api('GET', '/api/receipts', [], 'b')->assertOk();
        $this->assertNotNull($other);

        $this->as('nobody', 'n');
        $this->api('GET', '/api/invoices', [], 'n')->assertStatus(403);
        $this->api('GET', '/api/receipts', [], 'n')->assertStatus(403);
        $this->assertSame(0, Receipt::count());
        $this->assertNotNull(Quote::query()->first() ?? true);
    }
}
