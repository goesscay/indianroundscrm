<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadsDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function lead(string $id, string $createdIst, string $travel = ''): void
    {
        Lead::create(['lead_id' => $id, 'customer_name' => "Cust $id", 'phone' => '9'.random_int(100000000, 999999999), 'destination' => 'Goa', 'travel_date' => $travel]);
        DB::table('leads')->where('lead_id', $id)->update(['created_at' => \Illuminate\Support\Carbon::parse($createdIst, 'Asia/Kolkata')->utc()]);
    }

    private function ids(string $qs): array
    {
        return collect($this->getJson('/api/leads?'.$qs)->assertOk()->json('items') ?? $this->getJson('/api/leads?'.$qs)->json())->pluck('lead_id')->sort()->values()->all();
    }

    public function test_leads_can_be_filtered_by_created_or_travel_date_range(): void
    {
        $this->lead('IRXX10001', '2026-10-01 00:30', '2026-12-05');   // just after midnight IST on the 1st (still the 30th in UTC)
        $this->lead('IRXX10002', '2026-10-05 23:45', '2026-12-20');   // just before midnight IST on the 5th (still the 5th in UTC)
        $this->lead('IRXX10003', '2026-10-06 10:00', '2027-01-10');
        $this->lead('IRXX10004', '2026-10-09 10:00');                 // no travel date yet
        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');

        // created: calendar days in India, both ends inclusive
        $this->assertSame(['IRXX10001', 'IRXX10002'], $this->ids('date_field=created&date_from=2026-10-01&date_to=2026-10-05'));
        $this->assertSame(['IRXX10002', 'IRXX10003', 'IRXX10004'], $this->ids('date_field=created&date_from=2026-10-05'));
        $this->assertSame(['IRXX10001', 'IRXX10002'], $this->ids('date_to=2026-10-05'));                       // created is the default
        $this->assertSame(['IRXX10002'], $this->ids('date_from=2026-10-05&date_to=2026-10-05'));
        $this->assertSame(['IRXX10004'], $this->ids('date_from=2026-10-09&date_to=2026-10-09'));

        // travel date
        $this->assertSame(['IRXX10001', 'IRXX10002'], $this->ids('date_field=travel&date_from=2026-12-01&date_to=2026-12-31'));
        $this->assertSame(['IRXX10003'], $this->ids('date_field=travel&date_from=2027-01-01'));
        $this->assertSame(['IRXX10001', 'IRXX10002'], $this->ids('date_field=travel&date_to=2026-12-31'));   // leads without a travel date never match a range

        // junk dates are ignored, and the filter combines with the others
        $this->assertCount(4, $this->ids('date_from=not-a-date&date_to=2026-13-45'));
        $this->assertSame(['IRXX10003'], $this->ids('date_from=2026-10-06&date_to=2026-10-08&status='.rawurlencode((string) Lead::where('lead_id', 'IRXX10003')->value('lead_status'))));
    }

    public function test_the_date_filter_never_widens_what_a_sales_user_can_see(): void
    {
        $sales = User::factory()->sales()->create();
        $this->lead('IRXX10011', '2026-10-06 10:00');
        $this->lead('IRXX10012', '2026-10-06 11:00');
        DB::table('leads')->where('lead_id', 'IRXX10011')->update(['staff_id' => $sales->id]);
        Sanctum::actingAs($sales, ['*'], 'sanctum');
        $this->assertSame(['IRXX10011'], $this->ids('date_from=2026-10-01&date_to=2026-10-31'));
    }
}
