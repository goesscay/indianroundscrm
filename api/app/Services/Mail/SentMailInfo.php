<?php

namespace App\Services\Mail;

final class SentMailInfo
{
    public function __construct(
        public readonly ?string $graph_message_id = null,
        public readonly ?string $internet_message_id = null,
        public readonly ?string $conversation_id = null,
        public readonly bool $simulated = false,
        public readonly ?string $note = null,
    ) {
    }
}
