<?php

namespace Tests\Feature;

use App\Events\Chat\ConversationUpdated;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SpaRequests;
use Tests\TestCase;

class ChatBroadcastTest extends TestCase
{
    use RefreshDatabase, SpaRequests;

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

    private function channels($event): array
    {
        $c = array_map(fn (PrivateChannel $ch) => $ch->name, $event->broadcastOn());
        sort($c);

        return $c;
    }

    private function useReverbDriver(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        $this->app->forgetInstance(BroadcastManager::class);
        $this->app->forgetInstance(\Illuminate\Contracts\Broadcasting\Factory::class);
        require base_path('routes/channels.php'); // re-register channels on the reverb broadcaster
    }

    public function test_message_sent_goes_once_to_every_participant_including_sender(): void
    {
        Event::fake([MessageSent::class]);
        $a = User::factory()->sales()->create(['name' => 'Alice']);
        $b = User::factory()->sales()->create();
        $cid = $this->dm($a, $b);
        $this->as($a);
        $rest = $this->postJson("/api/chat/conversations/$cid/messages", ['body' => 'hello'])->assertOk()->json();

        Event::assertDispatchedTimes(MessageSent::class, 1);
        Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($a, $b, $cid, $rest) {
            $this->assertSame(['private-chat.user.'.min($a->id, $b->id), 'private-chat.user.'.max($a->id, $b->id)], array_map(
                fn ($n) => str_replace('private-', 'private-', $n), $this->channels($e)));
            $this->assertSame('message.sent', $e->broadcastAs());
            $this->assertSame(['conversation_id' => $cid, 'message' => $rest], $e->broadcastWith());
            $this->assertSame(['id', 'conversation_id', 'sender_id', 'sender_name', 'body', 'created_at', 'deleted'], array_keys($e->broadcastWith()['message']));

            return true;
        });
    }

    public function test_message_deleted_event(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $cid = $this->dm($a, $b);
        $this->as($a);
        $mid = $this->postJson("/api/chat/conversations/$cid/messages", ['body' => 'x'])->json('id');
        Event::fake([MessageDeleted::class]);
        $this->as($a);
        $this->deleteJson("/api/chat/messages/$mid")->assertOk();

        Event::assertDispatchedTimes(MessageDeleted::class, 1);
        Event::assertDispatched(MessageDeleted::class, function (MessageDeleted $e) use ($a, $b, $cid, $mid) {
            $this->assertSame('message.deleted', $e->broadcastAs());
            $this->assertSame(['conversation_id' => $cid, 'message_id' => $mid], $e->broadcastWith());
            $this->assertCount(2, $e->broadcastOn());

            return true;
        });
    }

    public function test_conversation_updated_on_dm_create_and_group_lifecycle(): void
    {
        Event::fake([ConversationUpdated::class]);
        $admin = User::factory()->admin()->create();
        $m1 = User::factory()->sales()->create();
        $m2 = User::factory()->sales()->create();
        $m3 = User::factory()->sales()->create();

        $this->dm($m1, $m2);
        Event::assertDispatchedTimes(ConversationUpdated::class, 1);
        $this->dm($m1, $m2); // idempotent: no new event
        Event::assertDispatchedTimes(ConversationUpdated::class, 1);

        $this->as($admin);
        $gid = $this->postJson('/api/chat/groups', ['name' => 'Team', 'member_ids' => [(string) $m1->id, (string) $m2->id]])->assertOk()->json('id');
        Event::assertDispatchedTimes(ConversationUpdated::class, 2);
        Event::assertDispatched(ConversationUpdated::class, fn ($e) => $e->conversationId === $gid
            && count($e->broadcastOn()) === 3 && $e->broadcastAs() === 'conversation.updated'
            && $e->broadcastWith() === ['conversation_id' => $gid]);

        $this->as($admin);
        $this->patchJson("/api/chat/groups/$gid", ['name' => 'Renamed'])->assertOk();
        Event::assertDispatchedTimes(ConversationUpdated::class, 3);

        $this->as($admin);
        $this->patchJson("/api/chat/groups/$gid", ['add_member_ids' => [(string) $m3->id], 'remove_member_ids' => [(string) $m1->id]])->assertOk();
        Event::assertDispatchedTimes(ConversationUpdated::class, 4);
        Event::assertDispatched(ConversationUpdated::class, function ($e) use ($gid, $admin, $m1, $m2, $m3) {
            if ($e->conversationId !== $gid || count($e->broadcastOn()) !== 4) {
                return false;
            }
            $want = collect([$admin, $m1, $m2, $m3])->map(fn ($u) => 'private-chat.user.'.$u->id)->sort()->values()->all();

            return $this->channels($e) === $want; // includes the removed member
        });

        $this->as($admin);
        $this->patchJson("/api/chat/groups/$gid", [])->assertOk(); // no-op change: no event
        Event::assertDispatchedTimes(ConversationUpdated::class, 4);

        $this->as($admin);
        $this->deleteJson("/api/chat/groups/$gid")->assertOk();
        Event::assertDispatchedTimes(ConversationUpdated::class, 5);
        Event::assertDispatched(ConversationUpdated::class, fn ($e) => $e->conversationId === $gid && count($e->broadcastOn()) === 3);
    }

    public function test_channel_authorisation_rules(): void
    {
        $this->useReverbDriver();
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $this->as($a);

        $ok = $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$a->id]);
        $ok->assertOk();
        $this->assertStringStartsWith('test-key:', $ok->json('auth'));

        $this->as($a);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$b->id])->assertStatus(403);
    }

    public function test_broadcasting_auth_via_spa_session(): void
    {
        $this->useReverbDriver();
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $ch = fn (User $u) => ['socket_id' => '1.2', 'channel_name' => 'private-chat.user.'.$u->id];

        $this->api('POST', '/api/broadcasting/auth', $ch($a))->assertStatus(401);

        $this->loginAs($a);
        $res = $this->api('POST', '/api/broadcasting/auth', $ch($a));
        $res->assertOk();
        $this->assertStringStartsWith('test-key:', $res->json('auth'));
        $this->api('POST', '/api/broadcasting/auth', $ch($b))->assertStatus(403);
    }

    public function test_broadcaster_failure_does_not_break_sending(): void
    {
        $this->app->make(BroadcastManager::class)->extend('boom', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new \RuntimeException('reverb down');
            }
        });
        config(['broadcasting.default' => 'boom', 'broadcasting.connections.boom' => ['driver' => 'boom']]);

        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $cid = $this->dm($a, $b);
        $this->as($a);
        $this->postJson("/api/chat/conversations/$cid/messages", ['body' => 'still saved'])->assertOk()->assertJsonPath('body', 'still saved');
        $this->assertSame(1, ChatMessage::where('body', 'still saved')->count());
    }
}
