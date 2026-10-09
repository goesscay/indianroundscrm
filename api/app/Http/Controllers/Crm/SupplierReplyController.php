<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\SupplierReply;
use App\Models\SupplierReplyAttachment;
use App\Models\User;
use App\Services\Mail\ReplyMatcher;
use App\Services\Mail\ReplyProcessor;
use App\Support\ActivityLogger;
use App\Support\Ids;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SupplierReplyController extends Controller
{
    private const WITH = ['lead', 'leadSupplier', 'supplier', 'attachments'];

    // ------------------------------------------------------------------ helpers

    /** @return list<int> reply ids the user has unread */
    private function unreadIds(User $user, ?array $within = null): array
    {
        $q = DB::table('supplier_reply_reads')->where('user_id', $user->id)->whereNull('read_at');
        if ($within !== null) {
            $q->whereIn('reply_id', $within ?: [0]);
        }

        return $q->pluck('reply_id')->map(fn ($i) => (int) $i)->all();
    }

    private function leadVisible(string $leadId, User $user): bool
    {
        return LeadController::scopeForUser(Lead::where('lead_id', $leadId), $user)->exists();
    }

    /** Parent-reply read rule: rsq.manage sees all; otherwise rsq.request AND the reply's lead must be visible. */
    private function canRead(SupplierReply $reply, User $user): bool
    {
        if ($user->hasPermission('rsq.manage')) {
            return true;
        }

        return $reply->lead_id !== null && $user->hasPermission('rsq.request') && $this->leadVisible($reply->lead_id, $user);
    }

    private function find(string $id): SupplierReply
    {
        $r = SupplierReply::with(self::WITH)->find(Ids::parse($id));
        if (! $r) {
            Fail::notFound('Reply not found');
        }

        return $r;
    }

    private function present(SupplierReply $r, User $user): array
    {
        return $r->fresh(self::WITH)->toApi($this->unreadIds($user, [(int) $r->id]));
    }

    // ------------------------------------------------------------------ endpoints

    /** GET /leads/{lead_id}/supplier-replies */
    public function forLead(Request $request, string $leadId)
    {
        $user = $request->user();
        if (! Lead::where('lead_id', $leadId)->exists()) {
            Fail::notFound('Lead not found');
        }
        if (! $this->leadVisible($leadId, $user)) {
            Fail::forbidden('Forbidden');
        }
        $rows = SupplierReply::with(self::WITH)->where('lead_id', $leadId)
            ->whereIn('status', ['matched', 'bounce', 'auto_reply'])
            ->orderByDesc('received_at')->orderByDesc('id')->limit(500)->get();
        $unread = $this->unreadIds($user, $rows->pluck('id')->map(fn ($i) => (int) $i)->all());

        return $rows->map(fn ($r) => $r->toApi($unread))->values();
    }

    /** GET /supplier-replies (Supplier Inbox, rsq.manage) */
    public function index(Request $request)
    {
        $user = $request->user();
        $status = (string) $request->query('status', 'all');
        $q = SupplierReply::with(self::WITH)->orderByDesc('received_at')->orderByDesc('id');
        if ($status !== 'all' && $status !== '') {
            if (! in_array($status, SupplierReply::STATUSES, true)) {
                Fail::bad('Invalid status');
            }
            $q->where('status', $status);
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('subject', $op, $like)->orWhere('from_email', $op, $like)->orWhere('from_name', $op, $like)
                    ->orWhere('lead_id', $op, $like)
                    ->orWhereHas('lead', fn ($l) => $l->where('customer_name', $op, $like));
            });
        }

        $unread = $this->unreadIds($user);
        $page = Paging::paginate($request, $q, fn ($r) => $r->toApi($unread));

        $by = SupplierReply::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $counts = ['all' => (int) $by->sum()];
        foreach (SupplierReply::STATUSES as $s) {
            $counts[$s] = (int) ($by[$s] ?? 0);
        }
        $counts['unread'] = count($unread);

        return $page + ['counts' => $counts];
    }

    /** POST /supplier-replies/{id}/read */
    public function markRead(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->find($id);
        if (! $this->canRead($r, $user)) {
            Fail::forbidden('Forbidden');
        }
        DB::table('supplier_reply_reads')->updateOrInsert(['reply_id' => $r->id, 'user_id' => $user->id], ['read_at' => now()]);

        return ['ok' => true];
    }

    /** POST /supplier-replies/read-all?lead_id= (only ever touches the caller's own unread rows) */
    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $q = DB::table('supplier_reply_reads')->where('user_id', $user->id)->whereNull('read_at');
        $leadId = (string) ($request->query('lead_id') ?? (Fail::body($request)['lead_id'] ?? ''));
        if ($leadId !== '') {
            $q->whereIn('reply_id', SupplierReply::where('lead_id', $leadId)->select('id'));
        }
        $q->update(['read_at' => now()]);

        return ['ok' => true];
    }

    /** POST /supplier-replies/{id}/link */
    public function link(Request $request, string $id, ReplyMatcher $matcher, ReplyProcessor $processor)
    {
        $user = $request->user();
        $b = Fail::validate(Fail::body($request), ['lead_id' => 'required|string', 'supplier_id' => 'nullable']);
        $reply = $this->find($id);
        if (! in_array($reply->status, ['unmatched', 'ignored'], true)) {
            Fail::http(409, 'Reply is already linked to a lead');
        }
        $lead = Lead::where('lead_id', $b['lead_id'])->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }

        $tracker = null;
        if (($b['supplier_id'] ?? null) !== null && $b['supplier_id'] !== '') {
            $tracker = LeadSupplier::where('lead_id', $lead->lead_id)->where('supplier_id', Ids::parse((string) $b['supplier_id']))->first();
            if (! $tracker) {
                Fail::unprocessable(['supplier_id' => 'This supplier is not tracked on the lead']);
            }
        } else {
            $tracker = $matcher->pickTracker($lead->lead_id, mb_strtolower((string) $reply->from_email));
        }

        $reply->forceFill([
            'lead_id' => $lead->lead_id,
            'supplier_id' => $tracker?->supplier_id,
            'lead_supplier_id' => $tracker?->id,
            'status' => 'matched', 'matched_by' => 'manual',
            'handled_by' => $user->id, 'handled_at' => now(),
        ])->save();
        $processor->processMatched($reply);

        ActivityLogger::log($user, 'link_supplier_reply', 'supplier_reply', (string) $reply->id, null,
            ['lead_id' => $lead->lead_id, 'supplier_id' => $tracker?->supplier_id]);

        return $this->present($reply, $user);
    }

    /**
     * POST /supplier-replies/{id}/mark  {as: final|chatter|normal}
     *   final   : this is the supplier's real quote (one per supplier on the lead); the tracker follows it
     *   chatter : an internal forward / noise; stops counting as unread
     *   normal  : back to an ordinary reply
     */
    public function mark(Request $request, string $id)
    {
        $user = $request->user();
        $b = Fail::validate(Fail::body($request), ['as' => 'required|in:final,chatter,normal']);
        $reply = $this->find($id);
        if ($reply->status !== 'matched') {
            Fail::http(409, 'Only a reply linked to a lead can be marked');
        }
        $as = $b['as'];

        DB::transaction(function () use ($reply, $as) {
            if ($as === 'final') {
                SupplierReply::where('lead_id', $reply->lead_id)->where('is_final', true)
                    ->when($reply->lead_supplier_id, fn ($q, $t) => $q->where('lead_supplier_id', $t))
                    ->whereKeyNot($reply->id)->update(['is_final' => false]);
                $reply->forceFill(['relevance' => 'quote', 'is_final' => true])->save();
                if ($tracker = $reply->lead_supplier_id ? LeadSupplier::find($reply->lead_supplier_id) : null) {
                    $tracker->fill([
                        'reply_received' => true, 'reply_date' => $reply->received_at ?? now(), 'last_update' => now(),
                        'inbound_snippet' => mb_substr((string) $reply->body_preview, 0, 300), 'status' => 'Quote Received',
                    ])->save();
                }
                RsqController::markResponseReceived($reply->lead_id);
            } else {
                $reply->forceFill(['relevance' => $as, 'is_final' => false])->save();
            }
            if ($as === 'chatter') {
                DB::table('supplier_reply_reads')->where('reply_id', $reply->id)->whereNull('read_at')->update(['read_at' => now()]);
            }
        });
        ActivityLogger::log($user, 'mark_supplier_reply', 'supplier_reply', (string) $reply->id, null, ['as' => $as]);

        return $this->present($reply, $user);
    }

    /** POST /supplier-replies/{id}/ignore */
    public function ignore(Request $request, string $id)
    {
        $user = $request->user();
        $reply = $this->find($id);
        $before = $reply->status;
        $reply->forceFill(['status' => 'ignored', 'handled_by' => $user->id, 'handled_at' => now()])->save();
        DB::table('supplier_reply_reads')->where('reply_id', $reply->id)->whereNull('read_at')->update(['read_at' => now()]);
        ActivityLogger::log($user, 'ignore_supplier_reply', 'supplier_reply', (string) $reply->id, ['status' => $before], ['status' => 'ignored']);

        return $this->present($reply, $user);
    }

    /** GET /supplier-replies/attachments/{id}/download */
    public function download(Request $request, string $id)
    {
        $user = $request->user();
        $att = SupplierReplyAttachment::with('reply')->find(Ids::parse($id));
        if (! $att || ! $att->reply) {
            Fail::notFound('Attachment not found');
        }
        if (! $this->canRead($att->reply, $user)) {
            Fail::forbidden('Forbidden');
        }
        $disk = Storage::disk('supplier_replies');
        if ($att->skipped || ! $att->storage_path || ! $disk->exists($att->storage_path)) {
            Fail::notFound('Attachment not available');
        }

        return $disk->download($att->storage_path, $att->filename, [
            'Content-Type' => $att->content_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
