<?php

namespace App\Services\Mail;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Local/dev transport: writes the mail to the log, returns synthetic ids, receives nothing. Never touches the network. */
class LogMailTransport implements MailTransport
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function sendMessage(OutgoingMail $mail): SentMailInfo
    {
        $n = (string) Str::uuid();
        Log::info('[mail:log] supplier mail not sent (MAIL_TRANSPORT=log)', [
            'to' => $mail->to, 'cc' => $mail->cc, 'bcc' => $mail->bcc, 'subject' => $mail->subject,
        ]);

        return new SentMailInfo("log-$n", "<$n@log.local>", "log-conv-$n", true,
            'MAIL_TRANSPORT is "log" (Microsoft 365 not configured) - mail written to the log, no real email dispatched.');
    }

    public function fetchNewMessages(?CarbonInterface $since, int $limit = 100): MailBatch
    {
        return new MailBatch([], false);
    }

    public function getAttachments(string $messageId): array
    {
        return [];
    }

    public function markProcessed(string $messageId): void
    {
    }

    public function countUnprocessed(): ?int
    {
        return 0;
    }
}
