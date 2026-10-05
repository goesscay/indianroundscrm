<?php

namespace App\Services\Mail;

use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\MailHistory;
use App\Models\MailRecipient;
use App\Models\Supplier;

/**
 * Finds the lead / supplier tracker a reply belongs to. Read-only (no side effects).
 * Priority: (a) conversationId  (b) In-Reply-To / References  (c) lead id in subject  (d) sender address.
 * The sender address also picks the tracker row once the lead is known.
 */
class ReplyMatcher
{
    public const LEAD_ID_REGEX = '/\bIR[A-Z]{0,2}\d{4,8}\b/i';

    public function match(InboundMessage $m): MatchResult
    {
        $sender = mb_strtolower(trim($m->fromEmail));
        $leadId = null;
        $by = null;
        $mail = null;
        $hint = null; // tracker id implied by a thread

        // (a) thread
        if ($m->conversationId) {
            $mail = MailHistory::where('conversation_id', $m->conversationId)->orderByDesc('id')->first();
            if ($mail && $this->leadExists($mail->lead_id)) {
                $leadId = $mail->lead_id;
                $by = 'thread';
            } else {
                $mail = null;
                $rows = LeadSupplier::where('conversation_id', $m->conversationId)->get();
                $leads = $rows->pluck('lead_id')->unique()->values();
                if ($leads->count() === 1 && $this->leadExists($leads[0])) {
                    $leadId = $leads[0];
                    $by = 'thread';
                    $hint = $rows->count() === 1 ? (int) $rows[0]->id : null;
                }
            }
        }

        // (b) In-Reply-To / References
        if ($leadId === null) {
            $ids = $this->idVariants(array_merge($m->inReplyTo ? [$m->inReplyTo] : [], $m->references));
            if ($ids) {
                $mail = MailHistory::whereIn('internet_message_id', $ids)->orderByDesc('id')->first();
                if ($mail && $this->leadExists($mail->lead_id)) {
                    $leadId = $mail->lead_id;
                    $by = 'reference';
                } else {
                    $mail = null;
                }
            }
        }

        // (c) lead id in the subject
        if ($leadId === null && preg_match_all(self::LEAD_ID_REGEX, $m->subject, $mm)) {
            foreach ($mm[0] as $cand) {
                $cand = strtoupper($cand);
                if ($this->leadExists($cand)) {
                    $leadId = $cand;
                    $by = 'subject';
                    break;
                }
            }
        }

        // (d) sender only: the lead must be unambiguous
        if ($leadId === null && $sender !== '') {
            $leads = LeadSupplier::whereRaw('LOWER(supplier_email) = ?', [$sender])->pluck('lead_id')->unique()->values();
            if ($leads->count() === 1 && $this->leadExists($leads[0])) {
                $leadId = $leads[0];
                $by = 'sender';
            }
        }

        if ($leadId === null) {
            return new MatchResult;
        }

        $tracker = $this->pickTracker($leadId, $sender, $mail, $hint);

        return new MatchResult($leadId, $tracker ? (int) $tracker->supplier_id : null, $tracker ? (int) $tracker->id : null,
            $mail ? (int) $mail->id : null, $by);
    }

    /** Sender address -> that supplier's tracker; else the single supplier the matched mail was sent to; else the thread hint. */
    public function pickTracker(string $leadId, string $sender, ?MailHistory $mail = null, ?int $hint = null): ?LeadSupplier
    {
        $q = LeadSupplier::where('lead_id', $leadId);
        if ($sender !== '') {
            $t = (clone $q)->whereRaw('LOWER(supplier_email) = ?', [$sender])->orderBy('id')->first();
            if ($t) {
                return $t;
            }
            $sid = Supplier::whereRaw('LOWER(email) = ?', [$sender])->value('id');
            if ($sid && ($t = (clone $q)->where('supplier_id', $sid)->first())) {
                return $t;
            }
        }
        if ($mail) {
            $sids = MailRecipient::where('mail_history_id', $mail->id)->where('type', 'to')->whereNotNull('supplier_id')->pluck('supplier_id')->unique();
            if ($sids->count() === 1) {
                return (clone $q)->where('supplier_id', $sids->first())->first();
            }
        }

        return $hint ? (clone $q)->whereKey($hint)->first() : null;
    }

    private function leadExists(?string $leadId): bool
    {
        return $leadId !== null && $leadId !== '' && Lead::where('lead_id', $leadId)->exists();
    }

    /** @return list<string> bare / angle-bracketed spellings of each message id */
    private function idVariants(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $bare = trim(trim((string) $id), '<>');
            if ($bare === '') {
                continue;
            }
            array_push($out, $bare, "<$bare>");
        }

        return array_values(array_unique($out));
    }
}
