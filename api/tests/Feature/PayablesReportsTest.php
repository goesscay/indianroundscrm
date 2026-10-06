<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class PayablesReportsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['crm.company.state' => 'Tamil Nadu']);
        Ledger::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::factory()->admin()->create();
        $this->loginAs($u, 'a');

        return $u;
    }

    private function supplier(string $name = 'Sea View Resort'): Supplier
    {
        static $n = 0;

        return Supplier::create(['name' => $name, 'email' => 'supplier'.++$n.'@example.com']);
    }

    private function deal(User $o, array $a = []): Deal
    {
        static $n = 0;
        $c = Customer::create(['customer_no' => 'IRC-P'.++$n, 'name' => 'Asha Rao '.$n, 'state' => 'Tamil Nadu', 'owner_id' => $o->id]);

        return Deal::create($a + ['deal_no' => 'IRD-P'.$n, 'customer_id' => $c->id, 'name' => 'Goa trip '.$n, 'owner_id' => $o->id, 'stage' => 'won', 'probability' => 100]);
    }

    private function bill(Supplier $s, array $over = [], array $lines = null): array
    {
        return $this->api('POST', '/api/finance/bills', $over + ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10',
            'lines' => $lines ?? [['description' => 'Hotel 4N', 'amount' => 15000]]], 'a')->assertOk()->json();
    }

    private function bal(string $code): float
    {
        $a = Account::where('code', $code)->first();
        $b = Ledger::balances()[$a->id] ?? ['debit' => 0.0, 'credit' => 0.0];

        return $a->balanceOf($b['debit'], $b['credit']);
    }

    private function entry(string $type, int|string $id): JournalEntry
    {
        return JournalEntry::with('lines.account')->where('source_type', $type)->where('source_id', $id)->whereNull('reverses_id')->firstOrFail();
    }

    private function assertBalanced(): void
    {
        $this->assertTrue($this->api('GET', '/api/finance/trial-balance', [], 'a')->assertOk()->json('balanced'));
    }

    private function invoice(Customer $c, array $over = []): array
    {
        $d = $this->api('POST', '/api/invoices', $over + ['customer_id' => (string) $c->id, 'title' => 'Goa', 'lines' => [['description' => 'Goa 4N', 'qty' => 2, 'unit_price' => 20000, 'tax_rate' => 5]]], 'a')->assertOk()->json();

        return $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'a')->assertOk()->json();
    }

    // ---------------------------------------------------------------- bills

    public function test_bill_posts_cost_and_payable_and_syncs_the_deal_cost(): void
    {
        $u = $this->admin();
        $s = $this->supplier();
        $deal = $this->deal($u, ['supplier_cost' => 99999]);
        $b = $this->bill($s, ['deal_id' => (string) $deal->id, 'supplier_ref' => 'INV-77'], [
            ['description' => 'Hotel 4N', 'amount' => 15000], ['description' => 'Transfers', 'amount' => 2500.5],
        ]);
        $this->assertSame('SB/26-27/0001', $b['bill_no']);
        $this->assertSame(17500.5, (float) $b['total']);
        $this->assertSame('open', $b['payment_status']);
        $this->assertSame(17500.5, (float) $b['balance']);
        $this->assertCount(2, $b['lines']);

        $e = $this->entry('bill', $b['id']);
        $this->assertSame(17500.5, (float) $e->lines->where('account.code', '5000')->sum('debit'));
        $this->assertSame(17500.5, (float) $e->lines->firstWhere('account.code', '2000')->credit);
        $this->assertSame($b['supplier_id'], (string) $e->lines->firstWhere('account.code', '2000')->supplier_id);
        $this->assertSame($deal->id, (int) $e->lines->firstWhere('account.code', '5000')->deal_id);
        $this->assertSame(17500.5, $this->bal('2000'));
        $this->assertSame(17500.5, (float) $deal->fresh()->supplier_cost); // typed cost replaced by the real bills

        // other expense accounts and a second deal per line
        $deal2 = $this->deal($u);
        $this->bill($s, [], [['description' => 'Flight', 'amount' => 8000, 'deal_id' => (string) $deal2->id], ['description' => 'Visa fee', 'amount' => 500, 'account_id' => (string) Account::where('code', '6900')->value('id')]]);
        $this->assertSame(8000.0, (float) $deal2->fresh()->supplier_cost);
        $this->assertSame(500.0, $this->bal('6900'));
        $this->assertBalanced();
    }

    public function test_bill_validation_and_duplicates(): void
    {
        $u = $this->admin();
        $s = $this->supplier();
        $post = fn (array $o) => $this->api('POST', '/api/finance/bills', $o + ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10', 'lines' => [['description' => 'x', 'amount' => 100]]], 'a');

        $post([])->assertOk();
        $post(['supplier_ref' => 'R1'])->assertOk();
        $post(['supplier_ref' => 'R1'])->assertStatus(409);
        $post(['supplier_ref' => 'R1', 'allow_duplicate' => true])->assertOk();
        $post(['supplier_id' => '99999'])->assertStatus(422);
        $post(['bill_date' => '2027-01-01'])->assertStatus(422);
        $post(['due_date' => '2026-10-01'])->assertStatus(422);
        $post(['deal_id' => '99999'])->assertStatus(422);
        $post(['lines' => []])->assertStatus(422);
        $post(['lines' => [['description' => '', 'amount' => 5]]])->assertStatus(422);
        $post(['lines' => [['description' => 'x', 'amount' => 0]]])->assertStatus(422);
        $post(['lines' => [['description' => 'x', 'amount' => 5, 'account_id' => (string) Account::where('code', '4000')->value('id')]]])->assertStatus(422); // not an expense account
        $this->assertNotNull($u);
    }

    public function test_cancel_bill_reverses_it_and_blocks_when_paid(): void
    {
        $this->admin();
        $s = $this->supplier();
        $b = $this->bill($s);
        $this->api('POST', "/api/finance/bills/{$b['id']}/cancel", [], 'a')->assertStatus(422); // reason
        $x = $this->api('POST', "/api/finance/bills/{$b['id']}/cancel", ['reason' => 'Entered twice'], 'a')->assertOk();
        $this->assertSame('cancelled', $x->json('payment_status'));
        $this->assertSame(0.0, $this->bal('2000'));
        $this->assertSame(0.0, $this->bal('5000'));
        $this->api('POST', "/api/finance/bills/{$b['id']}/cancel", ['reason' => 'again'], 'a')->assertStatus(409);
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 1], 'a')->assertStatus(409);

        $paid = $this->bill($s);
        $this->api('POST', "/api/finance/bills/{$paid['id']}/payments", ['amount' => 100], 'a')->assertOk();
        $this->api('POST', "/api/finance/bills/{$paid['id']}/cancel", ['reason' => 'x'], 'a')->assertStatus(409);
        $this->assertBalanced();
    }

    // ---------------------------------------------------------------- supplier payments and advances

    public function test_payments_against_a_bill_and_cancelling_them(): void
    {
        $this->admin();
        $s = $this->supplier();
        $bank = $this->api('POST', '/api/finance/banks', ['name' => 'HDFC Current', 'opening_balance' => 100000, 'opening_date' => '2026-04-01'], 'a')->assertOk();
        $b = $this->bill($s, ['due_date' => '2026-10-20']);

        $p1 = $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 6000, 'mode' => 'bank_transfer', 'reference' => 'UTR1', 'bank_account_id' => $bank->json('id')], 'a')->assertOk();
        $this->assertSame('SP/26-27/0001', $p1->json('payment.payment_no'));
        $this->assertSame('partial', $p1->json('bill.payment_status'));
        $this->assertSame(9000.0, (float) $p1->json('bill.balance'));
        $l = $this->entry('supplier_payment', $p1->json('payment.id'))->lines;
        $this->assertSame(6000.0, (float) $l->firstWhere('account.code', '2000')->debit);
        $this->assertSame(6000.0, (float) $l->firstWhere('account.code', '1011')->credit);
        $this->assertSame(94000.0, $this->bal('1011'));

        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 9000.5], 'a')->assertStatus(422); // more than the balance
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 0], 'a')->assertStatus(422);
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 5, 'paid_date' => '2027-01-01'], 'a')->assertStatus(422);
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 5, 'bank_account_id' => '9999'], 'a')->assertStatus(422);

        $p2 = $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 9000, 'mode' => 'cash'], 'a')->assertOk();
        $this->assertSame('paid', $p2->json('bill.payment_status'));
        $this->assertSame(-9000.0, $this->bal('1000'));
        $this->assertSame(0.0, $this->bal('2000'));

        $c = $this->api('POST', "/api/finance/supplier-payments/{$p2->json('payment.id')}/cancel", ['reason' => 'wrong bill'], 'a')->assertOk();
        $this->assertSame('cancelled', $c->json('status'));
        $this->assertSame(9000.0, (float) $this->api('GET', "/api/finance/bills/{$b['id']}", [], 'a')->json('balance'));
        $this->assertSame(9000.0, $this->bal('2000'));
        $this->api('POST', "/api/finance/supplier-payments/{$p2->json('payment.id')}/cancel", ['reason' => 'x'], 'a')->assertStatus(409);
        $this->assertBalanced();
    }

    public function test_supplier_advances_and_applying_them(): void
    {
        $this->admin();
        $s = $this->supplier();
        $other = $this->supplier('Other Hotel');
        $adv = $this->api('POST', "/api/finance/suppliers/{$s->id}/advances", ['amount' => 5000, 'reference' => 'ADV'], 'a')->assertOk()->json();
        $this->assertNull($adv['bill_id']);
        $this->assertSame(5000.0, $this->bal('1300'));
        $this->assertCount(1, $this->api('GET', '/api/finance/supplier-payments?advances=1', [], 'a')->json());

        $b = $this->bill($s);
        $big = $this->api('POST', "/api/finance/suppliers/{$s->id}/advances", ['amount' => 99000], 'a')->assertOk()->json('id');
        $this->api('POST', "/api/finance/supplier-payments/$big/apply", ['bill_id' => $b['id']], 'a')->assertStatus(422); // exceeds the balance
        $ok = $this->api('POST', "/api/finance/supplier-payments/{$adv['id']}/apply", ['bill_id' => $b['id']], 'a')->assertOk();
        $this->assertSame($b['id'], $ok->json('bill_id'));
        $this->assertSame(10000.0, (float) $this->api('GET', "/api/finance/bills/{$b['id']}", [], 'a')->json('balance'));
        $this->assertSame(10000.0, $this->bal('2000'));
        $this->assertSame(99000.0, $this->bal('1300')); // only the big advance remains
        $this->api('POST', "/api/finance/supplier-payments/{$adv['id']}/apply", ['bill_id' => $b['id']], 'a')->assertStatus(409);
        $ob = $this->bill($other);
        $this->api('POST', "/api/finance/supplier-payments/$big/apply", ['bill_id' => $ob['id']], 'a')->assertStatus(422); // other supplier

        // cancelling an applied advance reverses both postings
        $this->api('POST', "/api/finance/supplier-payments/{$adv['id']}/cancel", ['reason' => 'refunded'], 'a')->assertOk();
        $this->assertSame(15000.0 + 15000.0, $this->bal('2000'));
        $this->assertSame(99000.0, $this->bal('1300'));
        $this->assertBalanced();
    }

    // ---------------------------------------------------------------- other money

    public function test_money_in_and_out(): void
    {
        $this->admin();
        $bank = $this->api('POST', '/api/finance/banks', ['name' => 'HDFC Current'], 'a')->assertOk();
        $rent = (string) Account::where('code', '6200')->value('id');
        $other = (string) Account::where('code', '4100')->value('id');

        $e = $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => $rent, 'amount' => 8000, 'payee' => 'Landlord', 'reference' => 'Oct rent', 'bank_account_id' => $bank->json('id')], 'a')->assertOk();
        $this->assertSame('MO/26-27/0001', $e->json('entry_no'));
        $this->assertSame(8000.0, $this->bal('6200'));
        $this->assertSame(-8000.0, $this->bal('1011'));
        $i = $this->api('POST', '/api/finance/money', ['kind' => 'income', 'account_id' => $other, 'amount' => 1500, 'payee' => 'Commission', 'mode' => 'cash'], 'a')->assertOk();
        $this->assertSame('MI/26-27/0001', $i->json('entry_no'));
        $this->assertSame(1500.0, $this->bal('4100'));
        $this->assertSame(1500.0, $this->bal('1000'));

        $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => $other, 'amount' => 1], 'a')->assertStatus(422);   // income account for an expense
        $this->api('POST', '/api/finance/money', ['kind' => 'income', 'account_id' => $rent, 'amount' => 1], 'a')->assertStatus(422);
        $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => $rent, 'amount' => 0], 'a')->assertStatus(422);
        $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => $rent, 'amount' => 5, 'entry_date' => '2027-01-01'], 'a')->assertStatus(422);
        $this->api('POST', '/api/finance/money', ['kind' => 'weird', 'account_id' => $rent, 'amount' => 5], 'a')->assertStatus(422);

        $list = $this->api('GET', '/api/finance/money?page=1', [], 'a')->assertOk();
        $this->assertSame(2, $list->json('total'));
        $this->assertSame(8000.0, (float) $list->json('totals.expense'));
        $this->assertSame(1500.0, (float) $list->json('totals.income'));
        $this->assertCount(1, $this->api('GET', '/api/finance/money?kind=income', [], 'a')->json());

        $this->api('POST', "/api/finance/money/{$e->json('id')}/cancel", ['reason' => 'duplicate'], 'a')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0.0, $this->bal('6200'));
        $this->api('POST', "/api/finance/money/{$e->json('id')}/cancel", ['reason' => 'x'], 'a')->assertStatus(409);

        // closed books apply here too
        $this->api('PUT', '/api/finance/settings', ['lock_date' => '2026-10-14'], 'a')->assertOk();
        $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => $rent, 'amount' => 5, 'entry_date' => '2026-10-14'], 'a')->assertStatus(422);
        $this->assertBalanced();
    }

    public function test_deleting_a_supplier_with_books_is_refused(): void
    {
        $this->admin();
        $s = $this->supplier();
        $this->api('DELETE', "/api/suppliers/{$s->id}", [], 'a')->assertOk();   // no bills: fine
        $t = $this->supplier('Used One');
        $this->bill($t);
        $this->api('DELETE', "/api/suppliers/{$t->id}", [], 'a')->assertStatus(409);
        $this->assertSame(1, SupplierBill::count());
    }

    // ---------------------------------------------------------------- reports

    public function test_profit_and_loss_balance_sheet_and_gst_summary(): void
    {
        $u = $this->admin();
        $c = Customer::create(['customer_no' => 'IRC-R1', 'name' => 'Asha', 'state' => 'Tamil Nadu', 'owner_id' => $u->id]);
        $deal = $this->deal($u, ['customer_id' => $c->id]);
        $inv = $this->invoice($c, ['deal_id' => (string) $deal->id]);                       // sales 40000, GST 2000
        $this->bill($this->supplier(), ['deal_id' => (string) $deal->id], [['description' => 'Hotel', 'amount' => 15000]]);
        $this->api('POST', '/api/finance/money', ['kind' => 'expense', 'account_id' => (string) Account::where('code', '6200')->value('id'), 'amount' => 8000], 'a')->assertOk();
        $this->api('POST', "/api/invoices/{$inv['id']}/credit-notes", ['amount' => 10500, 'reason' => 'Guest dropped'], 'a')->assertOk();   // returns 10000, GST 500

        $pl = $this->api('GET', '/api/finance/reports/profit-loss', [], 'a')->assertOk();
        $this->assertSame('2026-04-01', $pl->json('from'));
        $this->assertSame('2026-10-15', $pl->json('to'));
        $this->assertEquals(30000, $pl->json('total_income'));           // 40000 sales - 10000 returns
        $this->assertEquals(15000, $pl->json('total_cost_of_sales'));
        $this->assertEquals(15000, $pl->json('gross_profit'));
        $this->assertEquals(8000, $pl->json('total_expenses'));
        $this->assertEquals(7000, $pl->json('net_profit'));
        $this->assertContains('Tour sales', array_column($pl->json('income'), 'name'));
        $this->assertSame(['5000'], array_column($pl->json('cost_of_sales'), 'code'));
        $this->assertEquals(0, $this->api('GET', '/api/finance/reports/profit-loss?from=2026-04-01&to=2026-09-30', [], 'a')->json('net_profit'));
        $this->api('GET', '/api/finance/reports/profit-loss?from=2026-10-01&to=2026-04-01', [], 'a')->assertStatus(422);

        $bs = $this->api('GET', '/api/finance/reports/balance-sheet', [], 'a')->assertOk();
        $this->assertTrue($bs->json('balanced'));
        $this->assertEquals(7000, $bs->json('profit_to_date'));
        // receivable 42000 - 10500 credit = 31500; bank -8000 => assets 23500; payable 15000 + GST 1500 = 16500; equity 7000
        $this->assertEquals(23500, $bs->json('total_assets'));
        $this->assertEquals(16500, $bs->json('total_liabilities'));
        $this->assertEquals(7000, $bs->json('total_equity'));
        $early = $this->api('GET', '/api/finance/reports/balance-sheet?as_of=2026-03-31', [], 'a')->assertOk();
        $this->assertTrue($early->json('balanced'));
        $this->assertEquals(0, $early->json('total_assets'));

        $gst = $this->api('GET', '/api/finance/reports/gst-summary', [], 'a')->assertOk();
        $this->assertCount(1, $gst->json('items'));
        $this->assertSame('2026-10', $gst->json('items.0.month'));
        $this->assertEquals(30000, $gst->json('items.0.taxable'));
        $this->assertEquals(750, $gst->json('items.0.cgst'));
        $this->assertEquals(750, $gst->json('items.0.sgst'));
        $this->assertEquals(0, $gst->json('items.0.igst'));
        $this->assertEquals(1500, $gst->json('totals.gst_total'));
        $this->assertCount(0, $this->api('GET', '/api/finance/reports/gst-summary?from=2026-04-01&to=2026-09-30', [], 'a')->json('items'));
    }

    public function test_ageing_reports(): void
    {
        $u = $this->admin();
        $line = [['description' => 'x', 'qty' => 1, 'unit_price' => 1000, 'tax_rate' => 5]];            // 1050
        $mk = fn (string $name, string $due) => $this->invoice(Customer::create(['customer_no' => 'IRC-'.$name, 'name' => $name, 'owner_id' => $u->id]),
            ['lines' => $line, 'installments' => [['label' => 'All', 'due_date' => $due, 'amount' => 1050]]]);
        $a = $mk('Alpha', '2026-10-10');   // 5 days late
        $mk('Bravo', '2026-08-01');        // 75 days late
        $mk('Charlie', '2026-12-01');      // not due
        $mk('Delta', '2026-06-01');        // 136 days late
        $this->api('POST', "/api/invoices/{$a['id']}/receipts", ['amount' => 50], 'a')->assertOk();

        $r = $this->api('GET', '/api/finance/reports/receivables-ageing', [], 'a')->assertOk();
        $by = collect($r->json('items'))->keyBy('customer');
        $this->assertEquals(1000, $by['Alpha']['d1_30']);
        $this->assertEquals(1050, $by['Bravo']['d61_90']);
        $this->assertEquals(1050, $by['Charlie']['current']);
        $this->assertEquals(1050, $by['Delta']['d90p']);
        $this->assertEquals(4150, $r->json('totals.balance'));
        $this->assertEquals(1050, $r->json('totals.d61_90'));

        // credit notes take from the latest instalments and paid invoices drop out
        $this->api('POST', "/api/invoices/{$a['id']}/receipts", ['amount' => 1000], 'a')->assertOk();
        $this->assertCount(3, $this->api('GET', '/api/finance/reports/receivables-ageing', [], 'a')->json('items'));

        $s = $this->supplier();
        $this->bill($s, ['bill_date' => '2026-08-01', 'due_date' => '2026-09-05'], [['description' => 'Late', 'amount' => 700]]);   // 40 days
        $this->bill($s, ['bill_date' => '2026-10-10'], [['description' => 'New', 'amount' => 300]]);                                  // due on bill date: 5 days
        $p = $this->api('GET', '/api/finance/reports/payables-ageing', [], 'a')->assertOk();
        $this->assertCount(2, $p->json('items'));
        $this->assertEquals(700, $p->json('totals.d31_60'));
        $this->assertEquals(300, $p->json('totals.d1_30'));
        $this->assertEquals(1000, $p->json('totals.balance'));
    }

    public function test_party_statements_and_deal_books(): void
    {
        $u = $this->admin();
        $c = Customer::create(['customer_no' => 'IRC-S1', 'name' => 'Statement Cust', 'state' => 'Tamil Nadu', 'owner_id' => $u->id]);
        $deal = $this->deal($u, ['customer_id' => $c->id]);
        $inv = $this->invoice($c, ['deal_id' => (string) $deal->id]);
        $this->api('POST', "/api/invoices/{$inv['id']}/receipts", ['amount' => 10000], 'a')->assertOk();
        $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 2000], 'a')->assertOk();

        $st = $this->api('GET', "/api/finance/party-ledger?type=customer&id={$c->id}", [], 'a')->assertOk();
        $this->assertEquals([42000, 32000, 30000], array_column($st->json('items'), 'balance'));
        $this->assertEquals(30000, $st->json('closing'));
        $this->assertSame('owes you', $st->json('position'));
        $part = $this->api('GET', "/api/finance/party-ledger?type=customer&id={$c->id}&from=2026-10-16", [], 'a')->assertOk();
        $this->assertEquals(30000, $part->json('opening'));

        $s = $this->supplier();
        $b = $this->bill($s, ['deal_id' => (string) $deal->id], [['description' => 'Hotel', 'amount' => 15000]]);
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 5000], 'a')->assertOk();
        $ss = $this->api('GET', "/api/finance/party-ledger?type=supplier&id={$s->id}", [], 'a')->assertOk();
        $this->assertEquals([15000, 10000], array_column($ss->json('items'), 'balance'));
        $this->assertSame('you owe them', $ss->json('position'));
        $this->api('GET', '/api/finance/party-ledger?type=supplier&id=99999', [], 'a')->assertStatus(404);
        $this->api('GET', '/api/finance/party-ledger?type=alien&id=1', [], 'a')->assertStatus(422);

        $books = $this->api('GET', "/api/deals/{$deal->id}/books", [], 'a')->assertOk();
        $this->assertEquals(40000, $books->json('total_revenue'));
        $this->assertEquals(15000, $books->json('total_costs'));
        $this->assertEquals(25000, $books->json('profit'));
        $this->assertEquals(62.5, $books->json('margin_pct'));
        $this->assertCount(1, $books->json('bills'));
        $this->assertSame(['5000'], array_column($books->json('costs'), 'code'));
    }

    public function test_dashboard_shows_what_is_owed_to_suppliers(): void
    {
        $this->admin();
        $this->bill($this->supplier(), [], [['description' => 'Hotel', 'amount' => 4000]]);
        $d = $this->api('GET', '/api/finance/dashboard', [], 'a')->assertOk();
        $this->assertEquals(4000, $d->json('payable'));
        $this->assertSame(1, $d->json('bills_outstanding.count'));
        $this->assertEquals(4000, $d->json('bills_outstanding.total'));
    }

    // ---------------------------------------------------------------- access

    public function test_permissions(): void
    {
        $s = $this->supplier();
        $this->loginAs(User::factory()->create(['role' => 'sales']), 's');
        $this->api('GET', '/api/finance/bills', [], 's')->assertStatus(403);
        $this->api('POST', '/api/finance/bills', ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10', 'lines' => [['description' => 'x', 'amount' => 1]]], 's')->assertStatus(403);
        $this->api('GET', '/api/finance/reports/profit-loss', [], 's')->assertStatus(403);
        $deal = $this->deal(User::factory()->create());
        $this->api('GET', "/api/deals/{$deal->id}/books", [], 's')->assertStatus(403);

        $this->loginAs(User::factory()->create(['role' => 'accountant']), 'c');
        $this->api('POST', '/api/finance/bills', ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10', 'lines' => [['description' => 'x', 'amount' => 1]]], 'c')->assertOk();
        $this->api('GET', '/api/finance/reports/receivables-ageing', [], 'c')->assertOk();
        $this->assertContains('finance.payables', \App\Support\Permissions::forRole('accountant'));
    }
}
