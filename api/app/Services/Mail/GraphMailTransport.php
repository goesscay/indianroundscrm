<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Graph transport (application permissions, OAuth2 client-credentials) for the shared mailbox.
 * Every request carries a timeout; 429/5xx/connection errors are retried with backoff honouring Retry-After.
 */
class GraphMailTransport implements MailTransport
{
    private const MESSAGE_SELECT = 'id,internetMessageId,conversationId,subject,from,toRecipients,ccRecipients,'
        .'receivedDateTime,body,hasAttachments,categories,internetMessageHeaders,isDraft';

    public function name(): string
    {
        return 'graph';
    }

    public function isConfigured(): bool
    {
        return MailTransportResolver::graphConfigured();
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("services.microsoft.$key", $default);
    }

    public function mailbox(): string
    {
        return (string) $this->cfg('mailbox');
    }

    private function userPath(string $suffix = ''): string
    {
        return '/users/'.rawurlencode($this->mailbox()).$suffix;
    }

    // ------------------------------------------------------------------ auth

    private function tokenKey(): string
    {
        return 'ms_graph_token:'.md5($this->cfg('tenant_id').'|'.$this->cfg('client_id'));
    }

    /** Access token (cached until shortly before expiry). @throws MailTransportException */
    public function token(bool $fresh = false): string
    {
        if (! $this->isConfigured()) {
            throw new MailTransportException('Microsoft 365 is not configured (set MS_TENANT_ID, MS_CLIENT_ID, MS_CLIENT_SECRET, MS_MAILBOX).');
        }
        if ($fresh) {
            Cache::forget($this->tokenKey());
        }
        $cached = Cache::get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = rtrim((string) $this->cfg('login_base'), '/').'/'.rawurlencode((string) $this->cfg('tenant_id')).'/oauth2/v2.0/token';
        try {
            $resp = Http::asForm()->acceptJson()->timeout((int) $this->cfg('timeout', 25))->post($url, [
                'client_id' => $this->cfg('client_id'),
                'client_secret' => $this->cfg('client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);
        } catch (ConnectionException $e) {
            throw new MailTransportException('Could not reach Microsoft login: '.$e->getMessage());
        }
        $token = $resp->json('access_token');
        if (! $resp->successful() || ! is_string($token) || $token === '') {
            $why = $resp->json('error_description') ?: $resp->json('error') ?: 'HTTP '.$resp->status();
            throw new MailTransportException('Microsoft token request failed: '.$this->firstLine((string) $why));
        }
        $ttl = max(60, (int) ($resp->json('expires_in') ?? 3600) - 120);
        Cache::put($this->tokenKey(), $token, $ttl);

        return $token;
    }

    private function firstLine(string $s): string
    {
        $s = trim($s);
        $line = preg_split('/\R/', $s)[0] ?? $s;

        return mb_substr($line, 0, 300);
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $json
     *
     * @throws MailTransportException
     */
    private function call(string $method, string $pathOrUrl, array $query = [], ?array $json = null, array $headers = []): Response
    {
        $url = str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : rtrim((string) $this->cfg('graph_base'), '/').$pathOrUrl;
        $max = max(0, (int) $this->cfg('max_retries', 3));
        $refreshed = false;
        $attempt = 0;

        while (true) {
            $token = $this->token();
            try {
                $req = Http::withToken($token)->acceptJson()->timeout((int) $this->cfg('timeout', 25))->withHeaders($headers);
                $opts = [];
                if ($query) {
                    $opts['query'] = $query;
                }
                if ($json !== null) {
                    $opts['json'] = $json;
                }
                $resp = $req->send($method, $url, $opts);
            } catch (ConnectionException $e) {
                if ($attempt < $max) {
                    $this->backoff($attempt++, null);

                    continue;
                }
                throw new MailTransportException('Could not reach Microsoft Graph: '.$e->getMessage());
            }

            if ($resp->status() === 401 && ! $refreshed) {
                $refreshed = true;
                $this->token(true);

                continue;
            }
            if (($resp->status() === 429 || $resp->status() >= 500) && $attempt < $max) {
                $this->backoff($attempt++, $resp->header('Retry-After'));

                continue;
            }
            if (! $resp->successful()) {
                $code = $resp->json('error.code') ?: 'HTTP '.$resp->status();
                $msg = $resp->json('error.message') ?: $resp->reason();
                throw new MailTransportException("Graph {$method} failed ({$resp->status()} {$code}): ".$this->firstLine((string) $msg));
            }

            return $resp;
        }
    }

    private function backoff(int $attempt, ?string $retryAfter): void
    {
        $cap = max(0, (int) $this->cfg('retry_max_wait', 30));
        $wait = ($retryAfter !== null && is_numeric($retryAfter)) ? (int) $retryAfter : (2 ** $attempt);
        $wait = min($wait, $cap);
        if ($wait > 0) {
            sleep($wait);
        }
    }

    // ------------------------------------------------------------------ sending

    private function recipients(array $emails): array
    {
        return array_values(array_map(fn ($e) => ['emailAddress' => ['address' => $e]], array_filter($emails)));
    }

    public function sendMessage(OutgoingMail $mail): SentMailInfo
    {
        $mailbox = $this->mailbox();
        $draft = $this->call('POST', $this->userPath('/messages'), [], [
            'subject' => $mail->subject,
            'body' => ['contentType' => 'Text', 'content' => $mail->body],
            'from' => ['emailAddress' => ['address' => $mailbox]],
            'toRecipients' => $this->recipients($mail->to),
            'ccRecipients' => $this->recipients($mail->cc),
            'bccRecipients' => $this->recipients($mail->bcc),
            'replyTo' => $this->recipients([$mailbox]),
        ]);
        $id = (string) $draft->json('id');
        if ($id === '') {
            throw new MailTransportException('Graph did not return a draft id.');
        }

        // re-read the draft: id / conversationId / internetMessageId are only reliable once it exists server side
        $conversationId = $draft->json('conversationId');
        $internetId = $draft->json('internetMessageId');
        try {
            $get = $this->call('GET', $this->userPath('/messages/'.rawurlencode($id)), ['$select' => 'id,conversationId,internetMessageId']);
            $conversationId = $get->json('conversationId') ?: $conversationId;
            $internetId = $get->json('internetMessageId') ?: $internetId;
        } catch (MailTransportException $e) {
            Log::warning('Graph draft re-read failed: '.$e->getMessage());
        }

        try {
            $this->call('POST', $this->userPath('/messages/'.rawurlencode($id).'/send'));
        } catch (MailTransportException $e) {
            try { // do not leave an orphan draft in the shared mailbox
                $this->call('DELETE', $this->userPath('/messages/'.rawurlencode($id)));
            } catch (\Throwable) {
            }
            throw $e;
        }

        return new SentMailInfo($id, $internetId ?: null, $conversationId ?: null);
    }

    // ------------------------------------------------------------------ reading

    public function fetchNewMessages(?CarbonInterface $since, int $limit = 100): MailBatch
    {
        $folder = rawurlencode((string) $this->cfg('inbox_folder', 'Inbox'));
        $path = $this->userPath("/mailFolders/$folder/messages");
        $query = [
            '$orderby' => 'receivedDateTime asc',
            '$top' => max(1, min(50, $limit)),
            '$select' => self::MESSAGE_SELECT,
        ];
        if ($since) {
            $query['$filter'] = 'receivedDateTime ge '.Carbon::instance($since)->utc()->format('Y-m-d\TH:i:s\Z');
        }
        $headers = ['Prefer' => 'outlook.body-content-type="text"'];

        $out = [];
        $next = null;
        $first = true;
        do {
            $resp = $first
                ? $this->call('GET', $path, $query, null, $headers)
                : $this->call('GET', $next, [], null, $headers);
            $first = false;
            foreach ((array) $resp->json('value', []) as $row) {
                $out[] = $this->parseMessage((array) $row);
            }
            $next = $resp->json()['@odata.nextLink'] ?? null; // (json() key lookup would treat the dot as nesting)
        } while ($next && count($out) < $limit);

        return new MailBatch($out, (bool) $next);
    }

    private function parseMessage(array $m): InboundMessage
    {
        $headers = [];
        foreach ((array) ($m['internetMessageHeaders'] ?? []) as $h) {
            if (isset($h['name'])) {
                $headers[strtolower((string) $h['name'])] = (string) ($h['value'] ?? '');
            }
        }
        $addr = fn ($r) => (string) ($r['emailAddress']['address'] ?? '');
        $refs = preg_split('/\s+/', trim($headers['references'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $irt = trim($headers['in-reply-to'] ?? '');

        return new InboundMessage(
            id: (string) $m['id'],
            internetMessageId: $m['internetMessageId'] ?? null,
            conversationId: $m['conversationId'] ?? null,
            inReplyTo: $irt !== '' ? $irt : null,
            references: array_values($refs),
            fromEmail: mb_strtolower($addr($m['from'] ?? [])),
            fromName: (string) ($m['from']['emailAddress']['name'] ?? ''),
            to: array_values(array_filter(array_map($addr, (array) ($m['toRecipients'] ?? [])))),
            cc: array_values(array_filter(array_map($addr, (array) ($m['ccRecipients'] ?? [])))),
            subject: (string) ($m['subject'] ?? ''),
            body: (string) ($m['body']['content'] ?? ''),
            bodyType: strtolower((string) ($m['body']['contentType'] ?? 'text')) === 'html' ? 'html' : 'text',
            receivedAt: Carbon::parse($m['receivedDateTime'] ?? 'now', 'UTC')->utc(),
            headers: $headers,
            categories: array_values((array) ($m['categories'] ?? [])),
            hasAttachments: (bool) ($m['hasAttachments'] ?? false),
            isDraft: (bool) ($m['isDraft'] ?? false),
        );
    }

    public function getAttachments(string $messageId): array
    {
        $base = $this->userPath('/messages/'.rawurlencode($messageId).'/attachments');
        $resp = $this->call('GET', $base, ['$select' => 'id,name,contentType,size,isInline']);
        $out = [];
        foreach ((array) $resp->json('value', []) as $a) {
            $aid = (string) ($a['id'] ?? '');
            $isFile = ($a['@odata.type'] ?? '#microsoft.graph.fileAttachment') === '#microsoft.graph.fileAttachment';
            $out[] = new AttachmentData(
                id: $aid,
                name: (string) ($a['name'] ?? 'attachment'),
                contentType: (string) ($a['contentType'] ?? ''),
                size: (int) ($a['size'] ?? 0),
                isFile: $isFile,
                isInline: (bool) ($a['isInline'] ?? false),
                loader: $isFile ? function () use ($base, $aid) {
                    $r = $this->call('GET', $base.'/'.rawurlencode($aid));
                    $b64 = $r->json('contentBytes');
                    $raw = is_string($b64) ? base64_decode($b64, true) : false;

                    return $raw === false ? null : $raw;
                } : null,
            );
        }

        return $out;
    }

    public function markProcessed(string $messageId): void
    {
        $category = (string) $this->cfg('processed_category', 'ERP-Processed');
        $path = $this->userPath('/messages/'.rawurlencode($messageId));
        $cats = (array) $this->call('GET', $path, ['$select' => 'categories'])->json('categories', []);
        if (in_array($category, $cats, true)) {
            return;
        }
        $cats[] = $category;
        $this->call('PATCH', $path, [], ['categories' => array_values($cats)]);
    }

    public function countUnprocessed(): ?int
    {
        try {
            $folder = rawurlencode((string) $this->cfg('inbox_folder', 'Inbox'));
            $cat = str_replace("'", "''", (string) $this->cfg('processed_category', 'ERP-Processed'));
            $resp = $this->call('GET', $this->userPath("/mailFolders/$folder/messages"), [
                '$filter' => "not(categories/any(c:c eq '$cat'))", '$top' => 1, '$count' => 'true', '$select' => 'id',
            ], null, ['ConsistencyLevel' => 'eventual']);
            $n = $resp->json()['@odata.count'] ?? null;

            return $n === null ? null : (int) $n;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Connectivity check used by `mail:test-graph`.
     *
     * @return list<array{step:string,ok:bool,detail:string}>
     */
    public function diagnose(): array
    {
        $steps = [];
        try {
            $this->token(true);
            $steps[] = ['step' => 'Acquire access token', 'ok' => true, 'detail' => 'token obtained'];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Acquire access token', 'ok' => false, 'detail' => $e->getMessage()];

            return $steps;
        }
        try {
            $folder = rawurlencode((string) $this->cfg('inbox_folder', 'Inbox'));
            $r = $this->call('GET', $this->userPath("/mailFolders/$folder"), ['$select' => 'displayName,totalItemCount,unreadItemCount']);
            $steps[] = ['step' => 'Read mailbox '.$this->mailbox(), 'ok' => true,
                'detail' => sprintf('folder "%s": %d items', $r->json('displayName'), (int) $r->json('totalItemCount'))];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Read mailbox '.$this->mailbox(), 'ok' => false, 'detail' => $e->getMessage()];
        }

        return $steps;
    }
}
