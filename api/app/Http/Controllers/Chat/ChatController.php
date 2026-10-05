<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    // ------------------------------------------------------------------ helpers

    private function me(Request $r): User
    {
        return $r->user();
    }

    private function userRow(User $u): array
    {
        return ['id' => (string) $u->id, 'name' => $u->name, 'role' => $u->role];
    }

    /** Conversation the user belongs to, or 404 (never leaks existence). */
    private function memberConversation(Request $r, string $id): ChatConversation
    {
        $cid = Ids::parse($id);
        $conv = ChatConversation::query()
            ->whereKey($cid)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('chat_participants')
                ->whereColumn('chat_participants.conversation_id', 'chat_conversations.id')
                ->where('chat_participants.user_id', $this->me($r)->id))
            ->first();
        abort_if(! $conv, 404, 'Not found');

        return $conv;
    }

    private function groupOrFail(string $id): ChatConversation
    {
        $conv = ChatConversation::find(Ids::parse($id));
        abort_if(! $conv, 404, 'Not found');
        abort_if($conv->type !== 'group', 400, 'Not a group conversation');

        return $conv;
    }

    /** Unread count per conversation id for a user (single grouped query, uses (conversation_id,id) index). */
    private function unreadByConversation(int $userId, ?int $onlyConversation = null): array
    {
        $q = DB::table('chat_participants as p')
            ->leftJoin('chat_messages as m', function ($j) {
                $j->on('m.conversation_id', '=', 'p.conversation_id')
                    ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
                    ->whereRaw('m.sender_id IS DISTINCT FROM p.user_id')
                    ->whereNull('m.deleted_at');
            })
            ->where('p.user_id', $userId)
            ->groupBy('p.conversation_id')
            ->selectRaw('p.conversation_id as cid, COUNT(m.id) as c');
        if ($onlyConversation) {
            $q->where('p.conversation_id', $onlyConversation);
        }

        return $q->pluck('c', 'cid')->map(fn ($v) => (int) $v)->all();
    }

    private function messageRow(ChatMessage $m, ?string $senderName): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => (string) $m->id,
            'conversation_id' => (string) $m->conversation_id,
            'sender_id' => $m->sender_id === null ? null : (string) $m->sender_id,
            'sender_name' => $senderName,
            'body' => $deleted ? '' : $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
            'deleted' => $deleted,
        ];
    }

    /**
     * @param  iterable<ChatConversation>  $convs
     */
    private function present(iterable $convs, User $me): array
    {
        $convs = collect($convs);
        if ($convs->isEmpty()) {
            return [];
        }
        $ids = $convs->pluck('id')->map(fn ($i) => (int) $i)->all();

        $parts = ChatParticipant::query()->whereIn('conversation_id', $ids)->get(['conversation_id', 'user_id']);
        $users = User::query()->whereIn('id', $parts->pluck('user_id')->unique())->get(['id', 'name', 'role'])->keyBy('id');
        $membersBy = $parts->groupBy('conversation_id');

        $lastIds = DB::table('chat_messages')->whereIn('conversation_id', $ids)
            ->groupBy('conversation_id')->selectRaw('MAX(id) as mid')->pluck('mid');
        $lastMsgs = $lastIds->isEmpty() ? collect() : ChatMessage::query()->whereIn('id', $lastIds)->get()->keyBy('conversation_id');
        $senders = User::query()->whereIn('id', $lastMsgs->pluck('sender_id')->filter()->unique())->pluck('name', 'id');

        $unread = $this->unreadByConversation($me->id);

        return $convs->map(function (ChatConversation $c) use ($me, $membersBy, $users, $lastMsgs, $senders, $unread) {
            $parts = $membersBy->get($c->id, collect());
            $isMember = $parts->contains(fn ($p) => (int) $p->user_id === (int) $me->id);
            $members = $parts->map(fn ($p) => $users->get($p->user_id))->filter()->map(fn ($u) => $this->userRow($u))->values()->all();
            $name = $c->name;
            if ($c->type === 'direct') {
                $other = $parts->first(fn ($p) => (int) $p->user_id !== (int) $me->id);
                $name = $other ? ($users->get($other->user_id)?->name ?? 'Unknown') : 'Unknown';
            }
            $last = $isMember ? $lastMsgs->get($c->id) : null; // a non-member admin managing a group never sees messages
            $lm = null;
            if ($last) {
                $lm = [
                    'id' => (string) $last->id,
                    'body' => $last->deleted_at ? '' : $last->body,
                    'sender_id' => $last->sender_id === null ? null : (string) $last->sender_id,
                    'sender_name' => $last->sender_id ? ($senders[$last->sender_id] ?? null) : null,
                    'created_at' => $last->created_at?->toIso8601String(),
                    'deleted' => $last->deleted_at !== null,
                ];
            }

            return [
                'id' => (string) $c->id,
                'type' => $c->type,
                'name' => $name,
                'members' => $members,
                'last_message' => $lm,
                'unread_count' => $isMember ? ($unread[$c->id] ?? 0) : 0,
                'updated_at' => ($c->last_message_at ?? $c->updated_at)?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function presentOne(ChatConversation $c, User $me): array
    {
        return $this->present([$c], $me)[0];
    }

    private function activeUserIds(array $ids): array
    {
        return User::query()->whereIn('id', $ids)->where('active', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function idList(mixed $v): array
    {
        return collect(is_array($v) ? $v : [])
            ->filter(fn ($i) => is_scalar($i) && ctype_digit((string) $i))
            ->map(fn ($i) => (int) $i)->filter(fn ($i) => $i > 0)->unique()->values()->all();
    }

    // ------------------------------------------------------------------ users / conversations

    public function users(Request $r): JsonResponse
    {
        $rows = User::query()->where('active', true)->where('id', '!=', $this->me($r)->id)
            ->orderBy('name')->get(['id', 'name', 'role'])->map(fn ($u) => $this->userRow($u));

        return response()->json($rows->values());
    }

    public function conversations(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $convs = ChatConversation::query()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('chat_participants')
                ->whereColumn('chat_participants.conversation_id', 'chat_conversations.id')
                ->where('chat_participants.user_id', $me->id))
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();

        return response()->json($this->present($convs, $me));
    }

    public function direct(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $data = $r->validate(['user_id' => ['required']]);
        $otherId = is_scalar($data['user_id']) && ctype_digit((string) $data['user_id']) ? (int) $data['user_id'] : 0;
        abort_if($otherId === 0, 404, 'User not found');
        abort_if($otherId === (int) $me->id, 400, 'You cannot message yourself');
        abort_if(! User::query()->where('active', true)->whereKey($otherId)->exists(), 404, 'User not found');

        $key = min($me->id, $otherId).':'.max($me->id, $otherId);
        $conv = ChatConversation::where('direct_key', $key)->first();
        if (! $conv) {
            try {
                $conv = DB::transaction(function () use ($key, $me, $otherId) {
                    $conv = ChatConversation::create(['type' => 'direct', 'direct_key' => $key, 'created_by' => $me->id]);
                    $now = now();
                    foreach ([$me->id, $otherId] as $uid) {
                        ChatParticipant::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'created_at' => $now]);
                    }

                    return $conv;
                });
            } catch (UniqueConstraintViolationException) {
                $conv = ChatConversation::where('direct_key', $key)->firstOrFail(); // lost a creation race
            }
        }

        return response()->json($this->presentOne($conv, $me));
    }

    // ------------------------------------------------------------------ groups (admin)

    public function createGroup(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $data = $r->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1'],
        ]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Group name is required');
        $wanted = array_values(array_diff($this->idList($data['member_ids']), [(int) $me->id]));
        abort_if(count($wanted) === 0, 422, 'Select at least one other active user');
        $valid = $this->activeUserIds($wanted);
        abort_if(count($valid) !== count($wanted), 422, 'One or more members are invalid or inactive');

        $all = array_merge([(int) $me->id], $valid);
        $conv = DB::transaction(function () use ($name, $me, $all) {
            $conv = ChatConversation::create(['type' => 'group', 'name' => $name, 'created_by' => $me->id]);
            $now = now();
            foreach ($all as $uid) {
                ChatParticipant::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'created_at' => $now]);
            }

            return $conv;
        });
        ActivityLogger::log($me, 'create', 'chat_group', $conv->id, null, ['name' => $name, 'member_ids' => array_map('strval', $all)]);

        return response()->json($this->presentOne($conv, $me));
    }

    public function updateGroup(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $conv = $this->groupOrFail($id);
        $data = $r->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'add_member_ids' => ['sometimes', 'array'],
            'remove_member_ids' => ['sometimes', 'array'],
        ]);
        $add = $this->idList($data['add_member_ids'] ?? []);
        $remove = $this->idList($data['remove_member_ids'] ?? []);

        $newName = array_key_exists('name', $data) ? trim($data['name']) : null;
        abort_if($newName === '', 422, 'Group name is required');

        $current = ChatParticipant::where('conversation_id', $conv->id)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        $toAdd = array_values(array_diff($add, $current, $remove));
        if ($toAdd) {
            abort_if(count($this->activeUserIds($toAdd)) !== count($toAdd), 422, 'One or more members are invalid or inactive');
        }
        $toRemove = array_values(array_intersect($remove, $current));
        abort_if(count($current) + count($toAdd) - count($toRemove) < 1, 422, 'A group must keep at least one member');

        $oldName = $conv->name;
        DB::transaction(function () use ($conv, $newName, $toAdd, $toRemove) {
            if ($newName !== null && $newName !== $conv->name) {
                $conv->update(['name' => $newName]);
            }
            $now = now();
            $head = ChatMessage::where('conversation_id', $conv->id)->max('id'); // new members start with history read
            foreach ($toAdd as $uid) {
                ChatParticipant::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'last_read_message_id' => $head, 'created_at' => $now]);
            }
            if ($toRemove) {
                ChatParticipant::where('conversation_id', $conv->id)->whereIn('user_id', $toRemove)->delete();
            }
        });

        if ($newName !== null && $newName !== $oldName) {
            ActivityLogger::log($me, 'rename', 'chat_group', $conv->id, ['name' => $oldName], ['name' => $newName]);
        }
        if ($toAdd || $toRemove) {
            ActivityLogger::log($me, 'members', 'chat_group', $conv->id, null, [
                'added' => array_map('strval', $toAdd), 'removed' => array_map('strval', $toRemove),
            ]);
        }

        return response()->json($this->presentOne($conv->fresh(), $me));
    }

    public function deleteGroup(Request $r, string $id): JsonResponse
    {
        $conv = $this->groupOrFail($id);
        $name = $conv->name;
        $conv->delete(); // participants + messages cascade
        ActivityLogger::log($this->me($r), 'delete', 'chat_group', $conv->id, ['name' => $name], null);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ messages

    public function messages(Request $r, string $id): JsonResponse
    {
        $conv = $this->memberConversation($r, $id);
        $limit = max(1, min(100, (int) $r->query('limit', 50)));
        $after = $r->query('after_id');
        $before = $r->query('before_id');

        $q = ChatMessage::query()->where('conversation_id', $conv->id);
        if ($after !== null && $after !== '') {
            $rows = $q->where('id', '>', Ids::parse((string) $after))->orderBy('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->values();
        } else {
            if ($before !== null && $before !== '') {
                $q->where('id', '<', Ids::parse((string) $before));
            }
            $rows = $q->orderByDesc('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }

        $names = User::query()->whereIn('id', $rows->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $out = $rows->map(fn ($m) => $this->messageRow($m, $m->sender_id ? ($names[$m->sender_id] ?? null) : null))->values();

        return response()->json(['messages' => $out, 'has_more' => $hasMore]);
    }

    public function send(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $conv = $this->memberConversation($r, $id);
        $body = trim((string) $r->input('body', ''));
        abort_if($body === '', 422, 'Message body is required');
        abort_if(mb_strlen($body) > 4000, 422, 'Message is too long (max 4000 characters)');

        if ($conv->type === 'direct') {
            $otherActive = DB::table('chat_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.conversation_id', $conv->id)->where('p.user_id', '!=', $me->id)->where('u.active', true)->exists();
            abort_if(! $otherActive, 403, 'This user is inactive and cannot receive messages');
        }

        $msg = DB::transaction(function () use ($conv, $me, $body) {
            $msg = ChatMessage::create(['conversation_id' => $conv->id, 'sender_id' => $me->id, 'body' => $body, 'created_at' => now()]);
            $conv->forceFill(['last_message_at' => $msg->created_at])->save();
            ChatParticipant::where('conversation_id', $conv->id)->where('user_id', $me->id)->update(['last_read_message_id' => $msg->id]);

            return $msg;
        });

        return response()->json($this->messageRow($msg, $me->name));
    }

    public function deleteMessage(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $msg = ChatMessage::find(Ids::parse($id));
        abort_if(! $msg, 404, 'Not found');
        $conv = $this->memberConversation($r, (string) $msg->conversation_id); // 404 for non-members
        $own = $msg->sender_id !== null && (int) $msg->sender_id === (int) $me->id;
        abort_if(! $own && ! ($me->isAdmin() && $conv->type === 'group'), 403, 'Forbidden');

        if ($msg->deleted_at === null) {
            $msg->update(['deleted_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function markRead(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $conv = $this->memberConversation($r, $id);
        $mid = $r->input('message_id');
        if ($mid === null || $mid === '') {
            $target = (int) ChatMessage::where('conversation_id', $conv->id)->max('id');
        } else {
            $target = Ids::parse((string) $mid);
            abort_if(! ChatMessage::where('conversation_id', $conv->id)->whereKey($target)->exists(), 404, 'Not found');
        }
        if ($target > 0) {
            ChatParticipant::where('conversation_id', $conv->id)->where('user_id', $me->id)
                ->update(['last_read_message_id' => DB::raw('GREATEST(COALESCE(last_read_message_id, 0), '.$target.')')]);
        }
        $unread = $this->unreadByConversation($me->id, $conv->id)[$conv->id] ?? 0;

        return response()->json(['ok' => true, 'unread_count' => $unread]);
    }

    public function unreadCount(Request $r): JsonResponse
    {
        $total = DB::table('chat_participants as p')
            ->join('chat_messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $this->me($r)->id)
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->whereRaw('m.sender_id IS DISTINCT FROM p.user_id')
            ->whereNull('m.deleted_at')
            ->count();

        return response()->json(['total' => (int) $total]);
    }
}
