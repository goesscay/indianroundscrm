<?php

namespace App\Crm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Plain-text mail sender.
 *  - RESEND_API_KEY + RESEND_FROM set  -> Resend REST API
 *  - otherwise                          -> Laravel Mail with the default mailer (log by default)
 * Returns ['status','provider','message_id'?,'error'?,'note'?].
 */
class MailSender
{
    public static function send(array $to, string $subject, string $body, array $cc = [], array $bcc = [], ?string $from = null): array
    {
        $apiKey = trim((string) env('RESEND_API_KEY', ''));
        $sender = trim((string) ($from ?: env('RESEND_FROM', '')));

        if ($apiKey !== '' && $sender !== '') {
            try {
                $payload = ['from' => $sender, 'to' => $to, 'subject' => $subject, 'text' => $body];
                if ($cc) {
                    $payload['cc'] = $cc;
                }
                if ($bcc) {
                    $payload['bcc'] = $bcc;
                }
                $resp = Http::withToken($apiKey)->acceptJson()->timeout(20)->post('https://api.resend.com/emails', $payload);
                if (! $resp->successful()) {
                    return ['status' => 'failed', 'provider' => 'resend', 'error' => $resp->body()];
                }
                return ['status' => 'sent', 'provider' => 'resend', 'message_id' => $resp->json('id')];
            } catch (\Throwable $e) {
                Log::error('Resend send failed: '.$e->getMessage());
                return ['status' => 'failed', 'provider' => 'resend', 'error' => $e->getMessage()];
            }
        }

        $mailer = config('mail.default');
        try {
            Mail::mailer($mailer)->raw($body, function ($m) use ($to, $subject, $cc, $bcc) {
                $m->to($to)->subject($subject);
                if ($cc) {
                    $m->cc($cc);
                }
                if ($bcc) {
                    $m->bcc($bcc);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Mail send failed: '.$e->getMessage());
            return ['status' => 'failed', 'provider' => $mailer, 'error' => $e->getMessage()];
        }
        if (in_array($mailer, ['log', 'array'], true)) {
            return ['status' => 'simulated_sent', 'provider' => 'mock',
                'note' => 'RESEND_API_KEY or RESEND_FROM not set - mail written to the log mailer, no real email dispatched.'];
        }
        return ['status' => 'sent', 'provider' => $mailer];
    }
}
