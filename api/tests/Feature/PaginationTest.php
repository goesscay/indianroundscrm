<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role): User
    {
        $u = User::factory()->create(['role' => $role, 'consultant_code' => 'KA']);
        Sanctum::actingAs($u, ['*'], 'sanctum');
        return $u;
    }

    private function seedLeads(User $owner, int $n, string $prefix, string $dest = 'Bali'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $l = Lead::create([
                'lead_id' => sprintf('%s%03d', $prefix, $i), 'customer_name' => "Cust $prefix $i",
                'phone' => '9'.str_pad((string) crc32($prefix.$i), 9, '0'), 'destination' => $dest,
                'staff_id' => $owner->id, 'lead_status' => $i % 2 ? 'New Lead' : 'Booked',
            ]);
            // identical created_at to exercise id tie-breaking
            Lead::where('id', $l->id)->update(['created_at' => '2026-01-01 00:00:00+00']);
        }
    }

    public function test_leads_shape_boundaries_and_clamp(): void
    {
        $admin = $this->as('admin');
        $this->seedLeads($admin, 30, 'A');

        $r = $this->getJson('/api/leads?page=1')->assertOk();
        $this->assertSame(['items', 'total', 'page', 'per_page', 'pages'], array_keys($r->json()));
        $this->assertCount(25, $r->json('items'));
        $this->assertSame([30, 1, 25, 2], [$r->json('total'), $r->json('page'), $r->json('per_page'), $r->json('pages')]);
        $this->assertArrayHasKey('profit', $r->json('items.0'));
        $this->assertArrayHasKey('stay_cities', $r->json('items.0'));

        $this->assertCount(5, $this->getJson('/api/leads?page=2')->json('items'));
        $past = $this->getJson('/api/leads?page=9')->assertOk();
        $this->assertSame([], $past->json('items'));
        $this->assertSame([30, 9, 2], [$past->json('total'), $past->json('page'), $past->json('pages')]);

        $this->assertSame(200, $this->getJson('/api/leads?page=1&per_page=9999')->json('per_page'));
        $this->assertSame(1, $this->getJson('/api/leads?page=1&per_page=0')->json('per_page'));
        $this->assertSame(1, $this->getJson('/api/leads?page=1&per_page=-5')->json('per_page'));
        $this->assertSame(25, $this->getJson('/api/leads?page=1&per_page=abc')->json('per_page'));
        $this->assertSame(1, $this->getJson('/api/leads?page=0')->json('page'));
    }

    public function test_no_overlap_between_pages_even_with_equal_timestamps(): void
    {
        $admin = $this->as('admin');
        $this->seedLeads($admin, 23, 'B');
        $ids = [];
        for ($p = 1; $p <= 5; $p++) {
            foreach ($this->getJson("/api/leads?page=$p&per_page=5")->json('items') as $it) {
                $ids[] = $it['lead_id'];
            }
        }
        $this->assertCount(23, $ids);
        $this->assertCount(23, array_unique($ids));
    }

    public function test_totals_reflect_filters_and_search(): void
    {
        $admin = $this->as('admin');
        $this->seedLeads($admin, 10, 'C', 'Bali');
        $this->seedLeads($admin, 4, 'D', 'Dubai');

        $this->assertSame(4, $this->getJson('/api/leads?page=1&destination=Dubai')->json('total'));
        $this->assertSame(7, $this->getJson('/api/leads?page=1&status=New Lead')->json('total'));
        $r = $this->getJson('/api/leads?page=1&per_page=2&q=Cust C&status=Booked');
        $this->assertSame(5, $r->json('total'));
        $this->assertSame(3, $r->json('pages'));
        $this->assertSame(0, $this->getJson('/api/leads?page=1&q=zzzz')->json('total'));
    }

    public function test_sales_scoping_never_leaks(): void
    {
        $sales = $this->as('sales');
        $other = User::factory()->create(['role' => 'sales', 'consultant_code' => 'XY']);
        $this->seedLeads($sales, 3, 'E');
        $this->seedLeads($other, 6, 'F');

        $r = $this->getJson('/api/leads?page=1')->assertOk();
        $this->assertSame(3, $r->json('total'));
        foreach ($r->json('items') as $it) {
            $this->assertSame((string) $sales->id, $it['staff_id']);
        }
        // staff_id filter ignored without view_all
        $this->assertSame(3, $this->getJson('/api/leads?page=1&staff_id='.$other->id)->json('total'));

        $this->as('admin');
        $this->assertSame(9, $this->getJson('/api/leads?page=1')->json('total'));
    }

    public function test_legacy_mode_unchanged(): void
    {
        $admin = $this->as('admin');
        $this->seedLeads($admin, 30, 'G');
        $r = $this->getJson('/api/leads')->assertOk();
        $this->assertCount(30, $r->json());
        $this->assertArrayHasKey(0, $r->json());
        $this->assertArrayNotHasKey('items', $r->json());
        $this->assertCount(4, $this->getJson('/api/leads?limit=4')->json());
    }

    public function test_activity_pagination_and_legacy(): void
    {
        $this->as('admin');
        for ($i = 1; $i <= 12; $i++) {
            ActivityLog::create(['user_email' => 'a@x.com', 'action' => $i % 3 ? 'create' : 'delete', 'entity' => 'lead',
                'entity_id' => "L$i", 'timestamp' => '2026-02-01 00:00:00+00']);
        }
        $r = $this->getJson('/api/activity?page=1&per_page=5')->assertOk();
        $this->assertSame([12, 3, 5], [$r->json('total'), $r->json('pages'), count($r->json('items'))]);
        $this->assertSame(4, $this->getJson('/api/activity?page=1&action=delete')->json('total'));
        $this->assertSame(1, $this->getJson('/api/activity?page=1&q=L12')->json('total'));
        $ids = [];
        for ($p = 1; $p <= 3; $p++) {
            foreach ($this->getJson("/api/activity?page=$p&per_page=5")->json('items') as $it) {
                $ids[] = $it['id'];
            }
        }
        $this->assertCount(12, array_unique($ids));
        $this->assertSame([], $this->getJson('/api/activity?page=4&per_page=5')->json('items'));

        $legacy = $this->getJson('/api/activity')->assertOk()->json();
        $this->assertCount(12, $legacy);
        $this->assertArrayHasKey(0, $legacy);
    }

    public function test_security_audit_log_pagination_and_permission(): void
    {
        $this->as('admin');
        for ($i = 1; $i <= 7; $i++) {
            SecurityAuditLog::create(['user_email' => $i > 4 ? 'b@x.com' : 'a@x.com', 'event' => 'login_success',
                'timestamp' => '2026-03-01 00:00:00+00']);
        }
        $r = $this->getJson('/api/security-audit-log?page=2&per_page=3')->assertOk();
        $this->assertSame([7, 2, 3, 3], [$r->json('total'), $r->json('page'), $r->json('pages'), count($r->json('items'))]);
        $this->assertSame(3, $this->getJson('/api/security-audit-log?page=1&user_email=b@x.com')->json('total'));
        $this->assertCount(7, $this->getJson('/api/security-audit-log')->json());

        $this->as('sales');
        $this->getJson('/api/security-audit-log?page=1')->assertStatus(403);
        $this->getJson('/api/activity?page=1')->assertStatus(403);
    }
}
