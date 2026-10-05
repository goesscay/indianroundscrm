<?php

namespace App\Services\Mail;

/** Pure classification of an inbound message: 'bounce' | 'auto_reply' | 'normal'. Bounces win over auto-replies (NDRs are auto-submitted). */
class ReplyClassifier
{
    public function classify(InboundMessage $m): string
    {
        if ($this->isBounce($m)) {
            return 'bounce';
        }
        if ($this->isAutoReply($m)) {
            return 'auto_reply';
        }

        return 'normal';
    }

    public function isBounce(InboundMessage $m): bool
    {
        $local = strtolower(explode('@', $m->fromEmail)[0] ?? '');
        if (in_array($local, ['mailer-daemon', 'postmaster', 'mailerdaemon'], true) || str_starts_with($local, 'mailer-daemon')) {
            return true;
        }
        if (preg_match('/undeliverable|delivery status notification|mail delivery failed|delivery has failed|returned mail|failure notice/i', $m->subject)) {
            return true;
        }
        $ct = strtolower((string) $m->header('content-type'));

        return str_contains($ct, 'report-type=delivery-status') || str_contains($ct, 'multipart/report');
    }

    public function isAutoReply(InboundMessage $m): bool
    {
        $auto = $m->header('auto-submitted');
        if ($auto !== null && strtolower(trim($auto)) !== 'no') {
            return true;
        }
        if ($m->header('x-auto-response-suppress') !== null) {
            return true;
        }
        $prec = strtolower(trim((string) $m->header('precedence')));
        if (in_array($prec, ['bulk', 'auto_reply', 'auto-reply', 'junk'], true)) {
            return true;
        }

        return (bool) preg_match('/\b(automatic reply|auto[- ]?reply|autoreply|auto-response|out of (the )?office|out-of-office)\b/i', $m->subject);
    }
}
