<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function dm(User $from, User $to): string
    {
        $this->as($from);

        return $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $to->id])->assertOk()->json('id');
    }

    private function send(User $u, string $cid, string $body = 'hi')
    {
        $this->as($u);

        return $this->postJson("/api/chat/conversations/$cid/messages", ['body' => $body]);
    }

    public function test_users_list_excludes_me_and_inactive(): void
    {
        $me = User::factory()->sales()->create();
        $a = User::factory()->operations()->create();
        User::factory()->create(['active' => false]);
        $this->as($me);
        $r = $this->getJson('/api/chat/users')->assertOk();
        $this->assertSame([(string) $a->id], array_column($r->json(), 'id'));
        $this->assertSame(['id', 'name', 'role'], array_keys($r->json(0)));
    }

    public function test_direct_get_or_create_is_idempotent_and_named_after_other(): void
    {
        $a = User::factory()->sales()->create(['name' => 'Alice']);
        $b = User::factory()->sales()->create(['name' => 'Bob']);
        $id1 = $this->dm($a, $b);
        $id2 = $this->dm($a, $b);
        $id3 = $this->dm($b, $a);
        $this->assertSame($id1, $id2);
        $this->assertSame($id1, $id3);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseHas('chat_conversations', ['direct_key' => min($a->id, $b->id).':'.max($a->id, $b->id)]);

        $this->as($a);
        $r = $this->getJson('/api/chat/conversations')->assertOk();
        $this->assertSame('Bob', $r->json('0.name'));
        $this->assertSame('direct', $r->json('0.type'));
        $this->assertCount(2, $r->json('0.members'));
        $this->assertNull($r->json('0.last_message'));
        $this->assertSame(0, $r->json('0.unread_count'));
        $this->as($b);
        $this->assertSame('Alice', $this->getJson('/api/chat/conversations')->json('0.name'));
    }

    public function test_direct_rejects_self_inactive_and_missing(): void
    {
        $a = User::factory()->sales()->create();
        $inactive = User::factory()->create(['active' => false]);
        $this->as($a);
        $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $a->id])->assertStatus(400);
        $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $inactive->id])->assertStatus(404);
        $this->postJson('/api/chat/conversations/direct', ['user_id' => '999999'])->assertStatus(404);
        $this->postJson('/api/chat/conversations/direct', [])->assertStatus(422);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/chat/conversations')->assertStatus(401)->assertJsonPath('detail', 'Not authenticated');
    }

    public function test_group_create_is_admin_only_and_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $b = User::factory()->sales()->create();
        $c = User::factory()->sales()->create();

        $this->as($b);
        $this->postJson('/api/chat/groups', ['name' => 'Team', 'member_ids' => [(string) $c->id]])->assertStatus(403);

        $this->as($admin);
        $this->postJson('/api/chat/groups', ['name' => '', 'member_ids' => [(string) $b->id]])->assertStatus(422);
        $this->postJson('/api/chat/groups', ['name' => str_repeat('x', 81), 'member_ids' => [(string) $b->id]])->assertStatus(422);
        $this->postJson('/api/chat/groups', ['name' => 'Team', 'member_ids' => []])->assertStatus(422);
        $this->postJson('/api/chat/groups', ['name' => 'Team', 'member_ids' => [(string) $admin->id]])->assertStatus(422);
        $r = $this->postJson('/api/chat/groups', ['name' => 'Team', 'member_ids' => [(string) $b->id, (string) $c->id]])->assertOk();
        $r->assertJsonPath('type', 'group')->assertJsonPath('name', 'Team');
        $this->assertCount(3, $r->json('members'));
        $this->assertIsString($r->json('id'));
        $this->assertDatabaseHas('activity_log', ['entity' => 'chat_group', 'action' => 'create', 'entity_id' => $r->json('id')]);

        // members see it
        $this->as($b);
        $this->assertSame('Team', $this->getJson('/api/chat/conversations')->json('0.name'));
    }

    public function test_group_cannot_include_inactive_user(): void
    {
        $admin = User::factory()->admin()->create();
        $dead = User::factory()->create(['active' => false]);
        $b = User::factory()->sales()->create();
        $this->as($admin);
        $this->postJson('/api/chat/groups', ['name' => 'T', 'member_ids' => [(string) $dead->id]])->assertStatus(422);
        $gid = $this->postJson('/api/chat/groups', ['name' => 'T', 'member_ids' => [(string) $b->id]])->json('id');
        $this->patchJson("/api/chat/groups/$gid", ['add_member_ids' => [(string) $dead->id]])->assertStatus(422);
    }

    public function test_membership_isolation_and_no_admin_backdoor(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $c = User::factory()->sales()->create();

        $dm = $this->dm($a, $b);
        $this->send($a, $dm, 'secret')->assertOk();

        foreach ([$c, $admin] as $outsider) {
            $this->as($outsider);
            $this->getJson("/api/chat/conversations/$dm/messages")->assertStatus(404);
            $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'x'])->assertStatus(404);
            $this->postJson("/api/chat/conversations/$dm/read")->assertStatus(404);
            $this->assertSame([], $this->getJson('/api/chat/conversations')->json());
        }

        // group the admin is NOT in
        $other = User::factory()->admin()->create();
        $this->as($other);
        $gid = $this->postJson('/api/chat/groups', ['name' => 'G', 'member_ids' => [(string) $a->id]])->json('id');
        $this->send($a, $gid, 'group secret')->assertOk();
        $this->as($admin);
        $this->getJson("/api/chat/conversations/$gid/messages")->assertStatus(404);
        // the admin can still manage the group, but sees no messages through it
        $r = $this->patchJson("/api/chat/groups/$gid", ['name' => 'G2'])->assertOk();
        $this->assertNull($r->json('last_message'));
        // PATCH on a DM is rejected
        $this->patchJson("/api/chat/groups/$dm", ['name' => 'x'])->assertStatus(400);
    }

    public function test_send_validation_and_list_pagination(): void
    {
        $a = User::factory()->sales()->create(['name' => 'Alice']);
        $b = User::factory()->sales()->create();
        $dm = $this->dm($a, $b);

        $this->send($a, $dm, '   ')->assertStatus(422);
        $this->send($a, $dm, str_repeat('x', 4001))->assertStatus(422);
        $m = $this->send($a, $dm, '  hello  ')->assertOk();
        $m->assertJsonPath('body', 'hello')->assertJsonPath('sender_name', 'Alice')->assertJsonPath('deleted', false)
            ->assertJsonPath('conversation_id', $dm)->assertJsonPath('sender_id', (string) $a->id);
        $this->assertIsString($m->json('id'));

        $ids = [$m->json('id')];
        for ($i = 2; $i <= 7; $i++) {
            $ids[] = $this->send($i % 2 ? $a : $b, $dm, "m$i")->assertOk()->json('id');
        }

        $this->as($b);
        // latest N ascending
        $r = $this->getJson("/api/chat/conversations/$dm/messages?limit=3")->assertOk();
        $this->assertSame(array_slice($ids, 4, 3), array_column($r->json('messages'), 'id'));
        $this->assertTrue($r->json('has_more'));
        // older page
        $r = $this->getJson("/api/chat/conversations/$dm/messages?limit=3&before_id={$ids[4]}")->assertOk();
        $this->assertSame(array_slice($ids, 1, 3), array_column($r->json('messages'), 'id'));
        $this->assertTrue($r->json('has_more'));
        $r = $this->getJson("/api/chat/conversations/$dm/messages?limit=3&before_id={$ids[1]}")->assertOk();
        $this->assertSame([$ids[0]], array_column($r->json('messages'), 'id'));
        $this->assertFalse($r->json('has_more'));
        // polling
        $r = $this->getJson("/api/chat/conversations/$dm/messages?after_id={$ids[4]}")->assertOk();
        $this->assertSame([$ids[5], $ids[6]], array_column($r->json('messages'), 'id'));
        $this->assertFalse($r->json('has_more'));
        $r = $this->getJson("/api/chat/conversations/$dm/messages?after_id={$ids[0]}&limit=2")->assertOk();
        $this->assertSame([$ids[1], $ids[2]], array_column($r->json('messages'), 'id'));
        $this->assertTrue($r->json('has_more'));
        // limit capped / default
        $this->assertFalse($this->getJson("/api/chat/conversations/$dm/messages?limit=1000")->json('has_more'));
        $this->getJson("/api/chat/conversations/$dm/messages?after_id=abc")->assertStatus(400);

        // conversation listing shows last message
        $c = $this->getJson('/api/chat/conversations')->json('0');
        $this->assertSame($ids[6], $c['last_message']['id']);
        $this->assertSame('m7', $c['last_message']['body']);
    }

    public function test_unread_counts_and_mark_read_never_goes_backwards(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $dm = $this->dm($a, $b);
        $m1 = $this->send($a, $dm, '1')->json('id');
        $m2 = $this->send($a, $dm, '2')->json('id');
        $m3 = $this->send($a, $dm, '3')->json('id');

        // sender has nothing unread
        $this->as($a);
        $this->getJson('/api/chat/unread-count')->assertOk()->assertJsonPath('total', 0);
        $this->as($b);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('total', 3);
        $this->assertSame(3, $this->getJson('/api/chat/conversations')->json('0.unread_count'));

        $this->postJson("/api/chat/conversations/$dm/read", ['message_id' => $m2])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('unread_count', 1);
        // never backwards
        $this->postJson("/api/chat/conversations/$dm/read", ['message_id' => $m1])->assertOk()->assertJsonPath('unread_count', 1);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('total', 1);
        // default = latest
        $this->postJson("/api/chat/conversations/$dm/read")->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('total', 0);

        // deleted messages are not counted
        $this->send($a, $dm, '4');
        $m5 = $this->send($a, $dm, '5')->json('id');
        $this->as($a);
        $this->deleteJson("/api/chat/messages/$m5")->assertOk();
        $this->as($b);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('total', 1);
        // message from another conversation is rejected
        $c = User::factory()->sales()->create();
        $other = $this->dm($a, $c);
        $mx = $this->send($a, $other, 'x')->json('id');
        $this->as($b);
        $this->postJson("/api/chat/conversations/$dm/read", ['message_id' => $mx])->assertStatus(404);
    }

    public function test_delete_message_rules(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();

        $dm = $this->dm($a, $b);
        $m = $this->send($a, $dm, 'oops')->json('id');
        $this->as($b);
        $this->deleteJson("/api/chat/messages/$m")->assertStatus(403);
        $this->as($admin); // admin who is not a participant
        $this->deleteJson("/api/chat/messages/$m")->assertStatus(404);
        $this->as($a);
        $this->deleteJson("/api/chat/messages/$m")->assertOk()->assertJsonPath('ok', true);
        $this->as($b);
        $msgs = $this->getJson("/api/chat/conversations/$dm/messages")->json('messages');
        $this->assertSame('', $msgs[0]['body']);
        $this->assertTrue($msgs[0]['deleted']);
        $this->assertTrue($this->getJson('/api/chat/conversations')->json('0.last_message.deleted'));

        // admin participating in a group can delete others' messages; a plain member cannot
        $this->as($admin);
        $gid = $this->postJson('/api/chat/groups', ['name' => 'G', 'member_ids' => [(string) $a->id, (string) $b->id]])->json('id');
        $gm = $this->send($a, $gid, 'group msg')->json('id');
        $this->as($b);
        $this->deleteJson("/api/chat/messages/$gm")->assertStatus(403);
        $this->as($admin);
        $this->deleteJson("/api/chat/messages/$gm")->assertOk();
        $this->assertNotNull(ChatMessage::find($gm)->deleted_at);

        // admin in a DM does not get moderation rights over the other person's message
        $dm2 = $this->dm($admin, $a);
        $m2 = $this->send($a, $dm2, 'mine')->json('id');
        $this->as($admin);
        $this->deleteJson("/api/chat/messages/$m2")->assertStatus(403);
    }

    public function test_group_update_remove_member_loses_access_and_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $b = User::factory()->sales()->create();
        $c = User::factory()->sales()->create();
        $d = User::factory()->sales()->create();

        $this->as($admin);
        $gid = $this->postJson('/api/chat/groups', ['name' => 'G', 'member_ids' => [(string) $b->id, (string) $c->id]])->json('id');
        $this->send($b, $gid, 'before');

        $this->as($b);
        $this->patchJson("/api/chat/groups/$gid", ['name' => 'nope'])->assertStatus(403);
        $this->deleteJson("/api/chat/groups/$gid")->assertStatus(403);

        $this->as($admin);
        $r = $this->patchJson("/api/chat/groups/$gid", ['name' => 'Renamed', 'add_member_ids' => [(string) $d->id], 'remove_member_ids' => [(string) $c->id]])->assertOk();
        $this->assertSame('Renamed', $r->json('name'));
        $this->assertEqualsCanonicalizing([(string) $admin->id, (string) $b->id, (string) $d->id], array_column($r->json('members'), 'id'));
        $this->assertDatabaseHas('activity_log', ['entity' => 'chat_group', 'action' => 'rename']);
        $this->assertDatabaseHas('activity_log', ['entity' => 'chat_group', 'action' => 'members']);

        // removed member immediately loses access
        $this->as($c);
        $this->getJson("/api/chat/conversations/$gid/messages")->assertStatus(404);
        $this->postJson("/api/chat/conversations/$gid/messages", ['body' => 'x'])->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/chat/conversations')->json());
        // new member does not see history as unread but can read it
        $this->as($d);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('total', 0);
        $this->assertCount(1, $this->getJson("/api/chat/conversations/$gid/messages")->json('messages'));

        // cannot empty a group
        $this->as($admin);
        $this->patchJson("/api/chat/groups/$gid", ['remove_member_ids' => [(string) $admin->id, (string) $b->id, (string) $d->id]])->assertStatus(422);

        // delete removes group and messages
        $this->deleteJson("/api/chat/groups/$gid")->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('chat_conversations', ['id' => $gid]);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseHas('activity_log', ['entity' => 'chat_group', 'action' => 'delete']);
        $this->assertSame(0, ActivityLog::where('entity', 'chat_group')->get()->filter(fn ($l) => str_contains(json_encode($l->new_value).json_encode($l->old_value), 'before'))->count());
    }

    public function test_cannot_message_inactive_user(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $dm = $this->dm($a, $b);
        $b->update(['active' => false]);
        $this->send($a, $dm, 'hello?')->assertStatus(403);
    }

    public function test_conversations_sorted_by_latest_activity(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $c = User::factory()->sales()->create();
        $dm1 = $this->dm($a, $b);
        $dm2 = $this->dm($a, $c);
        $this->send($a, $dm1, 'x');
        $this->send($a, $dm2, 'y');
        $this->travel(1)->minutes();
        $this->send($a, $dm1, 'z');
        $this->as($a);
        $this->assertSame([$dm1, $dm2], array_column($this->getJson('/api/chat/conversations')->json(), 'id'));
    }

    public function test_send_is_rate_limited(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $dm = $this->dm($a, $b);
        for ($i = 0; $i < 60; $i++) {
            $this->send($a, $dm, "m$i")->assertOk();
        }
        $this->send($a, $dm, 'too many')->assertStatus(429)->assertJsonStructure(['detail']);
        // another user is unaffected
        $this->send($b, $dm, 'fine')->assertOk();
    }
}
