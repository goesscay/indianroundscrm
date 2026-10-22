<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\AmountWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class InvoicePdfDesignTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_amounts_are_written_out_the_indian_way(): void
    {
        $this->assertSame('Ninety Thousand Five Hundred Rupees Only', AmountWords::rupees(90500));
        $this->assertSame('One Lakh Twenty Three Thousand Four Hundred Fifty Six Rupees and Fifty Paise Only', AmountWords::rupees(123456.5));
        $this->assertSame('Two Crore Five Lakh Rupees Only', AmountWords::rupees(20500000));
        $this->assertSame('Zero Rupees Only', AmountWords::rupees(0));
        $this->assertSame('Twenty Five Thousand Two Hundred Rupees Only', AmountWords::rupees(25200));
        $this->assertSame('Nineteen Rupees and Five Paise Only', AmountWords::rupees(19.05));
    }

    public function test_the_invoice_pdf_carries_the_company_details_and_renders(): void
    {
        $u = User::factory()->create(['role' => 'operations']);
        $this->loginAs($u, 'o');
        $c = Customer::create(['customer_no' => 'IRC-PD1', 'name' => 'AKILANDESWARI B', 'email' => 'a@example.com', 'phone' => '9876543210', 'state' => 'Tamil Nadu', 'owner_id' => $u->id]);
        $d = $this->api('POST', '/api/invoices', ['customer_id' => (string) $c->id, 'title' => 'Manali 2N 3D', 'destination' => 'Manali', 'travel_date' => '2026-11-10', 'adults' => 10, 'children' => 5,
            'terms' => '1. All details as per itinerary', 'lines' => [
                ['kind' => 'package', 'description' => 'MANALI 2N 3D', 'qty' => 10, 'unit_price' => 7000, 'discount' => 0, 'tax_rate' => 0],
                ['kind' => 'package', 'description' => 'CHILD', 'qty' => 5, 'unit_price' => 4500, 'discount' => 2000, 'tax_rate' => 0],
            ], 'installments' => [['label' => 'Advance', 'due_date' => '2026-09-30', 'amount' => 20000], ['label' => 'Balance', 'due_date' => '2026-10-02', 'amount' => 70500]]], 'o')->assertOk()->json();
        $i = $this->api('POST', "/api/invoices/{$d['id']}/issue", [], 'o')->assertOk()->json();
        $this->api('POST', "/api/invoices/{$i['id']}/receipts", ['amount' => 5000, 'mode' => 'upi'], 'o')->assertOk();

        $res = $this->api('GET', "/api/invoices/{$i['id']}/pdf", [], 'o')->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));

        // the page the PDF is made from
        $html = view('pdf.invoice', ['inv' => \App\Models\Invoice::with(['customer', 'deal', 'lines', 'installments', 'receipts', 'creditNotes'])->find($i['id']), 'schedule' => [], 'previousBalance' => 0])->render();
        foreach (['BILL OF SUPPLY', '13/8 EKR Avenue', '33ARAPV6148H1ZN', '+91 8778955009', 'enquiry@indianrounds.in', 'AKILANDESWARI B', 'Ninety Thousand Five Hundred Rupees Only', 'Original for recipient', 'data:image/png;base64'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        if ($dir = getenv('INVOICE_PDF_PREVIEW_DIR')) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.'invoice-preview.pdf', $res->getContent());
        }
    }
}
