<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class ActivitiesApiTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

    private function as(string $role, string $browser, array $attrs = []): User
    {
        $u = User::factory()->create(['role' => $role] + $attrs);
        $this->loginAs($u, $browser);

        return $u;
    }

    private function lead(string $phone, ?User $owner = null, string $name = 'Cust'): string
    {
        $this->loginAs(User::factory()->admin()->create(['consultant_code' => 'AD']), 'seed');
        $id = $this->api('POST', '/api/leads', ['customer_name' => $name, 'phone' => $phone, 'destination' => 'Goa'], 'seed')
            ->assertOk()->json('lead_id');
        if ($owner) {
            Lead::where('lead_id', $id)->update(['staff_id' => $owner->id]);
        }

        return $id;
    }

    private function mk(array $attrs = []): Activity
    {
        return Activity::create($attrs + ['type' => 'task', 'title' => 'T', 'priority' => 'medium']);
    }

    public function test_crud_and_done_toggle(): void
    {
        $sales = $this->as('sales', 's');
        $res = $this->api('POST', '/api/activities', [
            'title' => 'Call customer', 'type' => 'call', 'details' => 'secret details',
            'due_at' => '2026-12-01T10:00:00+05:30', 'priority' => 'high',
        ], 's')->assertOk();
        $this->assertSame('call', $res->json('type'));
        $this->assertSame('high', $res->json('priority'));
        $this->assertSame('2026-12-01T04:30:00+00:00', $res->json('due_at'));
        $this->assertNull($res->json('assigned_to'));
        $this->assertSame(['id' => (string) $sales->id, 'name' => $sales->name], $res->json('created_by'));
        $this->assertFalse($res->json('done'));
        $id = $res->json('id');
        $this->assertIsString($id);

        $this->api('GET', "/api/activities/$id", [], 's')->assertOk()->assertJsonPath('title', 'Call customer');

        $r = $this->api('PATCH', "/api/activities/$id", ['title' => 'Call back', 'done' => true], 's')->assertOk();
        $this->assertTrue($r->json('done'));
        $this->assertNotNull($r->json('completed_at'));
        $this->assertSame((string) $sales->id, $r->json('completed_by.id'));

        $r = $this->api('PATCH', "/api/activities/$id", ['done' => false], 's')->assertOk();
        $this->assertFalse($r->json('done'));
        $this->assertNull($r->json('completed_at'));
        $this->assertNull($r->json('completed_by'));

        $this->api('DELETE', "/api/activities/$id", [], 's')->assertOk()->assertExactJson(['ok' => true]);
        $this->api('GET', "/api/activities/$id", [], 's')->assertStatus(404)->assertJsonStructure(['detail']);

        // audit trail, never with the details text
        $actions = ActivityLog::where('entity', 'activity')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['create_activity', 'complete_activity', 'update_activity', 'update_activity', 'delete_activity'], $actions);
        $this->assertStringNotContainsString('secret details', json_encode(ActivityLog::where('entity', 'activity')->get()->toArray()));
    }

    public function test_operations_can_mark_done_the_review_activities_the_system_opens_but_not_other_peoples(): void
    {
        $ops = $this->as('operations', 'o');
        $other = User::factory()->sales()->create();
        // opened by the system for a supplier / customer reply: nobody assigned, nobody created it
        $review = $this->mk(['type' => 'email', 'title' => 'Review supplier reply — IRMH10023', 'due_at' => now()]);
        $reply = $this->mk(['type' => 'email', 'title' => 'Customer replied — Asha', 'due_at' => now()]);
        $theirs = $this->mk(['type' => 'email', 'title' => 'Customer replied — Ben', 'due_at' => now(), 'assigned_to' => $other->id]);

        $this->api('PATCH', "/api/activities/{$review->id}", ['done' => true], 'o')->assertOk()->assertJsonPath('done', true)->assertJsonPath('completed_by.id', (string) $ops->id);
        $this->api('PATCH', "/api/activities/{$reply->id}", ['done' => true], 'o')->assertOk();
        $this->api('PATCH', "/api/activities/{$review->id}", ['done' => false], 'o')->assertOk()->assertJsonPath('done', false);          // and back
        $this->api('PATCH', "/api/activities/{$theirs->id}", ['done' => true], 'o')->assertStatus(403);                                    // someone else's stays theirs
        $this->assertNull($theirs->fresh()->completed_at);
    }

    public function test_validation(): void
    {
        $this->as('sales', 's');
        $this->api('POST', '/api/activities', [], 's')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => str_repeat('x', 201)], 's')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => 'a', 'type' => 'bogus'], 's')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => 'a', 'priority' => 'urgent'], 's')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => 'a', 'due_at' => 'not a date'], 's')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => 'a', 'lead_id' => 'NOPE'], 's')->assertStatus(422);
        $r = $this->api('POST', '/api/activities', ['title' => 'a'], 's')->assertOk();
        $this->assertSame(['task', 'medium'], [$r->json('type'), $r->json('priority')]);
    }

    public function test_tab_partitioning_and_counts(): void
    {
        $sales = $this->as('sales', 's');
        Carbon::setTestNow('2026-06-10 12:00:00');
        $mk = fn ($title, $due, $extra = []) => $this->mk($extra + ['title' => $title, 'due_at' => $due, 'created_by' => $sales->id]);
        $mk('overdue-yesterday', '2026-06-09 10:00:00');
        $mk('overdue-today-earlier', '2026-06-10 08:00:00');
        $mk('today-later', '2026-06-10 18:00:00');
        $mk('today-end', '2026-06-10 23:59:59');
        $mk('tomorrow', '2026-06-11 00:00:00');
        $mk('nodate', null);
        $mk('done-one', '2026-06-01 00:00:00', ['completed_at' => '2026-06-02 00:00:00']);
        $mk('done-nodate', null, ['completed_at' => '2026-06-02 00:00:00']);
        $mk('mine-other-owner', '2026-06-20 00:00:00', ['created_by' => User::factory()->create()->id]); // not visible

        $qs = 'page=1&day_start='.urlencode('2026-06-10T00:00:00Z').'&day_end='.urlencode('2026-06-10T23:59:59Z');
        $titles = fn ($tab) => collect($this->api('GET', "/api/activities?$qs&tab=$tab", [], 's')->assertOk()->json('items'))->pluck('title')->all();

        $this->assertSame(['overdue-yesterday', 'overdue-today-earlier'], $titles('overdue'));
        $this->assertSame(['today-later', 'today-end'], $titles('today'));
        $this->assertSame(['tomorrow'], $titles('upcoming'));
        $this->assertSame(['nodate'], $titles('no_date'));
        $this->assertCount(2, $titles('done'));
        $this->assertCount(6, $titles('open'));
        $this->assertCount(6, $this->api('GET', "/api/activities?$qs", [], 's')->json('items')); // default tab = open
        $this->assertCount(8, $titles('all'));

        // open = overdue + today + upcoming + no_date (partition)
        $c = $this->api('GET', "/api/activities?$qs&tab=done", [], 's')->json('counts');
        $this->assertSame(['open' => 6, 'overdue' => 2, 'today' => 2, 'upcoming' => 1, 'no_date' => 1, 'done' => 2], $c);

        // counts ignore the tab but honour q
        $c = $this->api('GET', "/api/activities?$qs&q=today", [], 's')->json('counts');
        $this->assertSame(['open' => 3, 'overdue' => 1, 'today' => 2, 'upcoming' => 0, 'no_date' => 0, 'done' => 0], $c);

        // overdue flag
        $items = $this->api('GET', "/api/activities?$qs&tab=all", [], 's')->json('items');
        $flags = collect($items)->pluck('overdue', 'title');
        $this->assertTrue($flags['overdue-yesterday']);
        $this->assertFalse($flags['today-later']);
        $this->assertFalse($flags['done-one']);

        Carbon::setTestNow();
    }

    public function test_sorting_and_nulls_last(): void
    {
        $u = $this->as('sales', 's');
        $this->mk(['title' => 'b', 'due_at' => '2030-01-02 00:00:00', 'created_by' => $u->id]);
        $this->mk(['title' => 'a', 'due_at' => '2030-01-03 00:00:00', 'created_by' => $u->id]);
        $this->mk(['title' => 'c', 'due_at' => null, 'created_by' => $u->id]);
        $t = fn ($qs) => collect($this->api('GET', "/api/activities?$qs", [], 's')->json())->pluck('title')->all();
        $this->assertSame(['b', 'a', 'c'], $t('tab=all'));
        $this->assertSame(['a', 'b', 'c'], $t('tab=all&dir=desc'));
        $this->assertSame(['a', 'b', 'c'], $t('tab=all&sort=title'));
        $this->assertSame(['c', 'b', 'a'], $t('tab=all&sort=title&dir=desc'));
    }

    public function test_scoping_sales_operations_admin_and_owner_filter(): void
    {
        $s1 = $this->as('sales', 's1');
        $s2 = $this->as('sales', 's2');
        $ops = $this->as('operations', 'ops');
        $adm = $this->as('admin', 'adm');
        $lead = $this->lead('9876543210', $s1);

        $this->mk(['title' => 'created by s1', 'created_by' => $s1->id]);
        $this->mk(['title' => 'assigned to s1', 'assigned_to' => $s1->id, 'created_by' => $adm->id]);
        $this->mk(['title' => 'on s1 lead', 'lead_id' => $lead, 'created_by' => $adm->id]);
        $this->mk(['title' => 'unrelated', 'created_by' => $adm->id]);
        $this->mk(['title' => 'created by s2', 'created_by' => $s2->id, 'assigned_to' => $s2->id]);

        $titles = fn ($b, $qs = '') => collect($this->api('GET', "/api/activities?page=1&tab=all$qs", [], $b)->assertOk()->json('items'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['assigned to s1', 'created by s1', 'on s1 lead'], $titles('s1'));
        $this->assertSame(['created by s2'], $titles('s2'));
        $this->assertCount(5, $titles('ops'));
        $this->assertCount(5, $titles('adm'));

        // owner filter
        $this->assertSame(['assigned to s1'], $titles('s1', '&owner=me'));
        $this->assertSame(['assigned to s1'], $titles('adm', '&owner='.$s1->id));
        $this->assertSame(['created by s2'], $titles('ops', '&owner='.$s2->id));
        $this->assertSame(['created by s1', 'on s1 lead'], $titles('s1', '&owner=unassigned')); // within scope
        $this->assertCount(3, $titles('adm', '&owner=unassigned'));
        $this->assertSame(['created by s2'], $titles('s2', '&owner=all'));

        // detail visibility
        $hidden = Activity::where('title', 'unrelated')->value('id');
        $this->api('GET', "/api/activities/$hidden", [], 's1')->assertStatus(404);
        $this->api('PATCH', "/api/activities/$hidden", ['title' => 'x'], 's1')->assertStatus(404);
        $this->api('GET', "/api/activities/$hidden", [], 'ops')->assertOk();
    }

    public function test_search_filters(): void
    {
        $u = $this->as('operations', 'ops');
        $lead = $this->lead('9876543210', null, 'Zebediah Quux');
        $this->mk(['title' => 'Plain', 'details' => 'needle in haystack']);
        $this->mk(['title' => 'Linked', 'lead_id' => $lead, 'type' => 'meeting']);
        $this->mk(['title' => 'Other', 'type' => 'email']);
        $n = fn ($qs) => $this->api('GET', "/api/activities?page=1&tab=all&$qs", [], 'ops')->json('total');
        $this->assertSame(1, $n('q=NEEDLE'));
        $this->assertSame(1, $n('q=zebediah'));
        $this->assertSame(1, $n('q='.$lead));
        $this->assertSame(1, $n('type=meeting'));
        $this->assertSame(1, $n('lead_id='.$lead));
        $this->assertSame(3, $n('type='));
    }

    public function test_assignment_rules(): void
    {
        $s = $this->as('sales', 's');
        $other = User::factory()->create();
        $inactive = User::factory()->create(['active' => false, 'account_status' => 'inactive']);

        $this->api('POST', '/api/activities', ['title' => 'a', 'assigned_to' => (string) $other->id], 's')->assertStatus(403);
        $r = $this->api('POST', '/api/activities', ['title' => 'a', 'assigned_to' => (string) $s->id], 's')->assertOk();
        $this->assertSame((string) $s->id, $r->json('assigned_to.id'));
        $this->api('PATCH', '/api/activities/'.$r->json('id'), ['assigned_to' => $other->id], 's')->assertStatus(403);
        $this->api('PATCH', '/api/activities/'.$r->json('id'), ['assigned_to' => null], 's')->assertOk()->assertJsonPath('assigned_to', null);

        // options: self only for non-managers
        $opt = $this->api('GET', '/api/activities/options', [], 's')->assertOk()->json();
        $this->assertSame([(string) $s->id], array_column($opt['users'], 'id'));
        $this->assertSame(['task', 'call', 'meeting', 'email', 'follow_up'], $opt['types']);
        $this->assertSame(['low', 'medium', 'high'], $opt['priorities']);

        // admin (manage) can assign anyone active, not inactive users
        $this->as('admin', 'adm');
        $this->api('POST', '/api/activities', ['title' => 'b', 'assigned_to' => (string) $other->id], 'adm')->assertOk()->assertJsonPath('assigned_to.id', (string) $other->id);
        $this->api('POST', '/api/activities', ['title' => 'b', 'assigned_to' => (string) $inactive->id], 'adm')->assertStatus(422);
        $this->api('POST', '/api/activities', ['title' => 'b', 'assigned_to' => '99999'], 'adm')->assertStatus(422);
        $ids = array_column($this->api('GET', '/api/activities/options', [], 'adm')->json('users'), 'id');
        $this->assertContains((string) $other->id, $ids);
        $this->assertNotContains((string) $inactive->id, $ids);
    }

    public function test_modify_authorization(): void
    {
        $s1 = $this->as('sales', 's1');
        $s2 = $this->as('sales', 's2');
        $ops = $this->as('operations', 'ops'); // view_all but no manage
        $adm = $this->as('admin', 'adm');

        $a = $this->mk(['title' => 'mine', 'created_by' => $s1->id]);
        $b = $this->mk(['title' => 'assigned to s2', 'created_by' => $adm->id, 'assigned_to' => $s2->id]);

        // visible-but-not-modifiable -> 403
        $this->api('PATCH', "/api/activities/{$a->id}", ['title' => 'x'], 'ops')->assertStatus(403);
        $this->api('DELETE', "/api/activities/{$a->id}", [], 'ops')->assertStatus(403);
        // creator / assignee / manager can
        $this->api('PATCH', "/api/activities/{$a->id}", ['title' => 'x'], 's1')->assertOk();
        $this->api('PATCH', "/api/activities/{$b->id}", ['done' => true], 's2')->assertOk();
        $this->api('DELETE', "/api/activities/{$b->id}", [], 's2')->assertOk();
        $this->api('DELETE', "/api/activities/{$a->id}", [], 'adm')->assertOk();
    }

    public function test_lead_linkage_visibility(): void
    {
        $s1 = $this->as('sales', 's1');
        $s2 = $this->as('sales', 's2');
        $mine = $this->lead('9876543210', $s1);
        $theirs = $this->lead('9876543211', $s2);

        $r = $this->api('POST', '/api/activities', ['title' => 'link', 'lead_id' => $mine], 's1')->assertOk();
        $this->assertSame($mine, $r->json('lead.lead_id'));
        $this->assertSame('Cust', $r->json('lead.customer_name'));
        $this->api('POST', '/api/activities', ['title' => 'link', 'lead_id' => $theirs], 's1')->assertStatus(422);
        $this->api('PATCH', '/api/activities/'.$r->json('id'), ['lead_id' => $theirs], 's1')->assertStatus(422);
        $this->api('PATCH', '/api/activities/'.$r->json('id'), ['lead_id' => null], 's1')->assertOk()->assertJsonPath('lead', null);

        // GET /leads/{id}/activities
        $this->mk(['title' => 'done old', 'lead_id' => $mine, 'due_at' => '2030-01-01', 'completed_at' => '2030-01-02']);
        $this->mk(['title' => 'open soon', 'lead_id' => $mine, 'due_at' => '2030-02-01']);
        $this->mk(['title' => 'open later', 'lead_id' => $mine, 'due_at' => '2030-03-01']);
        $this->mk(['title' => 'by other user', 'lead_id' => $mine, 'created_by' => $s2->id]);
        $list = $this->api('GET', "/api/leads/$mine/activities", [], 's1')->assertOk()->json();
        $this->assertSame(['open later', 'open soon', 'by other user', 'done old'], array_column($list, 'title'));
        $this->api('GET', "/api/leads/$theirs/activities", [], 's1')->assertStatus(403);
        $this->api('GET', '/api/leads/NOPE/activities', [], 's1')->assertStatus(404);

        // deleting the lead keeps the activity (set null)
        DB::table('leads')->where('lead_id', $mine)->delete();
        $this->assertNull(Activity::where('title', 'open soon')->first()->lead_id);
    }

    public function test_pagination_shape_and_bare_array(): void
    {
        $u = $this->as('sales', 's');
        for ($i = 1; $i <= 7; $i++) {
            $this->mk(['title' => "n$i", 'created_by' => $u->id, 'due_at' => "2030-01-0$i 00:00:00"]);
        }
        $r = $this->api('GET', '/api/activities?page=2&per_page=3', [], 's')->assertOk();
        $this->assertSame([7, 2, 3, 3], [$r->json('total'), $r->json('page'), $r->json('per_page'), $r->json('pages')]);
        $this->assertSame(['n4', 'n5', 'n6'], array_column($r->json('items'), 'title'));
        $this->assertArrayHasKey('counts', $r->json());
        $this->assertSame(['open', 'overdue', 'today', 'upcoming', 'no_date', 'done'], array_keys($r->json('counts')));

        $bare = $this->api('GET', '/api/activities', [], 's')->assertOk()->json();
        $this->assertCount(7, $bare);
        $this->assertArrayHasKey(0, $bare);
        $this->assertCount(2, $this->api('GET', '/api/activities?limit=2', [], 's')->json());
    }

    public function test_calendar_range(): void
    {
        $u = $this->as('sales', 's');
        $this->mk(['title' => 'before', 'created_by' => $u->id, 'due_at' => '2030-05-31 23:59:59']);
        $this->mk(['title' => 'start', 'created_by' => $u->id, 'due_at' => '2030-06-01 00:00:00']);
        $this->mk(['title' => 'done in range', 'created_by' => $u->id, 'due_at' => '2030-06-15 00:00:00', 'completed_at' => '2030-06-01']);
        $this->mk(['title' => 'end excluded', 'created_by' => $u->id, 'due_at' => '2030-07-01 00:00:00']);
        $this->mk(['title' => 'nodate', 'created_by' => $u->id]);
        $this->mk(['title' => 'hidden', 'created_by' => User::factory()->create()->id, 'due_at' => '2030-06-10 00:00:00']);

        $r = $this->api('GET', '/api/activities/calendar?from=2030-06-01T00:00:00Z&to=2030-07-01T00:00:00Z', [], 's')->assertOk()->json();
        $this->assertSame(['start', 'done in range'], array_column($r, 'title'));
        $this->api('GET', '/api/activities/calendar?from=junk', [], 's')->assertStatus(422);
        $this->assertCount(1, $this->api('GET', '/api/activities/calendar?from=2030-06-01T00:00:00Z&to=2030-07-01T00:00:00Z&q=start', [], 's')->json());
    }

    public function test_permission_denial_with_custom_role(): void
    {
        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer', 'is_system' => false]);
        $u = $this->as('viewer', 'v');
        foreach ([['GET', '/api/activities'], ['GET', '/api/activities/calendar'], ['GET', '/api/activities/options'],
            ['GET', '/api/activities/1'], ['POST', '/api/activities'], ['PATCH', '/api/activities/1'], ['DELETE', '/api/activities/1'],
            ['GET', '/api/leads/X/activities']] as [$m, $url]) {
            $this->api($m, $url, $m === 'POST' ? ['title' => 'a'] : [], 'v')->assertStatus(403)->assertJsonStructure(['detail']);
        }

        // view without create: can read, cannot create
        DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission' => 'activities.view']);
        \App\Support\Permissions::flush();
        $this->api('GET', '/api/activities', [], 'v')->assertOk();
        $this->api('POST', '/api/activities', ['title' => 'a'], 'v')->assertStatus(403);
        $this->assertNotNull($u);
    }

    public function test_default_permissions_and_me_payload(): void
    {
        $this->as('sales', 's');
        $this->as('operations', 'ops');
        $p = fn ($b) => $this->api('GET', '/api/auth/me', [], $b)->json('permissions');
        $this->assertEqualsCanonicalizing(['activities.view', 'activities.create'], array_values(array_filter($p('s'), fn ($k) => str_starts_with($k, 'activities.'))));
        $this->assertEqualsCanonicalizing(['activities.view', 'activities.view_all', 'activities.create'], array_values(array_filter($p('ops'), fn ($k) => str_starts_with($k, 'activities.'))));
    }

    public function test_data_migration_grants_defaults_to_existing_system_roles_only(): void
    {
        $custom = Role::create(['name' => 'Custom', 'slug' => 'custom', 'is_system' => false]);
        DB::table('role_permissions')->insert(['role_id' => $custom->id, 'permission' => 'chat.use']);
        // simulate production: system roles seeded before the activities permissions existed
        DB::table('role_permissions')->where('permission', 'like', 'activities.%')->delete();
        // an admin customisation must be preserved (sales already has view, lacks create)
        $salesId = Role::where('slug', 'sales')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $salesId, 'permission' => 'activities.view']);

        $migration = require database_path('migrations/2026_10_07_100100_add_activities_permissions_to_system_roles.php');
        $migration->up();
        $migration->up(); // idempotent

        $perms = fn ($slug) => DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.slug', $slug)->where('permission', 'like', 'activities.%')->pluck('permission')->sort()->values()->all();
        $this->assertSame(['activities.create', 'activities.view'], $perms('sales'));
        $this->assertSame(['activities.create', 'activities.view', 'activities.view_all'], $perms('operations'));
        $this->assertSame([], $perms('admin'));
        $this->assertSame([], $perms('custom'));
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $salesId)->where('permission', 'activities.view')->count());
    }
}
