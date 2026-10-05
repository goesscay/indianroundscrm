<?php

namespace App\Services\Mail;

use App\Models\LeadSupplier;
use App\Models\SupplierReply;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Turns one inbound mailbox message into a stored supplier_replies row (+ side effects). Idempotent per graph message id. */
class ReplyIngestor
{
    public const OWN = 'own';
    public const DUPLICATE = 'duplicate';

    public function __construct(
        private MailTransport $transport,
        private ReplyMatcher $matcher,
        private ReplyClassifier $classifier,
        private ReplyProcessor $processor,
        private AttachmentStore $attachments,
    ) {
    }

    /** @return string 'own'|'duplicate'|'matched'|'unmatched'|'auto_reply'|'bounce' */
    public function ingest(InboundMessage $m): string
    {
        $mailbox = mb_strtolower(trim((string) config('services.microsoft.mailbox')));
        if ($m->isDraft || ($mailbox !== '' && mb_strtolower($m->fromEmail) === $mailbox)) {
            return self::OWN;
        }

        $existing = SupplierReply::where('graph_message_id', $m->id)->first();
        if ($existing) {
            $this->finish($existing, $m); // retry side effects / category mark that failed last time

            return self::DUPLICATE;
        }

        $text = ReplyText::toPlain($m->body, $m->bodyType);
        $kind = $this->classifier->classify($m);
        $match = $this->matcher->match($m);
        $tracker = null;

        if ($kind === 'bounce') {
            $status = $match->found() ? 'bounce' : 'unmatched';
            if ($match->found() && ! $match->leadSupplierId) {
                $tracker = $this->trackerFromBody($match->leadId, $text);
            }
        } elseif ($kind === 'auto_reply') {
            $status = 'auto_reply';
        } else {
            $status = $match->found() ? 'matched' : 'unmatched';
        }
        $attach = $match->found() || $status === 'auto_reply' ? $match : new MatchResult;

        try {
            $reply = DB::transaction(function () use ($m, $text, $status, $attach, $tracker) {
                $reply = SupplierReply::create([
                    'lead_id' => $attach->leadId,
                    'supplier_id' => $tracker?->supplier_id ?? $attach->supplierId,
                    'lead_supplier_id' => $tracker?->id ?? $attach->leadSupplierId,
                    'mail_history_id' => $attach->mailHistoryId,
                    'graph_message_id' => $m->id,
                    'internet_message_id' => $m->internetMessageId,
                    'conversation_id' => $m->conversationId,
                    'in_reply_to' => $m->inReplyTo,
                    'from_email' => mb_substr($m->fromEmail, 0, 255),
                    'from_name' => mb_substr($m->fromName, 0, 255),
                    'to_emails' => $m->to, 'cc_emails' => $m->cc,
                    'subject' => mb_substr($m->subject, 0, 998),
                    'body_text' => $text,
                    'body_preview' => ReplyText::preview($text, 300),
                    'received_at' => $m->receivedAt,
                    'status' => $status,
                    'matched_by' => $attach->matchedBy,
                ]);
                if ($m->hasAttachments) {
                    $this->attachments->storeAll($reply, $this->transport->getAttachments($m->id));
                }

                return $reply;
            });
        } catch (UniqueConstraintViolationException) {
            return self::DUPLICATE; // another poller won the race
        }

        $this->finish($reply, $m);

        return $status;
    }

    /** Side effects + mark the mailbox message as processed (both safe to repeat). */
    private function finish(SupplierReply $reply, InboundMessage $m): void
    {
        if (in_array($reply->status, ['matched', 'bounce'], true) && $reply->notified_at === null) {
            try {
                $this->processor->handle($reply);
            } catch (\Throwable $e) {
                Log::error('Supplier reply processing failed: '.$e->getMessage(), ['reply_id' => $reply->id]);
            }
        }
        $category = (string) config('services.microsoft.processed_category', 'ERP-Processed');
        if (! in_array($category, $m->categories, true)) {
            try {
                $this->transport->markProcessed($m->id);
            } catch (\Throwable $e) {
                Log::warning('Could not mark mail as processed: '.$e->getMessage());
            }
        }
    }

    /** For NDRs whose sender is MAILER-DAEMON: the tracker whose supplier address is quoted in the bounce text. */
    private function trackerFromBody(string $leadId, string $text): ?LeadSupplier
    {
        $hay = mb_strtolower($text);
        foreach (LeadSupplier::where('lead_id', $leadId)->orderBy('id')->get() as $t) {
            $e = mb_strtolower(trim((string) $t->supplier_email));
            if ($e !== '' && str_contains($hay, $e)) {
                return $t;
            }
        }

        return null;
    }
}
