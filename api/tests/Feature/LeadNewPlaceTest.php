<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Master;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadNewPlaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_can_create_a_lead_with_a_destination_and_departure_city_that_are_not_in_the_lists(): void
    {
        Master::create(['category' => 'destination', 'value' => 'Goa']);
        Master::create(['category' => 'departure_city', 'value' => 'Chennai']);
        $sales = User::factory()->sales()->create();
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $this->postJson('/api/leads', [
            'customer_name' => 'Asha Rao', 'phone' => '9876501234', 'destination' => 'Phu Quoc', 'departure_from' => 'Tiruchirappalli',
        ])->assertStatus(200)->assertJsonPath('destination', 'Phu Quoc')->assertJsonPath('departure_from', 'Tiruchirappalli');
        $this->assertSame((int) $sales->id, (int) Lead::where('destination', 'Phu Quoc')->value('staff_id'));   // no allocation for it: the creator keeps the lead
    }

    public function test_places_already_used_on_leads_are_offered_to_everyone_once_without_repeating_the_master_list(): void
    {
        Master::create(['category' => 'destination', 'value' => 'Goa']);
        Master::create(['category' => 'departure_city', 'value' => 'Chennai']);
        foreach ([['IRNP00001', 'Phu Quoc', 'Trichy'], ['IRNP00002', 'phu quoc', 'trichy'], ['IRNP00003', 'GOA', 'chennai'], ['IRNP00004', 'Hoi An', '']] as [$id, $dest, $dep]) {
            Lead::create(['lead_id' => $id, 'customer_name' => "C $id", 'phone' => '9'.random_int(100000000, 999999999), 'destination' => $dest, 'departure_from' => $dep]);
        }
        Sanctum::actingAs(User::factory()->sales()->create(), ['*'], 'sanctum');

        $r = $this->getJson('/api/lead-options')->assertOk();
        $this->assertSame(['Goa'], $r->json('master.destination'));
        $used = $r->json('used');
        $this->assertSame(['Hoi An', 'Phu Quoc'], collect($used['destination'])->sort()->values()->all());   // typed variants collapse, master names are not repeated
        $this->assertSame(['Trichy'], $used['departure_city']);
    }
}
