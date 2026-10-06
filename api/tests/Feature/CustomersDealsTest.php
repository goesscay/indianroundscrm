<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class CustomersDealsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function makeLead(string $browser, string $phone = '9876500001', string $name = 'Asha Rao'): string
    {
        return $this->api('POST', '/api/leads', ['customer_name' => $name, 'phone' => $phone, 'email' => 'asha@example.com',
            'destination' => 'Goa', 'travel_date' => '2026-12-20', 'duration_days' => 5, 'adults' => 2], $browser)->assertOk()->json('lead_id');
    }

    public function test_customer_crud_numbers_and_duplicate_guard(): void
    {
        $this->as('sales', 's');
        $res = $this->api('POST', '/api/customers', [
            'name' => 'Asha Rao', 'phone' => '+91 98765 00001', 'email' => 'Asha@Example.com', 'type' => 'family', 'gstin' => 'abc',
            'contacts' => [['name' => 'Ravi', 'phone' => '999', 'is_primary' => true], ['name' => '  ']],
            'travellers' => [['name' => 'Asha Rao', 'passport_no' => 'N1234567', 'passport_expiry' => '2030-01-01', 'dob' => '1990-05-04']],
        ], 's')->assertOk();
        $this->assertSame('IRC-00001', $res->json('customer_no'));
        $this->assertSame('ABC', $res->json('gstin'));
        $this->assertCount(1, $res->json('contacts'));
        $this->assertCount(1, $res->json('travellers'));
        $id = $res->json('id');

        // phone normalisation: same number written differently is a duplicate
        $this->api('POST', '/api/customers', ['name' => 'Other', 'phone' => '9876500001'], 's')->assertStatus(409);
        $this->api('POST', '/api/customers', ['name' => 'Other', 'phone' => '9876500001', 'allow_duplicate' => true], 's')->assertOk()
            ->assertJsonPath('customer_no', 'IRC-00002');
        $this->assertGreaterThanOrEqual(1, count($this->api('GET', '/api/customers/duplicate-check?phone=09876500001', [], 's')->assertOk()->json('matches')));

        $this->api('PATCH', "/api/customers/$id", ['city' => 'Pune', 'status' => 'inactive'], 's')->assertOk()
            ->assertJsonPath('city', 'Pune')->assertJsonPath('status', 'inactive')->assertJsonCount(1, 'contacts');
        $this->api('POST', '/api/customers', ['name' => ''], 's')->assertStatus(422);
        $this->api('POST', '/api/customers', ['name' => 'X', 'type' => 'alien'], 's')->assertStatus(422);

        $paged = $this->api('GET', '/api/customers?page=1&per_page=1', [], 's')->assertOk();
        $this->assertSame(2, $paged->json('total'));
        $this->assertCount(1, $paged->json('items'));
        $this->assertCount(2, $this->api('GET', '/api/customers', [], 's')->assertOk()->json());
        $this->assertCount(1, $this->api('GET', '/api/customers?q=asha', [], 's')->json());
    }

    public function test_passport_is_encrypted_at_rest_and_masked_without_permission(): void
    {
        $this->as('operations', 'o'); // has customers.view_passports
        $id = $this->api('POST', '/api/customers', ['name' => 'P', 'travellers' => [['name' => 'T', 'passport_no' => 'N1234567']]], 'o')->assertOk()->json('id');
        $this->assertStringNotContainsString('N1234567', (string) DB::table('customer_travellers')->value('passport_no'));
        $this->assertSame('N1234567', $this->api('GET', "/api/customers/$id", [], 'o')->json('travellers.0.passport_no'));

        $sales = $this->as('sales', 's');
        Customer::where('id', $id)->update(['owner_id' => $sales->id]);
        $t = $this->api('GET', "/api/customers/$id", [], 's')->assertOk();
        $this->assertSame('•••••567', $t->json('travellers.0.passport_no'));
        $this->assertTrue($t->json('travellers.0.passport_masked'));

        // saving the masked value back must not overwrite the real number
        $tr = $t->json('travellers');
        $this->api('PATCH', "/api/customers/$id", ['travellers' => $tr], 's')->assertOk();
        $this->assertSame('N1234567', CustomerTraveller::first()->passport_no);
    }

    public function test_visibility_scoping_and_permissions(): void
    {
        $a = $this->as('sales', 'a');
        $this->as('sales', 'b');
        $id = $this->api('POST', '/api/customers', ['name' => 'Mine', 'phone' => '9000000001'], 'a')->assertOk()->json('id');

        $this->api('GET', "/api/customers/$id", [], 'b')->assertStatus(404);
        $this->assertCount(0, $this->api('GET', '/api/customers', [], 'b')->assertOk()->json());
        $this->api('PATCH', "/api/customers/$id", ['city' => 'X'], 'b')->assertStatus(404);
        $this->api('DELETE', "/api/customers/$id", [], 'a')->assertStatus(403); // sales has no customers.delete

        $this->as('operations', 'ops'); // view_all
        $this->api('GET', "/api/customers/$id", [], 'ops')->assertOk();

        $this->as('nobody', 'n');
        $this->api('GET', '/api/customers', [], 'n')->assertStatus(403);
        $this->api('GET', '/api/deals', [], 'n')->assertStatus(403);
        $this->assertSame((string) $a->id, $this->api('GET', "/api/customers/$id", [], 'a')->json('owner.id'));
    }

    public function test_deal_stage_rules_and_numbering(): void
    {
        $this->as('sales', 's');
        $cid = $this->api('POST', '/api/customers', ['name' => 'Asha'], 's')->assertOk()->json('id');

        $d = $this->api('POST', '/api/deals', ['customer_id' => $cid, 'destination' => 'Bali', 'selling_amount' => 100000, 'supplier_cost' => 80000,
            'expected_close' => '2026-11-30', 'adults' => 2], 's')->assertOk();
        $this->assertSame('IRD-00001', $d->json('deal_no'));
        $this->assertSame('enquiry', $d->json('stage'));
        $this->assertSame(10, $d->json('probability'));
        $this->assertSame(20000, (int) $d->json('margin'));
        $this->assertSame(10000, (int) $d->json('weighted'));
        $this->assertSame('Bali — Asha', $d->json('name'));
        $id = $d->json('id');

        $this->api('PATCH', "/api/deals/$id", ['stage' => 'quoted'], 's')->assertOk()->assertJsonPath('probability', 40);
        $this->api('PATCH', "/api/deals/$id", ['stage' => 'lost'], 's')->assertStatus(422); // reason needed
        $lost = $this->api('PATCH', "/api/deals/$id", ['stage' => 'lost', 'lost_reason' => 'Too expensive'], 's')->assertOk();
        $this->assertSame(0, $lost->json('probability'));
        $this->assertNotNull($lost->json('closed_at'));

        $won = $this->api('PATCH', "/api/deals/$id", ['stage' => 'won'], 's')->assertOk();
        $this->assertSame(100, $won->json('probability'));
        $this->assertSame('confirmed', $won->json('trip_status'));
        $this->assertNull($won->json('lost_reason'));

        $re = $this->api('PATCH', "/api/deals/$id", ['stage' => 'negotiation'], 's')->assertOk();
        $this->assertNull($re->json('closed_at'));
        $this->assertNull($re->json('trip_status'));

        $this->api('POST', '/api/deals', ['customer_id' => $cid, 'stage' => 'bogus'], 's')->assertStatus(422);
        $this->api('POST', '/api/deals', ['customer_id' => 99999], 's')->assertStatus(422);
        $this->api('POST', '/api/deals', ['destination' => 'x'], 's')->assertStatus(422);

        $this->api('GET', "/api/customers/$cid", [], 's')->assertJsonPath('deals_count', 1)->assertJsonCount(1, 'deals');
        $this->api('DELETE', "/api/customers/$cid", [], 's')->assertStatus(403);
        $this->api('DELETE', "/api/deals/$id", [], 's')->assertStatus(403); // no deals.delete for sales

        $admin = $this->as('admin', 'adm');
        $this->api('DELETE', "/api/customers/$cid", [], 'adm')->assertStatus(409);
        $this->api('DELETE', "/api/deals/$id", [], 'adm')->assertOk();
        $this->api('DELETE', "/api/customers/$cid", [], 'adm')->assertOk();
        $this->assertNotNull($admin);
    }

    public function test_deal_filters_and_paging(): void
    {
        $this->as('sales', 's');
        $cid = $this->api('POST', '/api/customers', ['name' => 'Asha'], 's')->json('id');
        foreach (['enquiry', 'quoted', 'quoted'] as $i => $st) {
            $this->api('POST', '/api/deals', ['customer_id' => $cid, 'stage' => $st, 'destination' => "D$i", 'expected_close' => '2020-01-01'], 's')->assertOk();
        }
        $this->assertCount(2, $this->api('GET', '/api/deals?stage=quoted', [], 's')->json());
        $this->assertCount(3, $this->api('GET', '/api/deals?close=overdue', [], 's')->json());
        $p = $this->api('GET', '/api/deals?page=2&per_page=2', [], 's')->assertOk();
        $this->assertSame(3, $p->json('total'));
        $this->assertCount(1, $p->json('items'));
        $this->assertCount(1, $this->api('GET', '/api/deals?q=D1', [], 's')->json());
    }

    public function test_convert_lead_creates_customer_and_deal_and_links_everything(): void
    {
        $this->as('sales', 's', ['consultant_code' => 'SA']);
        $leadId = $this->makeLead('s');

        $pre = $this->api('GET', "/api/leads/$leadId/convert-preview", [], 's')->assertOk();
        $this->assertSame('Goa — Asha Rao', $pre->json('deal_suggestion.name'));
        $this->assertSame('enquiry', $pre->json('deal_suggestion.stage'));
        $this->assertSame([], $pre->json('matches'));

        $res = $this->api('POST', "/api/leads/$leadId/convert", ['deal' => ['selling_amount' => 55000]], 's')->assertOk();
        $this->assertTrue($res->json('customer.created'));
        $this->assertSame('IRC-00001', $res->json('customer.customer_no'));
        $this->assertSame('IRD-00001', $res->json('deal.deal_no'));

        $lead = Lead::where('lead_id', $leadId)->first();
        $this->assertNotNull($lead->customer_id);
        $this->assertNotNull($lead->deal_id);
        $this->assertNotNull($lead->converted_at);
        $deal = Deal::first();
        $this->assertSame($leadId, $deal->lead_id);
        $this->assertSame(55000.0, $deal->selling_amount);
        $this->assertSame('2026-12-20', $deal->travel_date->format('Y-m-d'));
        $this->assertSame(2, $deal->adults);

        $this->api('POST', "/api/leads/$leadId/convert", [], 's')->assertStatus(409);
        $this->assertSame((string) $lead->customer_id, $this->api('GET', "/api/leads/$leadId", [], 's')->json('customer_id'));
        $cust = $this->api('GET', "/api/customers/{$lead->customer_id}", [], 's')->assertOk();
        $this->assertSame($leadId, $cust->json('leads.0.lead_id'));
    }

    public function test_convert_links_an_existing_customer_and_detects_matches(): void
    {
        $this->as('sales', 's', ['consultant_code' => 'SA']);
        $cid = $this->api('POST', '/api/customers', ['name' => 'Asha R', 'phone' => '9876500001'], 's')->json('id');
        $leadId = $this->makeLead('s', '+91 98765 00001');

        $pre = $this->api('GET', "/api/leads/$leadId/convert-preview", [], 's')->assertOk();
        $this->assertSame($cid, $pre->json('matches.0.id'));

        $res = $this->api('POST', "/api/leads/$leadId/convert", ['customer_id' => $cid], 's')->assertOk();
        $this->assertFalse($res->json('customer.created'));
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Deal::count());
    }

    public function test_convert_customer_only_then_deal_later_and_stage_from_lead(): void
    {
        $this->as('sales', 's', ['consultant_code' => 'SA']);
        $leadId = $this->makeLead('s');
        Lead::where('lead_id', $leadId)->update(['lead_status' => 'Booked']);

        $this->api('POST', "/api/leads/$leadId/convert", ['create_deal' => false], 's')->assertOk()->assertJsonPath('deal', null);
        $this->assertSame(0, Deal::count());
        $this->assertNull(Lead::where('lead_id', $leadId)->value('converted_at'));

        $res = $this->api('POST', "/api/leads/$leadId/convert", [], 's')->assertOk();
        $this->assertFalse($res->json('customer.created')); // reuses the customer linked earlier
        $this->assertSame('won', Deal::first()->stage);
        $this->assertSame(1, Customer::count());
    }

    public function test_convert_requires_permission_and_lead_visibility(): void
    {
        $this->as('sales', 'a', ['consultant_code' => 'AA']);
        $leadId = $this->makeLead('a');
        $this->as('sales', 'b', ['consultant_code' => 'BB']);
        $this->api('POST', "/api/leads/$leadId/convert", [], 'b')->assertStatus(404);
        $this->as('nobody', 'n');
        $this->api('POST', "/api/leads/$leadId/convert", [], 'n')->assertStatus(403);
    }

    public function test_notes_and_activities_link_to_customer_and_deal(): void
    {
        $this->as('sales', 's', ['consultant_code' => 'SA']);
        $cid = $this->api('POST', '/api/customers', ['name' => 'Asha'], 's')->json('id');
        $did = $this->api('POST', '/api/deals', ['customer_id' => $cid], 's')->json('id');

        $n = $this->api('POST', "/api/customers/$cid/notes", ['note' => 'Prefers window seats'], 's')->assertOk();
        $this->assertCount(1, $this->api('GET', "/api/customers/$cid/notes", [], 's')->json());
        $this->api('POST', "/api/deals/$did/notes", ['note' => 'Sent itinerary'], 's')->assertOk();
        $this->api('POST', "/api/customers/$cid/notes", ['note' => ''], 's')->assertStatus(422);
        $this->api('DELETE', '/api/crm-notes/'.$n->json('id'), [], 's')->assertOk();
        $this->assertCount(0, $this->api('GET', "/api/customers/$cid/notes", [], 's')->json());

        $a = $this->api('POST', '/api/activities', ['title' => 'Call Asha', 'customer_id' => $cid, 'deal_id' => $did], 's')->assertOk();
        $this->assertSame('Asha', $a->json('customer.name'));
        $this->assertNotNull($a->json('deal'));
        $this->assertCount(1, $this->api('GET', "/api/customers/$cid/activities", [], 's')->json());
        $this->assertCount(1, $this->api('GET', "/api/deals/$did/activities", [], 's')->json());
        $this->api('POST', '/api/activities', ['title' => 'x', 'customer_id' => 99999], 's')->assertStatus(422);

        // another user cannot read or link what they cannot see
        $this->as('sales', 'o');
        $this->api('GET', "/api/customers/$cid/notes", [], 'o')->assertStatus(404);
        $this->api('POST', '/api/activities', ['title' => 'x', 'deal_id' => $did], 'o')->assertStatus(422);
        $this->api('DELETE', '/api/crm-notes/'.$this->api('POST', "/api/deals/$did/notes", ['note' => 'x'], 's')->json('id'), [], 'o')->assertStatus(404);
    }

    public function test_default_permissions_for_system_roles(): void
    {
        $sales = \App\Support\Permissions::defaults('sales');
        $this->assertContains('customers.create', $sales);
        $this->assertContains('leads.convert', $sales);
        $this->assertNotContains('customers.view_all', $sales);
        $this->assertNotContains('customers.view_passports', $sales);
        $this->assertContains('customers.view_passports', \App\Support\Permissions::defaults('operations'));
        $this->assertTrue(\App\Support\Permissions::isValid('deals.delete'));
    }
}
