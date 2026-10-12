<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class AccountsLedgerTest extends TestCase
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

    private function customer(User $o, array $a = []): Customer
    {
        static $n = 0;

        return Customer::create($a + ['customer_no' => 'IRC-L'.++$n, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'state' => 'Tamil Nadu', 'owner_id' => $o->id]);
    }

    private function issue(Customer $c, array $over = []): array
    {
        $d = $this->api('POST', '/api/invoices', $over + ['customer_id' => (string) $c->id, 'title' => 'Goa', 'lines' => [['description' => 'Goa 4N', 'qty' => 2, 'unit_price' => 20000, 'tax_rate' => 5]]], 'a')->assertOk()->json();

        return $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'a')->assertOk()->json();
    }

    private function bal(string $code): float
    {
        $a = Account::where('code', $code)->first();
        $b = Ledger::balances()[$a->id] ?? ['debit' => 0.0, 'credit' => 0.0];

        return $a->balanceOf($b['debit'], $b['credit']);
    }

    private function entryFor(string $type, int|string $id): JournalEntry
    {
        return JournalEntry::with('lines.account')->where('source_type', $type)->where('source_id', $id)->whereNull('reverses_id')->firstOrFail();
    }

    private function assertAllBalanced(): void
    {
        foreach (JournalEntry::with('lines')->get() as $e) {
            $this->assertEqualsWithDelta($e->lines->sum('debit'), $e->lines->sum('credit'), 0.005, "{$e->entry_no} is unbalanced");
        }
        $tb = $this->api('GET', '/api/finance/trial-balance', [], 'a')->assertOk();
        $this->assertTrue($tb->json('balanced'));
    }

    // ---------------------------------------------------------------- chart and banking

    public function test_chart_is_seeded_and_protected(): void
    {
        $this->admin();
        $items = $this->api('GET', '/api/finance/accounts', [], 'a')->assertOk()->json('items');
        $codes = array_column($items, 'code');
        foreach (['1000', '1010', '1100', '2000', '2100', '2200', '2210', '2220', '2230', '2240', '3200', '4000', '4090', '5000'] as $c) {
            $this->assertContains($c, $codes);
        }
        $sales = collect($items)->firstWhere('code', '4000');
        $this->assertTrue($sales['is_system']);

        $new = $this->api('POST', '/api/finance/accounts', ['code' => '6700', 'name' => 'Staff welfare', 'type' => 'expense'], 'a')->assertOk();
        $this->api('POST', '/api/finance/accounts', ['code' => '6700', 'name' => 'Dup', 'type' => 'expense'], 'a')->assertStatus(409);
        $this->api('POST', '/api/finance/accounts', ['code' => 'bad code!', 'name' => 'x', 'type' => 'expense'], 'a')->assertStatus(422);
        $this->api('POST', '/api/finance/accounts', ['code' => '6701', 'name' => 'x', 'type' => 'wizard'], 'a')->assertStatus(422);
        $this->api('PATCH', "/api/finance/accounts/{$sales['id']}", ['code' => '4001'], 'a')->assertStatus(409);
        $this->api('PATCH', "/api/finance/accounts/{$sales['id']}", ['active' => false], 'a')->assertStatus(409);
        $this->api('PATCH', "/api/finance/accounts/{$sales['id']}", ['name' => 'Tour revenue'], 'a')->assertOk()->assertJsonPath('name', 'Tour revenue');
        $this->api('DELETE', "/api/finance/accounts/{$sales['id']}", [], 'a')->assertStatus(409);

        $this->api('PATCH', "/api/finance/accounts/{$new->json('id')}", ['code' => '6710', 'name' => 'Welfare', 'active' => false], 'a')->assertOk()->assertJsonPath('code', '6710');
        $this->assertCount(1, $this->api('GET', '/api/finance/accounts?type=expense&q=welfare', [], 'a')->json('items'));

        // an account with postings cannot be deleted or re-typed
        $this->api('POST', '/api/finance/journal', ['entry_date' => '2026-10-10', 'narration' => 'x', 'lines' => [
            ['account_id' => (int) $new->json('id'), 'debit' => 100], ['account_id' => Account::where('code', '1000')->value('id'), 'credit' => 100]]], 'a')->assertStatus(422); // inactive account
        $this->api('PATCH', "/api/finance/accounts/{$new->json('id')}", ['active' => true], 'a')->assertOk();
        $this->api('POST', '/api/finance/journal', ['entry_date' => '2026-10-10', 'narration' => 'x', 'lines' => [
            ['account_id' => (int) $new->json('id'), 'debit' => 100], ['account_id' => Account::where('code', '1000')->value('id'), 'credit' => 100]]], 'a')->assertOk();
        $this->api('DELETE', "/api/finance/accounts/{$new->json('id')}", [], 'a')->assertStatus(409);
        $this->api('PATCH', "/api/finance/accounts/{$new->json('id')}", ['type' => 'asset'], 'a')->assertStatus(409);
    }

    public function test_bank_accounts_get_their_own_ledger_account_and_opening_balance(): void
    {
        $this->admin();
        $hdfc = $this->api('POST', '/api/finance/banks', ['name' => 'HDFC Current', 'bank_name' => 'HDFC', 'last4' => '1234', 'opening_balance' => 50000, 'opening_date' => '2026-04-01'], 'a')->assertOk();
        $this->assertSame('1011', $hdfc->json('account_code'));
        $this->assertTrue($hdfc->json('is_default')); // the first one becomes the default
        $this->assertSame(50000.0, (float) $hdfc->json('balance'));
        $icici = $this->api('POST', '/api/finance/banks', ['name' => 'ICICI Savings', 'opening_balance' => -2000], 'a')->assertOk();
        $this->assertSame('1012', $icici->json('account_code'));
        $this->assertFalse($icici->json('is_default'));
        $this->assertSame(-2000.0, (float) $icici->json('balance'));
        $this->assertSame(48000.0, (float) $this->api('GET', '/api/finance/banks', [], 'a')->json('total'));
        $this->assertSame(48000.0, $this->bal('3200')); // opening balance equity carries the offset

        $this->api('PATCH', "/api/finance/banks/{$icici->json('id')}", ['is_default' => true], 'a')->assertOk();
        $list = $this->api('GET', '/api/finance/banks', [], 'a')->json('items');
        $this->assertSame(['ICICI Savings'], array_column(array_filter($list, fn ($b) => $b['is_default']), 'name'));
        $this->api('PATCH', "/api/finance/banks/{$icici->json('id')}", ['active' => false], 'a')->assertOk();
        $this->assertCount(1, $this->api('GET', '/api/finance/bank-options', [], 'a')->json());
        $this->assertAllBalanced();
    }

    // ---------------------------------------------------------------- automatic postings

    public function test_invoice_issue_posts_receivable_sales_and_gst_split(): void
    {
        $u = $this->admin();
        $same = $this->issue($this->customer($u));                       // Tamil Nadu -> CGST + SGST
        $e = $this->entryFor('invoice', $same['id']);
        $by = $e->lines->mapWithKeys(fn ($l) => [$l->account->code => [(float) $l->debit, (float) $l->credit]]);
        $this->assertSame([42000.0, 0.0], $by['1100']);
        $this->assertSame([0.0, 40000.0], $by['4000']);
        $this->assertSame([0.0, 1000.0], $by['2210']);
        $this->assertSame([0.0, 1000.0], $by['2220']);
        $this->assertSame($same['customer_id'], (string) $e->lines->firstWhere('account.code', '1100')->customer_id);
        $this->assertSame('2026-10-15', $e->entry_date->format('Y-m-d'));
        $this->assertStringContainsString($same['invoice_no'], $e->narration);

        $other = $this->issue($this->customer($u, ['state' => 'Kerala']), ['tcs_rate' => 5]);
        $e2 = $this->entryFor('invoice', $other['id']);
        $by2 = $e2->lines->mapWithKeys(fn ($l) => [$l->account->code => [(float) $l->debit, (float) $l->credit]]);
        $this->assertSame([44000.0, 0.0], $by2['1100']);      // 42000 + 5% TCS on the 40000 subtotal (not on the GST)
        $this->assertSame([0.0, 2000.0], $by2['2230']);       // IGST
        $this->assertSame([0.0, 2000.0], $by2['2240']);       // TCS payable
        $this->assertSame(86000.0, $this->bal('1100'));
        $this->assertAllBalanced();
    }

    public function test_cancel_unpaid_invoice_reverses_the_sale(): void
    {
        $u = $this->admin();
        $i = $this->issue($this->customer($u));
        $this->assertSame(42000.0, $this->bal('1100'));
        $this->api('POST', "/api/invoices/{$i['id']}/cancel", ['reason' => 'Mistake'], 'a')->assertOk();
        $this->assertSame(0.0, $this->bal('1100'));
        $this->assertSame(0.0, $this->bal('4000'));
        $orig = JournalEntry::where('source_type', 'invoice')->first();
        $this->assertNotNull($orig->reversed_at);
        $this->assertSame(1, JournalEntry::where('source_type', 'reversal')->where('reverses_id', $orig->id)->count());
        $this->assertAllBalanced();
    }

    public function test_receipts_advances_refunds_and_credit_notes_keep_receivable_in_step(): void
    {
        $u = $this->admin();
        $c = $this->customer($u);
        $bank = $this->api('POST', '/api/finance/banks', ['name' => 'HDFC Current'], 'a')->assertOk();
        $i = $this->issue($c);

        // receipts: chosen bank, default bank, cash
        $r1 = $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 10000, 'mode' => 'upi', 'bank_account_id' => $bank->json('id')], 'a')->assertOk()->json('receipt');
        $this->assertSame('HDFC Current', $r1['deposited_to']);
        $l = $this->entryFor('receipt', $r1['id'])->lines;
        $this->assertSame(10000.0, (float) $l->firstWhere('account.code', '1011')->debit);
        $this->assertSame(10000.0, (float) $l->firstWhere('account.code', '1100')->credit);
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 5000, 'mode' => 'cash'], 'a')->assertOk();
        $this->assertSame(5000.0, $this->bal('1000'));
        $r3 = $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 2000, 'mode' => 'bank_transfer'], 'a')->assertOk()->json('receipt');
        $this->assertSame(12000.0, $this->bal('1011')); // no bank chosen -> the default bank account
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 1, 'bank_account_id' => '9999'], 'a')->assertStatus(422);

        // cancel one: reversed
        $this->api('POST', "/api/receipts/{$r3['id']}/cancel", ['reason' => 'bounced'], 'a')->assertOk();
        $this->assertSame(10000.0, $this->bal('1011'));

        // advance and applying it
        $adv = $this->api('POST', "/api/customers/{$c->id}/advances", ['amount' => 7000, 'mode' => 'upi'], 'a')->assertOk()->json();
        $this->assertSame(7000.0, $this->bal('2100'));
        $this->assertSame(27000.0, $this->bal('1100'));
        $this->api('POST', "/api/receipts/{$adv['id']}/apply", ['invoice_id' => $i['id']], 'a')->assertOk();
        $this->assertSame(0.0, $this->bal('2100'));
        $this->assertSame(20000.0, $this->bal('1100'));

        // cancelling the applied advance reverses both postings
        $this->api('POST', "/api/receipts/{$adv['id']}/cancel", ['reason' => 'wrong customer'], 'a')->assertOk();
        $this->assertSame(0.0, $this->bal('2100'));
        $this->assertSame(27000.0, $this->bal('1100'));

        // credit note: returns + GST, receivable down; then refund of the surplus
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 27000, 'mode' => 'upi'], 'a')->assertOk(); // now fully paid
        $cn = $this->api('POST', "/api/invoices/{$i['id']}/credit-notes", ['amount' => 10500, 'reason' => 'Guest cancelled'], 'a')->assertOk()->json('credit_notes.0');
        $by = $this->entryFor('credit_note', $cn['id'])->lines->mapWithKeys(fn ($l) => [$l->account->code => [(float) $l->debit, (float) $l->credit]]);
        $this->assertSame([10000.0, 0.0], $by['4090']);
        $this->assertSame([250.0, 0.0], $by['2210']);
        $this->assertSame([250.0, 0.0], $by['2220']);
        $this->assertSame([0.0, 10500.0], $by['1100']);
        $this->assertSame(-10500.0, $this->bal('1100'));
        $rf = $this->api('POST', "/api/invoices/{$i['id']}/refunds", ['amount' => 10500, 'mode' => 'bank_transfer'], 'a')->assertOk()->json('receipt');
        $this->assertSame(0.0, $this->bal('1100'));
        $l = $this->entryFor('receipt', $rf['id'])->lines;
        $this->assertSame(10500.0, (float) $l->firstWhere('account.code', '1100')->debit);
        $this->assertSame(10500.0, (float) $l->firstWhere('account.code', '1011')->credit);

        // the invariant: receivable in the ledger equals what the invoices say is owed
        $owed = Invoice::where('status', 'issued')->get()->sum(fn ($x) => $x->total - $x->credited_total - $x->paid_total);
        $this->assertEqualsWithDelta($owed, $this->bal('1100'), 0.005);
        $this->assertAllBalanced();
    }

    // ---------------------------------------------------------------- manual journal

    public function test_manual_journal_rules_and_reversal(): void
    {
        $this->admin();
        $cash = Account::where('code', '1000')->value('id');
        $rent = Account::where('code', '6200')->value('id');
        $entry = fn (array $lines, array $over = []) => $this->api('POST', '/api/finance/journal', $over + ['entry_date' => '2026-10-10', 'narration' => 'Office rent', 'lines' => $lines], 'a');

        $ok = $entry([['account_id' => $rent, 'debit' => 15000.5, 'memo' => 'Oct rent'], ['account_id' => $cash, 'credit' => 15000.5]])->assertOk();
        $this->assertSame('JE/26-27/0001', $ok->json('entry_no'));
        $this->assertSame(15000.5, (float) $ok->json('total'));
        $this->assertTrue($ok->json('editable_by_hand'));
        $this->assertSame(15000.5, $this->bal('6200'));

        $entry([['account_id' => $rent, 'debit' => 100], ['account_id' => $cash, 'credit' => 99]])->assertStatus(422);                          // unbalanced
        $entry([['account_id' => $rent, 'debit' => 100, 'credit' => 100], ['account_id' => $cash, 'credit' => 0]])->assertStatus(422);          // both sides
        $entry([['account_id' => $rent, 'debit' => 100]])->assertStatus(422);                                                                   // one line
        $entry([['account_id' => $rent, 'debit' => 0], ['account_id' => $cash, 'credit' => 0]])->assertStatus(422);                              // no amounts
        $entry([['account_id' => 99999, 'debit' => 100], ['account_id' => $cash, 'credit' => 100]])->assertStatus(422);                         // unknown account
        $entry([['account_id' => $rent, 'debit' => 100], ['account_id' => $cash, 'credit' => 100]], ['entry_date' => '2027-01-01'])->assertStatus(422); // future
        $entry([['account_id' => $rent, 'debit' => 100], ['account_id' => $cash, 'credit' => 100]], ['narration' => ''])->assertStatus(422);

        $rev = $this->api('POST', "/api/finance/journal/{$ok->json('id')}/reverse", ['reason' => 'posted twice'], 'a')->assertOk();
        $this->assertTrue($rev->json('is_reversal'));
        $this->assertSame(0.0, $this->bal('6200'));
        $this->assertTrue($this->api('GET', "/api/finance/journal/{$ok->json('id')}", [], 'a')->json('reversed'));
        $this->api('POST', "/api/finance/journal/{$ok->json('id')}/reverse", [], 'a')->assertStatus(409);
        $this->api('POST', "/api/finance/journal/{$rev->json('id')}/reverse", [], 'a')->assertStatus(409);

        // sales entries are reversed by cancelling the document, not by hand
        $inv = $this->issue($this->customer(User::first()));
        $auto = $this->entryFor('invoice', $inv['id']);
        $this->api('POST', "/api/finance/journal/{$auto->id}/reverse", [], 'a')->assertStatus(409);

        $paged = $this->api('GET', '/api/finance/journal?page=1&per_page=2', [], 'a')->assertOk();
        $this->assertSame(2, $paged->json('per_page'));
        $this->assertCount(1, $this->api('GET', '/api/finance/journal?source=invoice', [], 'a')->json());
        $this->assertCount(2, $this->api('GET', "/api/finance/journal?account_id={$rent}", [], 'a')->json());
        $this->assertCount(1, $this->api('GET', '/api/finance/journal?q=0001&source=manual', [], 'a')->json());
    }

    // ---------------------------------------------------------------- closed books

    public function test_closing_the_books_blocks_postings_and_rolls_back_the_document(): void
    {
        $u = $this->admin();
        $c = $this->customer($u);
        $draft = $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'T', 'issue_date' => '2026-09-15',
            'lines' => [['description' => 'x', 'qty' => 1, 'unit_price' => 1000, 'tax_rate' => 5]]], 'a')->assertOk()->json();

        $this->api('PUT', '/api/finance/settings', ['lock_date' => '2026-12-01'], 'a')->assertStatus(422); // future
        $this->api('PUT', '/api/finance/settings', ['lock_date' => '2026-09-30'], 'a')->assertOk()->assertJsonPath('lock_date', '2026-09-30');

        $this->api('POST', "/api/invoices/{$draft['id']}/issue", [], 'a')->assertStatus(422);
        $this->assertSame('draft', Invoice::find($draft['id'])->status);      // nothing half-done
        $this->assertNull(Invoice::find($draft['id'])->invoice_no);
        $this->assertSame(0, JournalEntry::count());
        $this->api('POST', '/api/finance/journal', ['entry_date' => '2026-09-30', 'narration' => 'x', 'lines' => [
            ['account_id' => Account::where('code', '1000')->value('id'), 'debit' => 5], ['account_id' => Account::where('code', '4100')->value('id'), 'credit' => 5]]], 'a')->assertStatus(422);

        // after the lock, work carries on; and re-opening is possible
        $this->api('PATCH', "/api/invoices/{$draft['id']}", ['issue_date' => '2026-10-01'], 'a')->assertOk();
        $this->api('POST', "/api/invoices/{$draft['id']}/issue", [], 'a')->assertOk();
        $this->api('PUT', '/api/finance/settings', ['lock_date' => null], 'a')->assertOk();
        $this->assertNull($this->api('GET', '/api/finance/settings', [], 'a')->json('lock_date'));
    }

    // ---------------------------------------------------------------- reports

    public function test_ledger_trial_balance_and_dashboard(): void
    {
        $u = $this->admin();
        $c = $this->customer($u);
        $this->api('POST', '/api/finance/banks', ['name' => 'HDFC Current', 'opening_balance' => 100000, 'opening_date' => '2026-04-01'], 'a')->assertOk();
        $i = $this->issue($c);
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 20000, 'received_date' => '2026-10-10'], 'a')->assertOk();
        $this->api('POST', '/api/finance/journal', ['entry_date' => '2026-10-12', 'narration' => 'Rent', 'lines' => [
            ['account_id' => Account::where('code', '6200')->value('id'), 'debit' => 8000], ['account_id' => Account::where('code', '1011')->value('id'), 'credit' => 8000]]], 'a')->assertOk();

        $recv = Account::where('code', '1100')->value('id');
        $led = $this->api('GET', "/api/finance/ledger/$recv", [], 'a')->assertOk();
        $this->assertSame(0.0, (float) $led->json('opening'));
        $this->assertSame(22000.0, (float) $led->json('closing'));
        $this->assertEquals([-20000.0, 22000.0], array_column($led->json('items'), 'balance'));
        // from 12 Oct: the 10 Oct receipt is the opening balance, then the 15 Oct invoice
        $part = $this->api('GET', "/api/finance/ledger/$recv?from=2026-10-12", [], 'a')->assertOk();
        $this->assertSame(-20000.0, (float) $part->json('opening'));
        $this->assertEquals([22000.0], array_column($part->json('items'), 'balance'));
        $this->assertSame(1, $part->json('total'));

        $tb = $this->api('GET', '/api/finance/trial-balance', [], 'a')->assertOk();
        $this->assertTrue($tb->json('balanced'));
        $this->assertSame($tb->json('total_debit'), $tb->json('total_credit'));
        $early = $this->api('GET', '/api/finance/trial-balance?as_of=2026-04-30', [], 'a')->assertOk();
        $this->assertCount(2, $early->json('items')); // only the opening balance entry
        $this->assertSame(100000.0, (float) $early->json('total_debit'));

        $dash = $this->api('GET', '/api/finance/dashboard', [], 'a')->assertOk();
        $this->assertSame(22000.0, (float) $dash->json('receivable'));
        $this->assertSame(112000.0, (float) $dash->json('bank_total'));          // 100000 + 20000 - 8000
        $this->assertSame(40000.0, (float) $dash->json('month.income'));
        $this->assertSame(8000.0, (float) $dash->json('month.expenses'));
        $this->assertSame(2000.0, (float) $dash->json('gst_payable'));
        $this->assertSame(22000.0, (float) $dash->json('invoices_outstanding.total'));
        $this->assertSame(6, count($dash->json('months')));
        $this->assertSame('2026-10', $dash->json('months.5.month'));
        $this->assertSame(40000.0, (float) $dash->json('months.5.income'));
        $this->assertSame(40000.0, (float) $dash->json('year.income'));
        $this->api('GET', '/api/finance/ledger/99999', [], 'a')->assertStatus(404);
        $this->api('GET', '/api/finance/ledger/'.$recv.'?from=garbage', [], 'a')->assertStatus(422);
    }

    // ---------------------------------------------------------------- backfill and access

    public function test_backfill_posts_missing_documents_once(): void
    {
        $u = $this->admin();
        $c = $this->customer($u);
        $i = $this->issue($c);
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 1000], 'a')->assertOk();
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
        $this->assertSame(0, JournalEntry::count());

        Artisan::call('accounts:backfill', ['--dry-run' => true]);
        $this->assertStringContainsString('Would post: 1 invoices, 1 receipts, 0 credit notes', Artisan::output());
        $this->assertSame(0, JournalEntry::count());
        Artisan::call('accounts:backfill');
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(41000.0, $this->bal('1100'));
        Artisan::call('accounts:backfill');
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(1, Receipt::count());
        $this->assertAllBalanced();
    }

    public function test_permissions_and_the_accountant_role(): void
    {
        $this->as('sales', 's');
        $this->api('GET', '/api/finance/dashboard', [], 's')->assertStatus(403);
        $this->api('GET', '/api/finance/accounts', [], 's')->assertStatus(403);
        $this->as('operations', 'o');
        $this->api('GET', '/api/finance/trial-balance', [], 'o')->assertStatus(403);

        $acc = $this->as('accountant', 'c');
        $this->api('GET', '/api/finance/dashboard', [], 'c')->assertOk();
        $this->api('POST', '/api/finance/banks', ['name' => 'HDFC'], 'c')->assertOk();
        $this->api('GET', '/api/finance/bank-options', [], 'c')->assertOk();
        $this->assertNotNull($acc);
        $this->assertContains('finance.manage', \App\Support\Permissions::forRole('accountant'));
        $this->assertContains('receipts.cancel', \App\Support\Permissions::forRole('accountant'));
        $this->assertNotContains('finance.view', \App\Support\Permissions::forRole('sales'));

        // a user who may record payments (but not see the books) can still list the bank accounts to pick one
        $this->api('GET', '/api/finance/bank-options', [], 'o')->assertOk();
    }

    private function as(string $role, string $browser): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->loginAs($u, $browser);

        return $u;
    }
}
