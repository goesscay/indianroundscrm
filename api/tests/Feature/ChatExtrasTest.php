<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    private function group(User $admin, array $members): string
    {
        $this->as($admin);

        return $this->postJson('/api/chat/groups', ['name' => 'Goa desk', 'member_ids' => array_map(fn ($u) => (string) $u->id, $members)])->assertOk()->json('id');
    }

    public function test_mentions_are_kept_only_for_people_in_the_conversation_and_counted_as_unread(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $outsider = User::factory()->sales()->create();
        $g = $this->group($admin, [$a, $b]);

        $this->as($admin);
        $m = $this->postJson("/api/chat/conversations/$g/messages", ['body' => "@{$a->name} please call", 'mentions' => [(string) $a->id, (string) $outsider->id, (string) $admin->id]])
            ->assertOk()->json();
        $this->assertSame([(string) $a->id], $m['mentions']);                       // not the outsider, not yourself
        $this->assertSame('text', $m['kind']);

        $this->as($a);
        $this->assertSame(1, $this->getJson('/api/chat/conversations')->json('0.unread_mentions'));
        $this->as($b);
        $this->assertSame(0, $this->getJson('/api/chat/conversations')->json('0.unread_mentions'));   // unread, but not tagged
        $this->assertSame(1, $this->getJson('/api/chat/conversations')->json('0.unread_count'));

        $this->as($a);
        $this->postJson("/api/chat/conversations/$g/read")->assertOk();
        $this->assertSame(0, $this->getJson('/api/chat/conversations')->json('0.unread_mentions'));
    }

    public function test_stickers_are_their_own_kind_and_preview_as_a_sticker(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $this->as($a);
        $dm = $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $b->id])->json('id');

        $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'Bad Sticker!', 'kind' => 'sticker'])->assertStatus(422);
        $m = $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'thank-you', 'kind' => 'sticker'])->assertOk()->json();
        $this->assertSame('sticker', $m['kind']);
        $this->assertSame([], $m['mentions']);
        $this->assertSame('Sticker', $this->getJson('/api/chat/conversations')->json('0.last_message.body'));
        $this->assertSame('thank-you', $this->getJson("/api/chat/conversations/$dm/messages")->json('messages.0.body'));
    }

    public function test_reactions_toggle_group_by_emoji_and_reject_text(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $this->as($a);
        $dm = $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $b->id])->json('id');
        $mid = $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'booked!'])->json('id');

        $this->as($b);
        $this->postJson("/api/chat/messages/$mid/reactions", ['emoji' => 'abc'])->assertStatus(422);
        $this->postJson("/api/chat/messages/$mid/reactions", ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions.0.emoji', '👍')->assertJsonPath('reactions.0.user_ids', [(string) $b->id]);
        $this->as($a);
        $r = $this->postJson("/api/chat/messages/$mid/reactions", ['emoji' => '👍'])->assertOk()->json('reactions');
        $this->assertCount(1, $r);
        $this->assertCount(2, $r[0]['user_ids']);
        $this->assertSame(1, count($this->getJson("/api/chat/conversations/$dm/messages")->json('messages.0.reactions')));

        $this->postJson("/api/chat/messages/$mid/reactions", ['emoji' => '👍'])->assertOk();          // second tap takes it back
        $this->assertSame([(string) $b->id], $this->getJson("/api/chat/conversations/$dm/messages")->json('messages.0.reactions.0.user_ids'));

        $this->as(User::factory()->sales()->create());
        $this->postJson("/api/chat/messages/$mid/reactions", ['emoji' => '👍'])->assertStatus(404);   // not in the conversation
    }

    public function test_the_message_list_says_how_far_everyone_has_read(): void
    {
        $a = User::factory()->sales()->create();
        $b = User::factory()->sales()->create();
        $this->as($a);
        $dm = $this->postJson('/api/chat/conversations/direct', ['user_id' => (string) $b->id])->json('id');
        $m1 = $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'one'])->json('id');
        $m2 = $this->postJson("/api/chat/conversations/$dm/messages", ['body' => 'two'])->json('id');

        $reads = $this->getJson("/api/chat/conversations/$dm/messages")->json('reads');
        $this->assertSame($m2, $reads[(string) $a->id]);       // the sender has read their own
        $this->assertNull($reads[(string) $b->id]);            // the other person has not opened it

        $this->as($b);
        $this->postJson("/api/chat/conversations/$dm/read", ['message_id' => $m1])->assertOk();
        $this->as($a);
        $this->assertSame($m1, $this->getJson("/api/chat/conversations/$dm/messages?after_id=$m2")->json('reads')[(string) $b->id]);   // also on a poll that finds nothing new
    }
}
