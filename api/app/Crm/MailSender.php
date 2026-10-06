<?php

namespace App\Crm;

use App\Services\Mail\MailTransport;
use App\Services\Mail\OutgoingMail;
use Illuminate\Support\Facades\Log;

/**
 * Mail (plain text by default, optional HTML + small attachments) via the configured MailTransport (Microsoft Graph, or the log transport in dev).
 * Never throws: returns ['status' => 'sent'|'failed'|'simulated_sent', 'provider' => 'graph'|'log', ids..., 'error'?, 'note'?].
 */
class MailSender
{
    /** @param list<array{name:string,type:string,bytes:string}> $attachments */
    public static function send(array $to, string $subject, string $body, array $cc = [], array $bcc = [], bool $html = false, array $attachments = []): array
    {
        $transport = app(MailTransport::class);
        $provider = $transport->name();
        try {
            $info = $transport->sendMessage(new OutgoingMail(array_values($to), $subject, $body, array_values($cc), array_values($bcc), $html, $attachments));
        } catch (\Throwable $e) {
            Log::error('Supplier mail send failed: '.$e->getMessage());

            return ['status' => 'failed', 'provider' => $provider, 'error' => mb_substr($e->getMessage(), 0, 1000)];
        }

        return array_filter([
            'status' => $info->simulated ? 'simulated_sent' : 'sent',
            'provider' => $provider,
            'message_id' => $info->graph_message_id,
            'graph_message_id' => $info->graph_message_id,
            'internet_message_id' => $info->internet_message_id,
            'conversation_id' => $info->conversation_id,
            'note' => $info->note,
        ], fn ($v) => $v !== null);
    }
}
