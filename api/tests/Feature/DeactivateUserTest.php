<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Allocation;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeactivateUserTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function lead(string $id, User $owner, string $status = 'New Lead', array $over = []): Lead
    {
        return Lead::create($over + ['lead_id' => $id, 'customer_name' => "Cust $id", 'phone' => '9'.random_int(100000000, 999999999), 'destination' => 'Goa',
            'staff_id' => $owner->id, 'staff_name' => $owner->name, 'lead_status' => $status]);
    }

    public function test_deactivating_someone_who_left_hands_over_their_open_work_and_blocks_them(): void
    {
        $admin = $this->as(User::factory()->admin()->create());
        $leaver = User::factory()->sales()->create(['name' => 'Leaver']);
        $heir = User::factory()->sales()->create(['name' => 'Heir', 'consultant_code' => 'HE']);

        $this->lead('IRDA00001', $leaver);
        $this->lead('IRDA00002', $leaver, 'In Progress');
        $this->lead('IRDA00003', $leaver, 'Booked');                                  // a booked trip stays with its owner
        $this->lead('IRDA00004', $leaver, 'New Lead', ['archived_at' => now()]);       // so does the archive
        Activity::create(['type' => 'follow_up', 'title' => 'Call', 'priority' => 'medium', 'assigned_to' => $leaver->id, 'due_at' => now()]);
        Activity::create(['type' => 'task', 'title' => 'Done one', 'priority' => 'medium', 'assigned_to' => $leaver->id, 'completed_at' => now()]);
        Allocation::create(['destination' => 'Goa', 'consultant_id' => $leaver->id]);

        $w = $this->getJson("/api/users/{$leaver->id}/workload")->assertOk()->json();
        $this->assertSame([2, 1, 1], [$w['leads'], $w['activities'], $w['allocations']]);

        // refuses a bad heir, yourself, and a missing person
        $this->postJson("/api/users/{$leaver->id}/deactivate", ['reassign_to' => (string) $leaver->id])->assertStatus(422);
        $this->postJson("/api/users/{$admin->id}/deactivate", [])->assertStatus(400);
        $this->postJson('/api/users/999999/deactivate', [])->assertStatus(404);

        $r = $this->postJson("/api/users/{$leaver->id}/deactivate", ['reassign_to' => (string) $heir->id, 'reason' => 'Left the company'])->assertOk()->json();
        $this->assertSame(2, $r['moved']['leads']);
        $this->assertSame(1, $r['moved']['activities']);

        $leaver->refresh();
        $this->assertFalse((bool) $leaver->active);
        $this->assertSame('inactive', $leaver->account_status);
        $this->assertSame([(int) $heir->id, (int) $heir->id], Lead::whereIn('lead_id', ['IRDA00001', 'IRDA00002'])->orderBy('lead_id')->pluck('staff_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('HE', Lead::where('lead_id', 'IRDA00001')->value('staff_code'));
        $this->assertSame((int) $leaver->id, (int) Lead::where('lead_id', 'IRDA00003')->value('staff_id'));       // booked: untouched
        $this->assertSame((int) $leaver->id, (int) Lead::where('lead_id', 'IRDA00004')->value('staff_id'));       // archived: untouched
        $this->assertSame((int) $heir->id, (int) Activity::where('title', 'Call')->value('assigned_to'));
        $this->assertSame((int) $leaver->id, (int) Activity::where('title', 'Done one')->value('assigned_to'));   // finished work keeps its history
        $this->assertSame(0, Allocation::count());                                                          // no new leads for someone who left
        $this->assertTrue(\App\Models\ActivityLog::where('action', 'deactivate_user')->exists());

        // they no longer appear for chat or as people to assign to
        $this->as($admin);
        $this->assertNotContains((string) $leaver->id, array_column($this->getJson('/api/chat/users')->json(), 'id'));

        // and can come back
        $this->postJson("/api/users/{$leaver->id}/reactivate")->assertOk();
        $this->assertTrue((bool) $leaver->fresh()->active);
        $this->assertSame('active', $leaver->fresh()->account_status);
    }

    public function test_without_a_heir_the_work_stays_and_the_status_menu_also_switches_the_person_off(): void
    {
        $this->as(User::factory()->admin()->create());
        $leaver = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $this->lead('IRDA00010', $leaver);

        $r = $this->postJson("/api/users/{$leaver->id}/deactivate", [])->assertOk()->json();
        $this->assertSame(1, $r['left_with_them']['leads']);                                              // told what is still with them
        $this->assertSame((int) $leaver->id, (int) Lead::where('lead_id', 'IRDA00010')->value('staff_id'));

        $this->postJson("/api/users/{$other->id}/status", ['account_status' => 'inactive'])->assertOk();    // the old dropdown now does the same switch
        $this->assertFalse((bool) $other->fresh()->active);
        $this->postJson("/api/users/{$other->id}/status", ['account_status' => 'active'])->assertOk();
        $this->assertTrue((bool) $other->fresh()->active);
    }

    public function test_the_last_admin_and_super_admins_are_protected_and_new_leads_skip_people_who_left(): void
    {
        $admin = $this->as(User::factory()->admin()->create());
        $sa = User::factory()->create(['role' => 'super_admin']);
        $this->postJson("/api/users/{$sa->id}/deactivate", [])->assertStatus(404);                         // an admin cannot even see a Super Admin

        $this->as($sa);
        $other = User::factory()->admin()->create();
        $this->postJson("/api/users/{$admin->id}/deactivate", [])->assertOk();                              // now another admin exists

        $left = User::factory()->sales()->create(['active' => false, 'account_status' => 'inactive']);
        Allocation::create(['destination' => 'Goa', 'consultant_id' => $left->id]);
        $this->as($other);
        $id = $this->postJson('/api/leads', ['customer_name' => 'Asha', 'phone' => '9000000077', 'destination' => 'Goa'])->assertOk()->json('lead_id');
        $this->assertSame((int) $other->id, (int) Lead::where('lead_id', $id)->value('staff_id'));                // not the person who left
    }
}
