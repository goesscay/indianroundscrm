<?php

namespace App\Services\Mail;

final class OutgoingMail
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $cc = [],
        public readonly array $bcc = [],
    ) {
    }
}
