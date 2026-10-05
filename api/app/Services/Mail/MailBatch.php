<?php

namespace App\Services\Mail;

final class MailBatch
{
    /** @param  list<InboundMessage>  $messages */
    public function __construct(public readonly array $messages = [], public readonly bool $hasMore = false)
    {
    }
}
