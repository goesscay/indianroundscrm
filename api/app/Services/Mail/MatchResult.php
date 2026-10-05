<?php

namespace App\Services\Mail;

final class MatchResult
{
    public function __construct(
        public readonly ?string $leadId = null,
        public readonly ?int $supplierId = null,
        public readonly ?int $leadSupplierId = null,
        public readonly ?int $mailHistoryId = null,
        public readonly ?string $matchedBy = null, // thread|reference|subject|sender
    ) {
    }

    public function found(): bool
    {
        return $this->leadId !== null;
    }
}
