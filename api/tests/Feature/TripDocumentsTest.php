<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class TripDocumentsTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        Storage::fake('trip_documents');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role, string $browser): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->loginAs($u, $browser);

        return $u;
    }

    /** a confirmed international trip for a customer with a father, a mother and a 6-year-old */
    private function trip(User $owner, string $type = 'international'): array
    {
        $c = Customer::create(['customer_no' => 'IRC-D'.random_int(100, 999), 'name' => 'Asha Rao', 'owner_id' => $owner->id]);
        $deal = Deal::create(['deal_no' => 'IRD-D'.random_int(100, 999), 'customer_id' => $c->id, 'name' => 'Bali', 'destination' => 'Bali', 'stage' => 'won', 'travel_date' => '2026-12-20',
            'duration_days' => 6, 'owner_id' => $owner->id, 'selling_amount' => 100000, 'trip_type' => $type, 'trip_confirmed_at' => now(), 'trip_status' => 'confirmed']);
        $t = [
            CustomerTraveller::create(['customer_id' => $c->id, 'name' => 'Ravi Rao', 'dob' => '1985-03-01', 'passport_no' => 'N1234567', 'passport_expiry' => '2031-01-01', 'position' => 0]),
            CustomerTraveller::create(['customer_id' => $c->id, 'name' => 'Asha Rao', 'dob' => '1987-04-02', 'passport_no' => 'N7654321', 'passport_expiry' => '2027-03-01', 'position' => 1]),   // 3 months after the return: too short
            CustomerTraveller::create(['customer_id' => $c->id, 'name' => 'Kid Rao', 'dob' => '2020-06-01', 'position' => 2]),
        ];

        return [$deal, $t];
    }

    private function pdf(string $name = 'passport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    public function test_requirements_depend_on_trip_type_and_who_is_travelling(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops);
        $r = $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id, (string) $t[1]->id, (string) $t[2]->id]], 'o')->assertOk();
        $slots = collect($r->json('slots'));
        $for = fn ($tid) => $slots->where('traveller_id', (string) $tid)->pluck('doc_type')->sort()->values()->all();
        $this->assertSame(['id_proof', 'pan', 'passport', 'photo', 'vaccination', 'visa'], $for($t[0]->id));
        $this->assertSame(['age_proof', 'id_proof', 'pan', 'passport', 'photo', 'vaccination', 'visa'], $for($t[2]->id));   // the child also needs an age proof
        $trip = $slots->whereNull('traveller_id')->pluck('doc_type')->sort()->values()->all();
        $this->assertSame(['gst', 'insurance', 'payment_proof', 'terms'], $trip);
        // mandatory: id, passport, photo, visa each (+ age proof for the child) + trip insurance + terms
        $this->assertSame(4 + 4 + 5 + 2, $r->json('summary.required'));
        $this->assertTrue($r->json('international'));
        $this->assertSame('2026-12-25', $r->json('trip_end'));

        $deal->update(['trip_type' => 'domestic']);
        $r = $this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->assertOk();
        $this->assertSame(3 + 1 + 1, $r->json('summary.required'));              // id for three, age proof for the child, signed terms
        $this->assertSame(['age_proof', 'id_proof'], collect($r->json('slots'))->where('traveller_id', (string) $t[2]->id)->pluck('doc_type')->sort()->values()->all());

        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 'o')->assertOk()->assertJsonPath('summary.required', 2);
        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => ['999999']], 'o')->assertStatus(422);
    }

    public function test_passport_validity_is_checked_against_six_months_after_the_return(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops);
        $r = $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id, (string) $t[1]->id, (string) $t[2]->id]], 'o')->assertOk();
        $v = collect($r->json('travellers'))->pluck('passport_validity', 'name')->all();
        $this->assertSame(['Ravi Rao' => 'ok', 'Asha Rao' => 'short', 'Kid Rao' => 'unknown'], $v);
        $this->api('PATCH', "/api/deals/{$deal->id}/trip/travellers/{$t[1]->id}", ['passport_expiry' => '2026-12-01'], 'o')->assertOk();
        $this->assertSame('expired', collect($this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->json('travellers'))->firstWhere('name', 'Asha Rao')['passport_validity']);
    }

    public function test_upload_verify_download_and_encryption(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops, 'domestic');
        $r = $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 'o')->assertOk();
        $slot = collect($r->json('slots'))->firstWhere('doc_type', 'id_proof');

        $up = $this->spa('o')->post("/api/trip-documents/{$slot['id']}/files", ['file' => $this->pdf('aadhaar.pdf')], ['Accept' => 'application/json'])->assertOk();
        $s = collect($up->json('slots'))->firstWhere('id', $slot['id']);
        $this->assertSame('received', $s['status']);
        $this->assertSame('aadhaar.pdf', $s['files'][0]['name']);
        $path = DB::table('trip_document_files')->value('path');
        $this->assertStringNotContainsString('%PDF', Storage::disk('trip_documents')->get($path));                      // stored encrypted
        $fid = $s['files'][0]['id'];
        $dl = $this->spa('o')->get("/api/trip-document-files/$fid/download")->assertOk();
        $this->assertStringStartsWith('%PDF', $dl->getContent());
        $this->assertSame('application/pdf', $dl->headers->get('Content-Type'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $this->assertDatabaseHas('activity_log', ['action' => 'view_trip_document']);

        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'verified'], 'o')->assertOk();
        $this->assertSame('verified', collect($this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->json('slots'))->firstWhere('id', $slot['id'])['status']);
        $this->spa('o')->post("/api/trip-documents/{$slot['id']}/files", ['file' => $this->pdf('second.pdf')], ['Accept' => 'application/json'])->assertOk();   // a new file needs checking again
        $this->assertSame('received', DB::table('trip_documents')->where('id', $slot['id'])->value('status'));

        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'rejected', 'notes' => 'Blurred, send again'], 'o')->assertOk();
        $this->assertSame('rejected', DB::table('trip_documents')->where('id', $slot['id'])->value('status'));
        $this->api('DELETE', "/api/trip-document-files/$fid", [], 'o')->assertOk();
        Storage::disk('trip_documents')->assertMissing($path);
    }

    public function test_bad_files_and_unverifiable_documents_are_refused(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops, 'domestic');
        $r = $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 'o')->assertOk();
        $slot = collect($r->json('slots'))->firstWhere('doc_type', 'id_proof');
        $this->spa('o')->post("/api/trip-documents/{$slot['id']}/files", ['file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422);   // content is not a PDF or image
        $this->spa('o')->post("/api/trip-documents/{$slot['id']}/files", [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'verified'], 'o')->assertStatus(409);       // nothing uploaded yet
        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'na', 'notes' => 'Customer travelling with a passport only'], 'o')->assertOk();
    }

    public function test_all_verified_moves_the_trip_to_documents_collected_and_the_manifest_exports(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops, 'domestic');
        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 'o')->assertOk();
        $slots = $this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->json('slots');
        foreach (collect($slots)->whereIn('doc_type', ['id_proof', 'terms']) as $s) {
            $this->spa('o')->post("/api/trip-documents/{$s['id']}/files", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        }
        $this->assertSame('confirmed', $deal->fresh()->trip_status);
        $this->assertTrue($this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 'o')->json('summary.complete'));
        foreach (collect($slots)->whereIn('doc_type', ['id_proof', 'terms']) as $s) {
            $this->api('PATCH', "/api/trip-documents/{$s['id']}", ['status' => 'verified'], 'o')->assertOk();
        }
        $this->assertSame('docs_collected', $deal->fresh()->trip_status);

        $csv = $this->spa('o')->get("/api/deals/{$deal->id}/trip/manifest")->assertOk();
        $this->assertStringContainsString('Ravi Rao', $csv->getContent());
        $this->assertStringContainsString('N1234567', $csv->getContent());                                               // operations may see passport numbers
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
    }

    public function test_sales_can_upload_but_not_open_or_verify_and_passport_numbers_stay_masked(): void
    {
        $ops = $this->as('operations', 'o');
        $sales = $this->as('sales', 's');
        [$deal, $t] = $this->trip($sales);
        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 's')->assertOk();
        $r = $this->api('GET', "/api/deals/{$deal->id}/trip/documents", [], 's')->assertOk();
        $this->assertStringStartsWith('•', $r->json('travellers.0.passport_no'));
        $this->assertStringEndsWith('567', $r->json('travellers.0.passport_no'));
        $slot = collect($r->json('slots'))->firstWhere('doc_type', 'passport');
        $up = $this->spa('s')->post("/api/trip-documents/{$slot['id']}/files", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $fid = collect($up->json('slots'))->firstWhere('id', $slot['id'])['files'][0]['id'];

        $this->spa('s')->get("/api/trip-document-files/$fid/download")->assertStatus(403);
        $this->api('PATCH', "/api/trip-documents/{$slot['id']}", ['status' => 'verified'], 's')->assertStatus(403);
        $this->api('DELETE', "/api/trip-document-files/$fid", [], 's')->assertStatus(403);
        $this->api('GET', "/api/deals/{$deal->id}/trip/manifest", [], 's')->assertStatus(403);
        $this->api('POST', "/api/deals/{$deal->id}/trip/travellers", ['name' => 'New', 'passport_no' => 'Z1'], 's')->assertStatus(403);     // passport details: operations
        $this->api('POST', "/api/deals/{$deal->id}/trip/travellers", ['name' => 'New Traveller', 'relation' => 'Friend'], 's')->assertOk();
        // a deal the user cannot see is not found
        $other = $this->trip($ops)[0];
        $this->api('GET', "/api/deals/{$other->id}/trip/documents", [], 's')->assertStatus(404);
        $this->spa('o')->get("/api/trip-document-files/$fid/download")->assertOk();
    }

    public function test_saving_the_customer_keeps_traveller_ids_so_documents_stay_attached(): void
    {
        $ops = $this->as('operations', 'o');
        [$deal, $t] = $this->trip($ops, 'domestic');
        $this->api('PUT', "/api/deals/{$deal->id}/trip/travellers", ['traveller_ids' => [(string) $t[0]->id]], 'o')->assertOk();
        $c = $this->api('GET', "/api/customers/{$deal->customer_id}", [], 'o')->assertOk()->json();
        $rows = collect($c['travellers'])->map(fn ($x) => $x)->all();
        $rows[0]['name'] = 'Ravi K Rao';
        $this->api('PATCH', "/api/customers/{$deal->customer_id}", ['travellers' => $rows], 'o')->assertOk();
        $this->assertSame('Ravi K Rao', $t[0]->fresh()->name);
        $this->assertSame([(int) $t[0]->id], DB::table('trip_travellers')->where('deal_id', $deal->id)->pluck('traveller_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('N1234567', $t[0]->fresh()->passport_no);

        $this->api('PATCH', "/api/customers/{$deal->customer_id}", ['travellers' => array_slice($rows, 1)], 'o')->assertOk();     // removing a traveller removes them from the trip too
        $this->assertSame(0, DB::table('trip_travellers')->where('deal_id', $deal->id)->count());
    }
}
