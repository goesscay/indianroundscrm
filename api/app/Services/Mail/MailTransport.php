<?php

namespace App\Services\Mail;

use Carbon\CarbonInterface;

/** Sends supplier mail and reads replies from the shared mailbox. Implementations: Graph, Log (dev), Fake (tests). */
interface MailTransport
{
    /** 'graph' | 'log' (stored as mail_history.provider) */
    public function name(): string;

    /** False when the transport cannot reach a real mailbox (log transport, or graph without credentials). */
    public function isConfigured(): bool;

    /** @throws MailTransportException */
    public function sendMessage(OutgoingMail $mail): SentMailInfo;

    /**
     * Inbox messages received at/after $since, oldest first (at most $limit; MailBatch::$hasMore tells if more remain).
     *
     * @throws MailTransportException
     */
    public function fetchNewMessages(?CarbonInterface $since, int $limit = 100): MailBatch;

    /**
     * File/other attachments of a message (content is loaded lazily via AttachmentData::bytes()).
     *
     * @return list<AttachmentData>
     *
     * @throws MailTransportException
     */
    public function getAttachments(string $messageId): array;

    /** Flag the message as handled (category) without moving/deleting it. @throws MailTransportException */
    public function markProcessed(string $messageId): void;

    /** Messages still in the inbox without the processed category (null = unknown). */
    public function countUnprocessed(): ?int;
}
