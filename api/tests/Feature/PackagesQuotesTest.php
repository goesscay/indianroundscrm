<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\User;
use App\Services\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;

class PackagesQuotesTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function customer(User $owner, array $a = []): Customer
    {
        static $n = 0;

        return Customer::create($a + ['customer_no' => 'IRC-Q'.++$n, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'owner_id' => $owner->id]);
    }

    private function quoteBody(Customer $c, array $over = []): array
    {
        return $over + [
            'customer_id' => (string) $c->id, 'title' => 'Goa getaway', 'destination' => 'Goa', 'travel_date' => '2026-12-20', 'duration_days' => 5,
            'adults' => 2, 'children' => 1,
            'lines' => [
                ['kind' => 'package', 'description' => 'Goa 4N/5D - adults', 'qty' => 2, 'unit_price' => 20000, 'discount' => 1000, 'tax_rate' => 5],
                ['kind' => 'package', 'description' => 'Goa 4N/5D - child', 'qty' => 1, 'unit_price' => 10000, 'tax_rate' => 5],
                ['kind' => 'visa', 'description' => '', 'qty' => 1, 'unit_price' => 5], // blank description is dropped
            ],
        ];
    }

    // ---------------------------------------------------------------- packages

    public function test_package_crud_seasons_itinerary_and_rates(): void
    {
        $this->as('operations', 'o');
        $res = $this->api('POST', '/api/packages', [
            'name' => 'Goa Beach Break', 'destination' => 'Goa', 'category' => 'domestic', 'nights' => 4, 'days' => 5,
            'adult_price' => 20000, 'child_price' => 10000, 'adult_cost' => 15000, 'child_cost' => 8000, 'gst_rate' => 5,
            'inclusions' => 'Breakfast', 'seasons' => [
                ['name' => 'Peak', 'start_date' => '2026-12-15', 'end_date' => '2027-01-05', 'adult_price' => 28000, 'child_price' => 14000],
                ['name' => '', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02'],
            ],
            'itinerary' => [['title' => 'Arrival', 'description' => 'Transfer to hotel'], ['title' => ''], ['title' => 'Beaches']],
        ], 'o')->assertOk();
        $this->assertSame('PKG-0001', $res->json('code'));
        $this->assertCount(1, $res->json('seasons'));
        $this->assertSame([1, 2], array_column($res->json('itinerary'), 'day_no'));
        $id = $res->json('id');

        $this->assertSame(28000, (int) $this->api('GET', "/api/packages/$id/price?date=2026-12-25", [], 'o')->assertOk()->json('adult_price'));
        $this->assertSame('Peak', $this->api('GET', "/api/packages/$id/price?date=2026-12-25", [], 'o')->json('season'));
        $this->assertSame(20000, (int) $this->api('GET', "/api/packages/$id/price?date=2026-06-01", [], 'o')->json('adult_price'));
        $this->assertSame(20000, (int) $this->api('GET', "/api/packages/$id/price", [], 'o')->json('adult_price'));

        $this->api('PATCH', "/api/packages/$id", ['active' => false, 'adult_price' => 21000, 'seasons' => []], 'o')->assertOk()
            ->assertJsonPath('active', false)->assertJsonCount(0, 'seasons')->assertJsonCount(2, 'itinerary');
        $this->api('POST', '/api/packages', ['name' => 'X', 'code' => 'PKG-0001'], 'o')->assertStatus(409);
        $this->api('POST', '/api/packages', ['name' => 'Bad', 'seasons' => [['name' => 'S', 'start_date' => '2026-02-01', 'end_date' => '2026-01-01']]], 'o')->assertStatus(422);
        $this->api('POST', '/api/packages', ['name' => ''], 'o')->assertStatus(422);

        $this->assertCount(0, $this->api('GET', '/api/packages?active=1', [], 'o')->json());
        $this->assertCount(1, $this->api('GET', '/api/packages?q=goa', [], 'o')->json());
        $p = $this->api('GET', '/api/packages?page=1&per_page=5', [], 'o')->assertOk();
        $this->assertSame(1, $p->json('total'));
        $this->assertContains('Goa', $this->api('GET', '/api/packages/options', [], 'o')->json('destinations'));

        $this->api('DELETE', "/api/packages/$id", [], 'o')->assertOk();
        $this->api('GET', "/api/packages/$id", [], 'o')->assertStatus(404);
    }

    public function test_package_permissions(): void
    {
        $this->as('sales', 's'); // view only
        $this->api('GET', '/api/packages', [], 's')->assertOk();
        $this->api('POST', '/api/packages', ['name' => 'X'], 's')->assertStatus(403);
        $this->as('nobody', 'n');
        $this->api('GET', '/api/packages', [], 'n')->assertStatus(403);
    }

    // ---------------------------------------------------------------- quotes

    public function test_create_quote_calculates_totals_numbers_and_defaults(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $u = $this->as('sales', 's');
        $c = $this->customer($u);

        $r = $this->api('POST', '/api/quotes', $this->quoteBody($c, ['tcs_rate' => 5]), 's')->assertOk();
        $this->assertSame('IR-Q-26-0001', $r->json('quote_no'));
        $this->assertSame('draft', $r->json('status'));
        $this->assertCount(2, $r->json('lines'));
        // line 1: 2*20000-1000 = 39000 (+5% = 1950); line 2: 10000 (+500)
        $this->assertSame(50000.0, (float) $r->json('subtotal'));
        $this->assertSame(1000.0, (float) $r->json('discount_total'));
        $this->assertSame(2450.0, (float) $r->json('tax_total'));
        $this->assertSame(round(49000 * 0.05, 2), (float) $r->json('tcs_total'));            // TCS comes from the subtotal after discount, not from subtotal + GST
        $this->assertSame(round(49000 + 2450 + 2450, 2), (float) $r->json('total'));
        $this->assertSame('2026-10-15', $r->json('issue_date'));
        $this->assertSame('2026-10-29', $r->json('valid_until'));
        $this->assertSame('draft', $r->json('display_status'));

        $this->assertSame('IR-Q-26-0002', $this->api('POST', '/api/quotes', $this->quoteBody($c), 's')->json('quote_no'));

        // validation
        $bad = $this->quoteBody($c);
        $bad['lines'][0]['discount'] = 999999;
        $this->api('POST', '/api/quotes', $bad, 's')->assertStatus(422);
        $this->api('POST', '/api/quotes', $this->quoteBody($c, ['customer_id' => '99999']), 's')->assertStatus(422);
        $this->api('POST', '/api/quotes', ['customer_id' => (string) $c->id], 's')->assertStatus(422); // title missing
        Carbon::setTestNow();
    }

    public function test_quote_from_deal_inherits_trip_details(): void
    {
        $u = $this->as('sales', 's');
        $c = $this->customer($u);
        $deal = Deal::create(['deal_no' => 'IRD-1', 'customer_id' => $c->id, 'name' => 'Bali — Asha', 'destination' => 'Bali', 'travel_date' => '2027-02-10',
            'duration_days' => 6, 'adults' => 3, 'children' => 2, 'owner_id' => $u->id, 'stage' => 'enquiry']);

        $r = $this->api('POST', '/api/quotes', ['customer_id' => (string) $c->id, 'deal_id' => (string) $deal->id, 'title' => 'Bali trip',
            'lines' => [['description' => 'Package', 'qty' => 1, 'unit_price' => 100]]], 's')->assertOk();
        $this->assertSame('Bali', $r->json('destination'));
        $this->assertSame('2027-02-10', $r->json('travel_date'));
        $this->assertSame(3, $r->json('adults'));
        $this->assertSame($deal->deal_no, $r->json('deal.deal_no'));

        // a deal that belongs to another customer is refused
        $other = $this->customer($u, ['name' => 'Other']);
        $this->api('POST', '/api/quotes', ['customer_id' => (string) $other->id, 'deal_id' => (string) $deal->id, 'title' => 'X'], 's')->assertStatus(422);
    }

    public function test_edit_rules_revise_and_status_flow_with_deal_sync(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $u = $this->as('sales', 's');
        $c = $this->customer($u);
        $deal = Deal::create(['deal_no' => 'IRD-1', 'customer_id' => $c->id, 'name' => 'D', 'owner_id' => $u->id, 'stage' => 'enquiry', 'probability' => 10, 'selling_amount' => 1]);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c, ['deal_id' => (string) $deal->id]), 's')->json('id');

        $up = $this->api('PATCH', "/api/quotes/$id", ['title' => 'Goa v1', 'lines' => [['description' => 'All in', 'qty' => 1, 'unit_price' => 1000, 'tax_rate' => 18]]], 's')->assertOk();
        $this->assertSame('Goa v1', $up->json('title'));
        $this->assertSame(1180.0, (float) $up->json('total'));
        $this->api('PATCH', "/api/quotes/$id", ['title' => 'Only title'], 's')->assertOk()->assertJsonPath('total', 1180); // lines kept

        // draft -> sent (manual) moves the deal to Quoted
        $this->api('POST', "/api/quotes/$id/status", ['status' => 'accepted'], 's')->assertStatus(409);
        $this->api('POST', "/api/quotes/$id/status", ['status' => 'sent'], 's')->assertOk()->assertJsonPath('status', 'sent');
        $this->assertSame('quoted', $deal->fresh()->stage);
        $this->api('PATCH', "/api/quotes/$id", ['title' => 'nope'], 's')->assertStatus(409);
        $this->api('DELETE', "/api/quotes/$id", [], 's')->assertStatus(409);

        // revise: new draft version, old superseded, same number with suffix
        $rev = $this->api('POST', "/api/quotes/$id/revise", [], 's')->assertOk();
        $this->assertSame('IR-Q-26-0001-R2', $rev->json('quote_no'));
        $this->assertSame(2, $rev->json('version'));
        $this->assertSame('draft', $rev->json('status'));
        $this->assertCount(1, $rev->json('lines'));
        $this->assertSame('superseded', Quote::find($id)->status);
        $this->assertCount(1, $this->api('GET', '/api/quotes', [], 's')->json()); // superseded hidden
        $this->assertCount(1, $this->api('GET', '/api/quotes?status=superseded', [], 's')->json());
        $this->api('POST', "/api/quotes/{$rev->json('id')}/revise", [], 's')->assertStatus(409); // drafts are edited, not revised

        // accept the revision: deal selling amount follows the quote
        $rid = $rev->json('id');
        $this->api('POST', "/api/quotes/$rid/status", ['status' => 'sent'], 's')->assertOk();
        $this->api('POST', "/api/quotes/$rid/status", ['status' => 'accepted'], 's')->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertSame(1180.0, (float) $deal->fresh()->selling_amount);
        $this->assertNotNull(Quote::find($rid)->accepted_at);
        $this->api('POST', "/api/quotes/$rid/status", ['status' => 'sent'], 's')->assertOk(); // reopen

        $this->api('POST', "/api/quotes/$rid/status", ['status' => 'rejected', 'reason' => 'Too dear'], 's')->assertOk()->assertJsonPath('reject_reason', 'Too dear');
        Carbon::setTestNow();
    }

    public function test_expired_display_status_and_filter(): void
    {
        $u = $this->as('sales', 's');
        $c = $this->customer($u);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c, ['valid_until' => '2020-01-01']), 's')->json('id');
        $this->api('POST', "/api/quotes/$id/status", ['status' => 'sent'], 's')->assertOk()->assertJsonPath('display_status', 'expired');
        $this->assertCount(1, $this->api('GET', '/api/quotes?status=expired', [], 's')->json());
        $this->assertCount(0, $this->api('GET', '/api/quotes?status=sent', [], 's')->json());
    }

    public function test_visibility_and_permissions(): void
    {
        $a = $this->as('sales', 'a');
        $c = $this->customer($a);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c), 'a')->json('id');

        $this->as('sales', 'b');
        $this->api('GET', "/api/quotes/$id", [], 'b')->assertStatus(404);
        $this->assertCount(0, $this->api('GET', '/api/quotes', [], 'b')->json());
        $this->api('GET', "/api/quotes/$id/pdf", [], 'b')->assertStatus(404);

        $this->as('operations', 'o');
        $this->api('GET', "/api/quotes/$id", [], 'o')->assertOk();

        $this->as('nobody', 'n');
        $this->api('GET', '/api/quotes', [], 'n')->assertStatus(403);
        $this->api('POST', '/api/quotes', $this->quoteBody($c), 'n')->assertStatus(403);
    }

    public function test_pdf_is_a_real_pdf(): void
    {
        $u = $this->as('sales', 's');
        $c = $this->customer($u, ['gstin' => '29ABCDE1234F1Z5', 'city' => 'Pune']);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c, [
            'intro' => 'Hello', 'inclusions' => 'Breakfast', 'exclusions' => 'Flights', 'terms' => 'Pay 50% to confirm',
            'itinerary' => [['title' => 'Arrival', 'description' => 'Hotel check-in']],
        ]), 's')->json('id');
        $res = $this->spa('s')->get("/api/quotes/$id/pdf");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertGreaterThan(1500, strlen($res->getContent()));
        $this->assertStringContainsString('IR-Q-', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_send_by_email_attaches_pdf_marks_sent_and_moves_deal(): void
    {
        $fake = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $fake);
        $u = $this->as('sales', 's');
        $c = $this->customer($u);
        $deal = Deal::create(['deal_no' => 'IRD-1', 'customer_id' => $c->id, 'name' => 'D', 'owner_id' => $u->id, 'stage' => 'enquiry']);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c, ['deal_id' => (string) $deal->id]), 's')->json('id');

        $pre = $this->api('GET', "/api/quotes/$id/email-preview", [], 's')->assertOk();
        $this->assertSame(['asha@example.com'], $pre->json('to'));
        $this->assertStringContainsString('Dear Asha Rao', $pre->json('message'));

        $res = $this->api('POST', "/api/quotes/$id/send", ['to' => ['asha@example.com'], 'cc' => ['boss@example.com'],
            'subject' => 'Your quote', 'message' => 'Hi <b>Asha</b>'], 's')->assertOk();
        $this->assertSame('sent', $res->json('status'));
        $this->assertSame('sent', $res->json('quote.status'));
        $this->assertCount(1, $res->json('quote.emails'));
        $this->assertCount(1, $fake->sent);
        $m = $fake->sent[0];
        $this->assertTrue($m->html);
        $this->assertSame(['asha@example.com'], $m->to);
        $this->assertSame(['boss@example.com'], $m->cc);
        $this->assertCount(1, $m->attachments);
        $this->assertStringStartsWith('%PDF', $m->attachments[0]['bytes']);
        $this->assertStringContainsString('application/pdf', $m->attachments[0]['type']);
        $this->assertStringContainsString('Hi &lt;b&gt;Asha&lt;/b&gt;', $m->body); // message is escaped in the HTML body
        $this->assertSame('quoted', $deal->fresh()->stage);

        // without the PDF
        $this->api('POST', "/api/quotes/$id/send", ['to' => ['asha@example.com'], 'subject' => 'Again', 'message' => 'x', 'attach_pdf' => false], 's')->assertOk();
        $this->assertCount(0, $fake->sent[1]->attachments);
    }

    public function test_send_failures_and_validation(): void
    {
        $fake = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $fake);
        $u = $this->as('sales', 's');
        $c = $this->customer($u);
        $id = $this->api('POST', '/api/quotes', $this->quoteBody($c), 's')->json('id');
        $empty = $this->api('POST', '/api/quotes', ['customer_id' => (string) $c->id, 'title' => 'Empty'], 's')->json('id');

        $this->api('POST', "/api/quotes/$id/send", ['to' => ['not-an-email'], 'subject' => 's', 'message' => 'm'], 's')->assertStatus(422);
        $this->api('POST', "/api/quotes/$id/send", ['to' => [], 'subject' => 's', 'message' => 'm'], 's')->assertStatus(422);
        $this->api('POST', "/api/quotes/$empty/send", ['to' => ['a@b.co'], 'subject' => 's', 'message' => 'm'], 's')->assertStatus(422); // no lines

        $fake->failSend = 'Mailbox unavailable';
        $this->api('POST', "/api/quotes/$id/send", ['to' => ['a@b.co'], 'subject' => 's', 'message' => 'm'], 's')->assertStatus(502);
        $this->assertSame('draft', Quote::find($id)->status); // a failed send does not mark it sent
        $d = $this->api('GET', "/api/quotes/$id", [], 's')->json();
        $this->assertSame('failed', $d['emails'][0]['status']);
    }
}
