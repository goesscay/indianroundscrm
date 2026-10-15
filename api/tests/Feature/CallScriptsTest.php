<?php

namespace Tests\Feature;

use App\Models\CallScript;
use App\Models\User;
use App\Support\CallScriptDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CallScriptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_example_scripts_cover_a_general_call_and_the_main_destinations_and_use_known_placeholders(): void
    {
        $all = CallScriptDefaults::all();
        $this->assertSame(count($all), CallScript::whereNull('user_id')->count());               // the migration put them in as the team defaults
        $this->assertNull($all[0]['destination']);                                              // one general script
        $this->assertGreaterThanOrEqual(8, count($all));
        $dests = array_filter(array_column($all, 'destination'));
        $this->assertSame(count($dests), count(array_unique(array_map('strtolower', $dests))));   // one per destination
        foreach ($all as $s) {
            $this->assertStringContainsString('OPENING', $s['body']);
            $this->assertStringContainsString('{customer}', $s['body']);
            $this->assertStringContainsString('{me}', $s['body']);
            preg_match_all('/\{(\w+)\}/', $s['body'], $m);
            $this->assertSame([], array_diff($m[1], ['customer', 'destination', 'me', 'company']), $s['title']);   // nothing the page cannot fill in
        }
    }

    public function test_a_salesperson_sees_the_team_defaults_and_only_their_own_scripts(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        CallScript::create(['user_id' => $a->id, 'destination' => 'Bali', 'title' => 'Mine', 'body' => 'my bali script']);
        CallScript::create(['user_id' => $b->id, 'destination' => 'Bali', 'title' => 'Theirs', 'body' => 'b bali script']);

        Sanctum::actingAs($a, ['*'], 'sanctum');
        $list = collect($this->getJson('/api/scripts')->assertOk()->json());
        $this->assertTrue($list->contains(fn ($s) => $s['scope'] === 'team' && $s['destination'] === 'Bali'));
        $this->assertTrue($list->contains(fn ($s) => $s['scope'] === 'mine' && $s['body'] === 'my bali script'));
        $this->assertFalse($list->contains(fn ($s) => $s['body'] === 'b bali script'));          // never someone else's
        $this->assertSame(count(CallScriptDefaults::all()) + 1, $list->count());
    }

    public function test_saving_makes_or_updates_their_own_version_and_leaves_the_team_default_alone(): void
    {
        $a = User::factory()->sales()->create();
        Sanctum::actingAs($a, ['*'], 'sanctum');
        $teamBody = CallScript::whereNull('user_id')->whereRaw("lower(destination) = 'goa'")->value('body');

        $first = $this->postJson('/api/scripts', ['destination' => ' goa ', 'body' => 'Hi {customer}, my own Goa pitch.'])->assertOk()->json();
        $this->assertSame('mine', $first['scope']);
        $this->assertSame('goa', $first['destination']);
        $second = $this->postJson('/api/scripts', ['destination' => 'GOA', 'title' => 'Goa v2', 'body' => 'Updated pitch.'])->assertOk()->json();
        $this->assertSame($first['id'], $second['id']);                                          // same destination: updated, not duplicated
        $this->assertSame(1, CallScript::where('user_id', $a->id)->count());
        $this->assertSame($teamBody, CallScript::whereNull('user_id')->whereRaw("lower(destination) = 'goa'")->value('body'));

        $this->postJson('/api/scripts', ['body' => 'A general one of mine.'])->assertOk();         // no destination: their general script
        $this->assertSame(2, CallScript::where('user_id', $a->id)->count());
        $this->postJson('/api/scripts', ['destination' => 'Goa', 'body' => ''])->assertStatus(422);

        // deleting their own brings back the team default
        $this->deleteJson('/api/scripts/'.$first['id'])->assertOk();
        $this->assertSame(1, CallScript::where('user_id', $a->id)->count());
    }

    public function test_only_script_managers_can_change_or_delete_the_team_defaults(): void
    {
        $sales = User::factory()->sales()->create();
        $teamId = CallScript::whereNull('user_id')->whereRaw("lower(destination) = 'bali'")->value('id');
        Sanctum::actingAs($sales, ['*'], 'sanctum');

        $this->postJson('/api/scripts', ['scope' => 'team', 'destination' => 'Bali', 'body' => 'hijacked'])->assertStatus(403);
        $this->deleteJson("/api/scripts/$teamId")->assertStatus(403);
        $this->assertStringNotContainsString('hijacked', (string) CallScript::find($teamId)->body);

        Sanctum::actingAs(User::factory()->admin()->create(), ['*'], 'sanctum');
        $this->postJson('/api/scripts', ['scope' => 'team', 'destination' => 'Bali', 'title' => 'Bali (new)', 'body' => 'New team Bali script for {customer}.'])->assertOk()->assertJsonPath('scope', 'team');
        $this->assertSame(1, CallScript::whereNull('user_id')->whereRaw("lower(destination) = 'bali'")->count());   // updated in place
        $this->assertSame('New team Bali script for {customer}.', CallScript::find($teamId)->body);
        $this->deleteJson("/api/scripts/$teamId")->assertOk();
        $this->assertNull(CallScript::find($teamId));
    }

    public function test_someone_who_cannot_work_leads_has_no_scripts(): void
    {
        $role = \App\Models\Role::create(['slug' => 'viewer', 'name' => 'Viewer', 'description' => '', 'is_system' => false]);
        $u = User::factory()->create(['role' => 'viewer']);
        Sanctum::actingAs($u, ['*'], 'sanctum');
        $this->getJson('/api/scripts')->assertStatus(403);
    }
}
