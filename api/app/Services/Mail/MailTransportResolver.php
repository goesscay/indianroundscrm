<?php

namespace App\Services\Mail;

class MailTransportResolver
{
    /** True when every MS_* value required for Graph is present. */
    public static function graphConfigured(): bool
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'mailbox'] as $k) {
            if (trim((string) config("services.microsoft.$k")) === '') {
                return false;
            }
        }

        return true;
    }

    /** MAIL_TRANSPORT=graph|log; empty => graph only when configured, otherwise log (local dev / tests never hit the network). */
    public static function make(): MailTransport
    {
        $want = strtolower(trim((string) config('services.microsoft.transport')));
        if ($want === '') {
            $want = self::graphConfigured() ? 'graph' : 'log';
        }

        return $want === 'graph' ? new GraphMailTransport : new LogMailTransport;
    }
}
