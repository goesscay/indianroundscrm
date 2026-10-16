<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class OperationsBillsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        Ledger::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_operations_can_enter_see_and_undo_their_own_supplier_bills_but_not_pay_or_open_the_books(): void
    {
        $ops = User::factory()->operations()->create();
        $this->assertTrue($ops->hasPermission('finance.bills'));
        $this->assertFalse($ops->hasPermission('finance.payables'));
        $this->loginAs($ops, 'o');
        $s = Supplier::create(['name' => 'Sea View Resort', 'email' => 'sv@example.com']);

        $opts = $this->api('GET', '/api/finance/bill-options', [], 'o')->assertOk()->json('accounts');
        $this->assertContains('5000', array_column($opts, 'code'));                                         // the form can offer the cost accounts

        $b = $this->api('POST', '/api/finance/bills', ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10', 'supplier_ref' => 'INV-9',
            'lines' => [['description' => 'Hotel 4N', 'amount' => 15000]]], 'o')->assertOk()->json();
        $this->assertSame((string) $ops->id, (string) $b['created_by']);
        $this->assertSame('open', $b['payment_status']);

        $list = $this->api('GET', '/api/finance/bills?page=1&per_page=10', [], 'o')->assertOk()->json();
        $this->assertCount(1, $list['items'] ?? $list['data'] ?? $list);
        $this->assertNull($list['outstanding']);                                                             // what the company owes is not for them
        $this->api('GET', "/api/finance/bills/{$b['id']}", [], 'o')->assertOk()->assertJsonPath('bill_no', $b['bill_no']);

        // nothing else of Accounts
        $this->api('POST', "/api/finance/bills/{$b['id']}/payments", ['amount' => 100], 'o')->assertStatus(403);
        $this->api('GET', '/api/finance/accounts', [], 'o')->assertStatus(403);
        $this->api('GET', '/api/finance/trial-balance', [], 'o')->assertStatus(403);

        // someone else's bill stays with finance; their own can be taken back
        $theirs = SupplierBill::create(['bill_no' => 'SB/26-27/0099', 'supplier_id' => $s->id, 'bill_date' => '2026-10-01', 'total' => 500, 'paid_total' => 0, 'status' => 'open', 'created_by' => User::factory()->admin()->create()->id]);
        $this->api('POST', "/api/finance/bills/{$theirs->id}/cancel", ['reason' => 'x'], 'o')->assertStatus(403);
        $this->api('POST', "/api/finance/bills/{$b['id']}/cancel", ['reason' => 'Entered twice by mistake'], 'o')->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_sales_cannot_enter_supplier_bills(): void
    {
        $this->loginAs(User::factory()->sales()->create(), 's');
        $s = Supplier::create(['name' => 'Sea View', 'email' => 'sv2@example.com']);
        $this->api('POST', '/api/finance/bills', ['supplier_id' => (string) $s->id, 'bill_date' => '2026-10-10', 'lines' => [['description' => 'x', 'amount' => 1]]], 's')->assertStatus(403);
        $this->api('GET', '/api/finance/bills', [], 's')->assertStatus(403);
    }
}
