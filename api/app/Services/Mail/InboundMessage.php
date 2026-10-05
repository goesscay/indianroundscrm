<?php

namespace App\Services\Mail;

use Carbon\CarbonInterface;

final class InboundMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  array<string,string>  $headers  lower-cased header name => value (internetMessageHeaders)
     * @param  list<string>  $categories
     * @param  list<string>  $references  internet message ids from the References header
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $internetMessageId,
        public readonly ?string $conversationId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $bodyType, // 'text' | 'html'
        public readonly CarbonInterface $receivedAt,
        public readonly array $headers = [],
        public readonly array $categories = [],
        public readonly bool $hasAttachments = false,
        public readonly bool $isDraft = false,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
