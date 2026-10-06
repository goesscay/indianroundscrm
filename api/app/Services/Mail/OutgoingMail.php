<?php

namespace App\Services\Mail;

final class OutgoingMail
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{name:string,type:string,bytes:string}>  $attachments  small files only (Graph inline limit is about 3 MB)
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly bool $html = false,
        public readonly array $attachments = [],
    ) {
    }
}
