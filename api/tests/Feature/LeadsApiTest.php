<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Lead;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadsApiTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role, array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['role' => $role, 'consultant_code' => 'KA'], $attrs));
        Sanctum::actingAs($u, ['*'], 'sanctum');
        return $u;
    }

    private function leadPayload(array $over = []): array
    {
        return array_merge([
            'customer_name' => 'Asha Rao', 'phone' => '+91 98765 43210', 'destination' => 'Bali',
            'travel_date' => '2026-12-10', 'duration_days' => 5, 'adults' => 2, 'children' => 0,
            'stay_cities' => [['city' => 'Ubud', 'nights' => 2], ['city' => 'Seminyak', 'nights' => 2]],
        ], $over);
    }

    public function test_create_lead_generates_id_and_derived_fields(): void
    {
        $u = $this->as('sales');
        $r = $this->postJson('/api/leads', $this->leadPayload())->assertOk();
        $r->assertJsonPath('phone', '9876543210')
            ->assertJsonPath('trip_end_date', '2026-12-14')
            ->assertJsonPath('duration_label', '4N / 5D')
            ->assertJsonPath('stay_summary', 'Ubud 2N + Seminyak 2N')
            ->assertJsonPath('lead_status', 'New Lead')
            ->assertJsonPath('staff_id', (string) $u->id)
            ->assertJsonPath('profit', 0);
        $this->assertMatchesRegularExpression('/^IRKA\d{2}001$/', $r->json('lead_id'));
        $this->assertIsString($r->json('id'));
        $this->assertCount(2, $r->json('stay_cities'));

        // sequence increments per consultant per month
        $r2 = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9876543211']))->assertOk();
        $this->assertStringEndsWith('002', $r2->json('lead_id'));
    }

    public function test_create_validation_and_duplicate_block(): void
    {
        $this->as('sales');
        $this->postJson('/api/leads', $this->leadPayload(['phone' => '12345']))->assertStatus(422);
        $this->postJson('/api/leads', $this->leadPayload(['children' => 2, 'child_ages' => '4']))->assertStatus(422);
        $this->postJson('/api/leads', $this->leadPayload())->assertOk();
        $dup = $this->postJson('/api/leads', $this->leadPayload())->assertStatus(409);
        $dup->assertJsonPath('detail.message', 'Lead already exists for this mobile number.');
        $this->postJson('/api/leads?force=true', $this->leadPayload())->assertStatus(409); // sales cannot force

        $this->as('admin');
        $this->postJson('/api/leads?force=true', $this->leadPayload())->assertOk();
        $this->assertDatabaseCount('duplicate_log', 1);
    }

    public function test_destination_allocation_assigns_owner(): void
    {
        $consultant = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ME', 'name' => 'Meena']);
        Allocation::create(['destination' => 'Bali', 'consultant_id' => $consultant->id]);
        $this->as('admin');
        $r = $this->postJson('/api/leads', $this->leadPayload())->assertOk();
        $r->assertJsonPath('staff_id', (string) $consultant->id)->assertJsonPath('staff_name', 'Meena');
        $this->assertStringStartsWith('IRME', $r->json('lead_id'));
    }

    public function test_sales_visibility_is_scoped_to_own_leads(): void
    {
        $a = $this->as('sales', ['consultant_code' => 'AA']);
        $la = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9000000001']))->json('lead_id');
        $b = $this->as('sales', ['consultant_code' => 'BB']);
        $lb = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9000000002']))->json('lead_id');

        // B sees only own
        $ids = collect($this->getJson('/api/leads')->assertOk()->json())->pluck('lead_id')->all();
        $this->assertSame([$lb], $ids);
        $this->getJson("/api/leads/{$la}")->assertStatus(403);
        $this->patchJson("/api/leads/{$la}", ['remarks' => 'x'])->assertStatus(403);
        $this->getJson("/api/leads/{$la}/notes")->assertStatus(403);
        $this->getJson('/api/leads/duplicate-check?phone=9000000001')->assertJsonCount(0, 'matches');

        // admin sees all, ops too
        $this->as('admin');
        $this->assertCount(2, $this->getJson('/api/leads')->json());
        $this->assertCount(1, $this->getJson('/api/leads?staff_id='.$a->id)->json());
        $this->as('operations');
        $this->assertCount(2, $this->getJson('/api/leads')->json());
        $this->getJson('/api/leads/'.$la)->assertOk();
        $this->getJson('/api/leads/NOPE')->assertStatus(404)->assertJsonPath('detail', 'Lead not found');
    }

    public function test_update_lead_rules(): void
    {
        $this->as('sales');
        $id = $this->postJson('/api/leads', $this->leadPayload())->json('lead_id');

        // recompute derived fields on duration change; sales cannot set supplier_quote_amount
        $r = $this->patchJson("/api/leads/{$id}", ['duration_days' => 7, 'supplier_quote_amount' => 999,
            'selling_amount' => 5000, 'follow_up_date' => '2026-12-01'])->assertOk();
        $r->assertJsonPath('trip_end_date', '2026-12-16')->assertJsonPath('duration_label', '6N / 7D')
            ->assertJsonPath('supplier_quote_amount', 0)->assertJsonPath('selling_amount', 5000)
            ->assertJsonPath('profit', 5000);

        $r = $this->patchJson("/api/leads/{$id}", ['stay_cities' => [['city' => 'Kuta', 'nights' => 3]]])->assertOk();
        $r->assertJsonPath('stay_summary', 'Kuta 3N')->assertJsonCount(1, 'stay_cities');

        $this->postJson("/api/leads/{$id}/notes", ['note' => '  hello '])->assertOk()->assertJsonPath('note', 'hello');
        $this->postJson("/api/leads/{$id}/notes", ['note' => '  '])->assertStatus(400);
        $nid = $this->getJson("/api/leads/{$id}/notes")->assertJsonCount(1)->json('0.id');
        $this->postJson("/api/leads/{$id}/contact-events", ['kind' => 'call'])->assertOk()->assertJsonPath('channel', 'customer');
        $this->getJson("/api/leads/{$id}/contact-events")->assertJsonCount(1);

        // operations can set supplier amounts; only admin deletes
        $this->as('operations');
        $this->patchJson("/api/leads/{$id}", ['supplier_quote_amount' => 3000])->assertOk()->assertJsonPath('profit', 2000);
        $this->deleteJson("/api/leads/{$id}")->assertStatus(403);
        $this->deleteJson("/api/notes/{$nid}")->assertStatus(403);
        $this->as('admin');
        $this->deleteJson("/api/notes/{$nid}")->assertOk();
        $this->deleteJson("/api/leads/{$id}")->assertOk();
        $this->getJson("/api/leads/{$id}")->assertStatus(404);
    }

    public function test_search_calendar_followups_and_reassign(): void
    {
        $this->as('admin');
        $id = $this->postJson('/api/leads', $this->leadPayload())->json('lead_id');
        $this->patchJson("/api/leads/{$id}", ['follow_up_date' => now()->addHours(5)->toIso8601String()])->assertOk();
        $this->assertCount(1, $this->getJson('/api/leads?q=asha')->json());
        $this->assertCount(0, $this->getJson('/api/leads?q=zzz')->json());
        $this->assertCount(1, $this->getJson('/api/leads?status=New%20Lead&destination=Bali')->json());
        $cal = $this->getJson('/api/leads/calendar?month=2026-12')->assertOk()->json();
        $this->assertSame('travel', $cal[0]['kind']);
        $this->getJson('/api/followups/upcoming')->assertStatus(404);   // follow-ups are Activities now

        // trip dates by month or by range (end exclusive); follow-ups are on the Activities calendar, not here
        $this->assertCount(0, $this->getJson('/api/leads/calendar?from=2026-01-01&to=2026-12-01')->assertOk()->json());
        $this->assertCount(1, $this->getJson('/api/leads/calendar?from=2026-12-01&to=2027-01-01')->assertOk()->json());
        $this->assertSame(['travel'], collect($this->getJson('/api/leads/calendar')->json())->pluck('kind')->unique()->values()->all());

        $other = User::factory()->create(['role' => 'sales', 'consultant_code' => 'ZZ', 'name' => 'Zed']);
        $ops = User::factory()->create(['role' => 'operations']);
        $this->postJson("/api/leads/{$id}/reassign", ['consultant_id' => (string) $ops->id])->assertStatus(400);
        $this->postJson("/api/leads/{$id}/reassign", ['consultant_id' => (string) $other->id, 'reason' => 'x'])
            ->assertOk()->assertJsonPath('staff_name', 'Zed')->assertJsonPath('staff_code', 'ZZ');
        $this->assertDatabaseHas('activity_log', ['action' => 'reassign_lead', 'entity_id' => $id]);

        $this->as('sales');
        $this->postJson("/api/leads/{$id}/reassign", ['consultant_id' => (string) $other->id])->assertStatus(403);
    }

    public function test_rsq_flow(): void
    {
        $sales = $this->as('sales');
        $id = $this->postJson('/api/leads', $this->leadPayload())->json('lead_id');
        $supplier = Supplier::create(['name' => 'Wanderlust', 'email' => 'sales@wanderlust.demo']);

        $r = $this->postJson('/api/rsq-requests', ['lead_id' => $id, 'note' => 'pls'])->assertOk();
        $r->assertJsonPath('status', 'new')->assertJsonPath('supplier_count', 0);
        $this->postJson('/api/rsq-requests', ['lead_id' => $id])->assertJsonPath('already_exists', true);
        $this->getJson("/api/leads/{$id}")->assertJsonPath('rsq_status', 'new')->assertJsonPath('quote_type', 'rsq');
        $this->getJson('/api/rsq-requests')->assertStatus(403);
        $this->postJson('/api/supplier-mail/send', ['lead_id' => $id, 'supplier_ids' => [(string) $supplier->id], 'subject' => 's', 'body' => 'b'])->assertStatus(403);
        $this->getJson("/api/supplier-mail/preview/{$id}")->assertStatus(403);

        $this->as('operations');
        $this->assertCount(1, $this->getJson('/api/rsq-requests')->assertOk()->json('new'));
        $this->assertNotEmpty($this->getJson('/api/notifications')->json());
        $pv = $this->getJson("/api/supplier-mail/preview/{$id}")->assertOk();
        $this->assertStringContainsString('TRIP DETAILS', $pv->json('text'));
        $this->assertSame("Trip Enquiry - {$id} - Bali", $pv->json('subject'));

        $send = $this->postJson('/api/supplier-mail/send', [
            'lead_id' => $id, 'supplier_ids' => [(string) $supplier->id], 'subject' => $pv->json('subject'),
            'body' => $pv->json('text'), 'cc' => ['cc@example.com'],
        ])->assertOk();
        $send->assertJsonPath('send_result.status', 'simulated_sent')->assertJsonPath('to_suppliers.0.name', 'Wanderlust')
            ->assertJsonPath('cc.0', 'cc@example.com');
        $this->getJson("/api/leads/{$id}")->assertJsonPath('rsq_status', 'pending_reply')
            ->assertJsonPath('lead_status', 'Supplier Mail Sent')->assertJsonPath('mail_status', 'Sent');
        $this->assertCount(1, $this->getJson('/api/rsq-requests')->json('pending_reply'));
        $this->getJson("/api/supplier-mail/{$id}")->assertJsonCount(1);

        $tr = $this->getJson("/api/lead-suppliers/{$id}")->assertOk()->json();
        $this->assertSame(1, $tr[0]['sequence']);
        $tid = $tr[0]['id'];
        $this->patchJson("/api/lead-suppliers/{$tid}", ['quote_received' => true])->assertStatus(400);
        $this->postJson("/api/lead-suppliers/{$tid}/followup")->assertOk();
        $this->patchJson("/api/lead-suppliers/{$tid}", ['status' => 'Quote Received', 'quote_amount' => 50000])
            ->assertOk()->assertJsonPath('reply_received', true)->assertJsonPath('quote_amount', 50000);
        $this->assertCount(1, $this->getJson('/api/rsq-requests')->json('response_received'));
        $this->assertCount(0, $this->getJson('/api/rsq-requests')->json('pending_reply'));

        $sum = $this->getJson('/api/lead-suppliers/dashboard/summary')->assertOk();
        $sum->assertJsonPath('sent_today', 1)->assertJsonPath('quotes_received_today', 1)->assertJsonPath('awaiting_replies', 0);

        $this->patchJson("/api/leads/{$id}", ['lead_status' => 'Quote Sent to Customer'])->assertOk();
        $this->getJson("/api/leads/{$id}")->assertJsonPath('rsq_status', 'completed');
        $this->assertCount(1, $this->getJson('/api/rsq-requests')->json('completed'));
    }

    public function test_dashboard_stats_leaderboard_activity_and_ai(): void
    {
        $this->as('sales', ['consultant_code' => 'AA']);
        $id = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9000000001']))->json('lead_id');
        $this->patchJson("/api/leads/{$id}", ['booking_status' => 'Booked', 'selling_amount' => 10000])->assertOk();
        $this->postJson('/api/leads', $this->leadPayload(['phone' => '9000000002', 'destination' => 'Dubai']))->assertOk();

        $s = $this->getJson('/api/dashboard/stats')->assertOk();
        $s->assertJsonPath('total_leads', 2)->assertJsonPath('today_leads', 2)->assertJsonPath('month_leads', 2)
            ->assertJsonPath('booked', 1)->assertJsonPath('revenue', 10000)->assertJsonPath('profit', 10000)
            ->assertJsonPath('pending_payments', 1)->assertJsonPath('status_counts.New Lead', 2)
            ->assertJsonCount(2, 'top_destinations')->assertJsonCount(1, 'monthly');
        $this->getJson('/api/dashboard/leaderboard')->assertStatus(403);
        $this->getJson('/api/activity')->assertStatus(403);

        // another sales user sees zero
        $this->as('sales', ['consultant_code' => 'BB']);
        $this->getJson('/api/dashboard/stats')->assertJsonPath('total_leads', 0);

        $this->as('admin');
        $this->getJson('/api/dashboard/stats')->assertJsonPath('total_leads', 2);
        $lb = $this->getJson('/api/dashboard/leaderboard')->assertOk()->json();
        $this->assertSame(2, $lb[0]['leads']);
        $this->assertSame(50, (int) $lb[0]['conversion_rate']);
        $this->assertNotEmpty($this->getJson('/api/activity?action=create_lead')->assertOk()->json());
        $this->getJson('/api/activity/facets')->assertOk()->assertJsonStructure(['actions', 'entities', 'users']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/leads')->assertStatus(401);
        $this->getJson('/api/dashboard/stats')->assertStatus(401);
    }

    public function test_full_edit_of_a_lead_by_admin_and_limited_edit_by_sales(): void
    {
        $this->as('admin');
        $id = $this->postJson('/api/leads', $this->leadPayload())->assertOk()->json('lead_id');
        $other = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9123456780', 'customer_name' => 'Other']))->assertOk()->json('lead_id');

        $r = $this->patchJson("/api/leads/$id", [
            'customer_name' => 'Asha R. Menon', 'phone' => '+91 98765 11111', 'email' => 'asha@example.com', 'destination' => 'Goa', 'lead_source' => 'Referral',
            'travel_date' => '2027-01-05', 'duration_days' => 6, 'adults' => 3, 'children' => 1, 'child_ages' => '7', 'rooms' => 2,
            'hotel_category' => '4 Star', 'special_requests' => 'Sea view',
            'stay_cities' => [['city' => 'Calangute', 'nights' => 3], ['city' => 'Palolem', 'nights' => 2]],
        ])->assertOk();
        $r->assertJsonPath('customer_name', 'Asha R. Menon')->assertJsonPath('phone', '9876511111')->assertJsonPath('destination', 'Goa')
            ->assertJsonPath('lead_source', 'Referral')->assertJsonPath('trip_end_date', '2027-01-10')->assertJsonPath('stay_summary', 'Calangute 3N + Palolem 2N');
        $this->assertSame($id, $r->json('lead_id'));              // identity and status are untouched
        $this->assertSame('New Lead', $r->json('lead_status'));

        // another lead's mobile number cannot be taken
        $this->patchJson("/api/leads/$id", ['phone' => '9123456780'])->assertStatus(409);
        $this->patchJson("/api/leads/$id", ['phone' => '09876511111'])->assertOk(); // the same number written differently is fine
        $this->assertNotNull($other);

        // a consultant may edit the trip details of their own lead but not the source
        $sales = $this->as('sales');
        $mine = $this->postJson('/api/leads', $this->leadPayload(['phone' => '9000000001']))->assertOk()->json('lead_id');
        $e = $this->patchJson("/api/leads/$mine", ['customer_name' => 'Edited by consultant', 'destination' => 'Kerala', 'lead_source' => 'Instagram'])->assertOk();
        $this->assertSame('Edited by consultant', $e->json('customer_name'));
        $this->assertSame('Kerala', $e->json('destination'));
        $this->assertNotSame('Instagram', $e->json('lead_source'));
        $this->assertSame((string) $sales->id, $e->json('staff_id'));
    }
}
