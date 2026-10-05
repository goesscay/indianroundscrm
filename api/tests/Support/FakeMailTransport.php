<?php

namespace Tests\Support;

use App\Services\Mail\AttachmentData;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\MailBatch;
use App\Services\Mail\MailTransport;
use App\Services\Mail\MailTransportException;
use App\Services\Mail\OutgoingMail;
use App\Services\Mail\SentMailInfo;
use Carbon\CarbonInterface;

/** In-memory transport for tests. */
class FakeMailTransport implements MailTransport
{
    /** @var list<OutgoingMail> */
    public array $sent = [];

    /** @var list<InboundMessage> */
    public array $inbox = [];

    /** @var array<string,list<AttachmentData>> */
    public array $attachments = [];

    /** @var list<string> */
    public array $processed = [];

    public ?string $failSend = null;

    public ?string $failFetch = null;

    public ?string $failMark = null;

    private int $n = 0;

    public function name(): string
    {
        return 'graph';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function sendMessage(OutgoingMail $mail): SentMailInfo
    {
        if ($this->failSend) {
            throw new MailTransportException($this->failSend);
        }
        $this->sent[] = $mail;
        $n = ++$this->n;

        return new SentMailInfo("gid-$n", "<imid-$n@outlook.test>", "conv-$n");
    }

    public function fetchNewMessages(?CarbonInterface $since, int $limit = 100): MailBatch
    {
        if ($this->failFetch) {
            throw new MailTransportException($this->failFetch);
        }
        $msgs = array_values(array_filter($this->inbox, fn (InboundMessage $m) => ! $since || $m->receivedAt->gte($since)));
        usort($msgs, fn ($a, $b) => $a->receivedAt <=> $b->receivedAt);

        return new MailBatch(array_slice($msgs, 0, $limit), count($msgs) > $limit);
    }

    public function getAttachments(string $messageId): array
    {
        return $this->attachments[$messageId] ?? [];
    }

    public function markProcessed(string $messageId): void
    {
        if ($this->failMark) {
            throw new MailTransportException($this->failMark);
        }
        $this->processed[] = $messageId;
    }

    public function countUnprocessed(): ?int
    {
        return count(array_filter($this->inbox, fn ($m) => ! in_array($m->id, $this->processed, true)));
    }
}
