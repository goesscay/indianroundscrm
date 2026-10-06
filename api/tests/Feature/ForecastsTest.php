<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\ForecastController;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class ForecastsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function deal(Customer $c, User $owner, array $a): Deal
    {
        static $n = 0;

        return Deal::create($a + ['deal_no' => 'IRD-T'.++$n, 'customer_id' => $c->id, 'name' => 'D'.$n, 'owner_id' => $owner->id,
            'stage' => 'enquiry', 'probability' => 10, 'selling_amount' => 100000, 'supplier_cost' => 80000]);
    }

    public function test_financial_year_and_quarter_math(): void
    {
        $this->assertSame(2026, ForecastController::fyOf(Carbon::create(2026, 4, 1)));
        $this->assertSame(2026, ForecastController::fyOf(Carbon::create(2026, 12, 31)));
        $this->assertSame(2025, ForecastController::fyOf(Carbon::create(2026, 3, 31)));
        $this->assertSame([1, 1, 1, 2, 3, 4], [
            ForecastController::quarterOf(Carbon::create(2026, 4, 1)), ForecastController::quarterOf(Carbon::create(2026, 6, 30)),
            ForecastController::quarterOf(Carbon::create(2026, 5, 15)), ForecastController::quarterOf(Carbon::create(2026, 7, 1)),
            ForecastController::quarterOf(Carbon::create(2026, 10, 1)), ForecastController::quarterOf(Carbon::create(2027, 1, 1)),
        ]);
    }

    public function test_quarters_won_pipeline_overdue_and_undated(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $u = $this->as('operations', 'o');
        $c = Customer::create(['customer_no' => 'IRC-1', 'name' => 'A', 'owner_id' => $u->id]);

        // won: closed in Q2 (Jul-Sep) and in Q3 (Oct-Dec) of FY2026; one closed in the previous FY (excluded)
        $this->deal($c, $u, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-08-10 06:00:00', 'selling_amount' => 200000, 'supplier_cost' => 150000]);
        $this->deal($c, $u, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-10-02 06:00:00', 'selling_amount' => 50000, 'supplier_cost' => 40000]);
        $this->deal($c, $u, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-02-10 06:00:00']);
        // 2026-03-31 20:00 UTC is 01:30 IST on 1 Apr 2026 => FY2026 Q1
        $this->deal($c, $u, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-03-31 20:00:00', 'selling_amount' => 10000, 'supplier_cost' => 0]);
        // open: Q3 on time, Q3 overdue (before today), Q4 (Jan 2027), undated, lost (ignored)
        $this->deal($c, $u, ['stage' => 'quoted', 'probability' => 40, 'expected_close' => '2026-12-01', 'selling_amount' => 100000]);
        $this->deal($c, $u, ['stage' => 'negotiation', 'probability' => 70, 'expected_close' => '2026-10-05', 'selling_amount' => 300000]);
        $this->deal($c, $u, ['stage' => 'enquiry', 'probability' => 10, 'expected_close' => '2027-01-20', 'selling_amount' => 500000]);
        $this->deal($c, $u, ['stage' => 'enquiry', 'probability' => 10, 'expected_close' => null, 'selling_amount' => 60000]);
        $this->deal($c, $u, ['stage' => 'lost', 'probability' => 0, 'closed_at' => '2026-09-01 00:00:00', 'expected_close' => '2026-09-01']);

        $r = $this->api('GET', '/api/forecasts?fy=2026', [], 'o')->assertOk();
        $this->assertSame('FY 2026–27', $r->json('label'));
        $q = $r->json('quarters');
        $this->assertSame(10000, (int) $q[0]['won']);
        $this->assertSame(200000, (int) $q[1]['won']);
        $this->assertSame(50000, (int) $q[2]['won']);
        $this->assertSame(50000 - 40000, (int) $q[2]['won_margin']);
        $this->assertSame(2, $q[2]['open_count']);
        $this->assertSame(400000, (int) $q[2]['open']);
        $this->assertSame(1, $q[2]['overdue_count']);
        $this->assertSame(300000, (int) $q[2]['overdue_value']);
        $this->assertSame(40000 + 210000, (int) $q[2]['weighted']);
        $this->assertSame(500000, (int) $q[3]['open']);
        $this->assertTrue($q[2]['current']);
        $this->assertSame(1, $r->json('undated.count'));
        $this->assertSame(260000, (int) $r->json('totals.won'));
        $this->assertSame(4, $r->json('totals.open_count'));
        $this->assertSame(1, $r->json('totals.overdue_count'));

        // previous FY only has the February win
        $this->assertSame(100000, (int) $this->api('GET', '/api/forecasts?fy=2025', [], 'o')->json('totals.won'));

        // drill-down
        $this->assertCount(1, $this->api('GET', '/api/forecasts/deals?fy=2026&quarter=3&kind=overdue', [], 'o')->assertOk()->json());
        $this->assertCount(2, $this->api('GET', '/api/forecasts/deals?fy=2026&quarter=3&kind=open', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/forecasts/deals?fy=2026&quarter=2&kind=won', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/forecasts/deals?fy=2026&kind=undated', [], 'o')->json());
        $this->api('GET', '/api/forecasts/deals?fy=2026&kind=won', [], 'o')->assertStatus(422);
        $this->api('GET', '/api/forecasts?fy=abc', [], 'o')->assertOk(); // falls back to the current FY
        Carbon::setTestNow();
    }

    public function test_targets_and_percentage_and_owner_breakdown(): void
    {
        $admin = $this->as('admin', 'a');
        $rep = User::factory()->create(['role' => 'sales']);
        $c = Customer::create(['customer_no' => 'IRC-1', 'name' => 'A', 'owner_id' => $admin->id]);
        $this->deal($c, $rep, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-08-10 06:00:00', 'selling_amount' => 50000]);

        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 2, 'amount' => 200000], 'a')->assertOk();
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 2, 'user_id' => (string) $rep->id, 'amount' => 80000], 'a')->assertOk();
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 1, 'user_id' => (string) $rep->id, 'amount' => 20000], 'a')->assertOk();

        $team = $this->api('GET', '/api/forecasts?fy=2026', [], 'a')->assertOk();
        $this->assertSame(200000, (int) $team->json('quarters.1.target'));
        $this->assertSame(20000, (int) $team->json('quarters.0.target')); // no team row: sum of consultants
        $this->assertSame(22.7, (float) $team->json('totals.pct'));
        $row = collect($team->json('owners'))->firstWhere('id', (string) $rep->id);
        $this->assertSame(50000, (int) $row['won']);
        $this->assertSame(100000, (int) $row['target']);

        $mine = $this->api('GET', "/api/forecasts?fy=2026&owner={$rep->id}", [], 'a')->assertOk();
        $this->assertSame(80000, (int) $mine->json('quarters.1.target'));
        $this->assertSame(62.5, $mine->json('quarters.1.won') / $mine->json('quarters.1.target') * 100);

        // clearing a target
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 2, 'amount' => 0], 'a')->assertOk();
        $this->assertSame(80000, (int) $this->api('GET', '/api/forecasts?fy=2026', [], 'a')->json('quarters.1.target')); // falls back to the sum
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 9, 'amount' => 1], 'a')->assertStatus(422);
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 1, 'user_id' => '99999', 'amount' => 1], 'a')->assertStatus(422);
    }

    public function test_permissions_and_own_deals_scope(): void
    {
        $a = $this->as('sales', 'a');
        $b = User::factory()->create(['role' => 'sales']);
        $c = Customer::create(['customer_no' => 'IRC-1', 'name' => 'A', 'owner_id' => $a->id]);
        $this->deal($c, $a, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-08-10 06:00:00', 'selling_amount' => 70000]);
        $this->deal($c, $b, ['stage' => 'won', 'probability' => 100, 'closed_at' => '2026-08-11 06:00:00', 'selling_amount' => 30000]);

        // a sales user only ever sees their own deals, whatever owner= says
        $r = $this->api('GET', "/api/forecasts?fy=2026&owner={$b->id}", [], 'a')->assertOk();
        $this->assertSame(70000, (int) $r->json('totals.won'));
        $this->assertFalse($r->json('can_view_all'));
        $this->assertSame([], $r->json('owners'));
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 1, 'amount' => 5], 'a')->assertStatus(403);

        $this->as('operations', 'o');
        $this->assertSame(100000, (int) $this->api('GET', '/api/forecasts?fy=2026', [], 'o')->json('totals.won'));
        $this->api('PUT', '/api/forecasts/targets', ['fy' => 2026, 'quarter' => 1, 'amount' => 5], 'o')->assertStatus(403); // view_all but no manage

        $this->as('nobody', 'n');
        $this->api('GET', '/api/forecasts', [], 'n')->assertStatus(403);
    }
}
