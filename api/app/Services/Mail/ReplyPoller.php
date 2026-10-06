<?php

namespace App\Services\Mail;

use App\Models\MailSyncState;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** One polling pass over the shared mailbox inbox. Idempotent and safe to run concurrently / repeatedly. */
class ReplyPoller
{
    public const CHECKPOINT = 'inbox_checkpoint';
    public const BATCH = 100;
    public const MAX_BATCHES = 20;

    public function __construct(private MailTransport $transport, private ReplyIngestor $ingestor)
    {
    }

    /** @return array{fetched:int,new:int,matched:int,unmatched:int,errors:int} */
    public function run(int $maxSeconds = 50): array
    {
        $sum = ['fetched' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0];
        $lock = Cache::lock('mail-poll', $maxSeconds + 60);
        if (! $lock->get()) {
            return $sum; // another poll is running
        }
        $started = microtime(true);
        MailSyncState::put('last_poll_at', now()->toIso8601String());

        try {
            $cursor = $this->startCursor();
            $newCheckpoint = null;

            for ($i = 0; $i < self::MAX_BATCHES; $i++) {
                $batch = $this->transport->fetchNewMessages($cursor, self::BATCH);
                $max = null;
                foreach ($batch->messages as $m) {
                    $sum['fetched']++;
                    $max = $max === null || $m->receivedAt->gt($max) ? $m->receivedAt : $max;
                    try {
                        $res = $this->ingestor->ingest($m);
                    } catch (\Throwable $e) {
                        $sum['errors']++;
                        Log::error('Mail ingest failed: '.$e->getMessage(), ['graph_id' => $m->id]);
                        MailSyncState::put('last_error', mb_substr($e->getMessage(), 0, 500));

                        continue;
                    }
                    if (! in_array($res, [ReplyIngestor::OWN, ReplyIngestor::DUPLICATE], true)) {
                        $sum['new']++;
                        in_array($res, ['matched', 'customer'], true) && $sum['matched']++;
                        $res === 'unmatched' && $sum['unmatched']++;
                    }
                }
                if ($max !== null) {
                    $newCheckpoint = $max;
                }
                if (! $batch->hasMore || $max === null || ($cursor && $max->lte($cursor)) || (microtime(true) - $started) > $maxSeconds) {
                    break;
                }
                $cursor = $max;
            }

            if ($newCheckpoint) {
                MailSyncState::put(self::CHECKPOINT, $newCheckpoint->copy()->utc()->toIso8601String());
            } elseif (MailSyncState::get(self::CHECKPOINT) === null) {
                MailSyncState::put(self::CHECKPOINT, now()->utc()->toIso8601String());
            }
            MailSyncState::put('last_success_at', now()->toIso8601String());
            if ($sum['errors'] === 0) {
                MailSyncState::put('last_error', null);
            }
        } catch (\Throwable $e) {
            $sum['errors']++;
            Log::error('Mail poll failed: '.$e->getMessage());
            MailSyncState::put('last_error', mb_substr($e->getMessage(), 0, 500));
        } finally {
            $lock->release();
        }

        return $sum;
    }

    /** Stored checkpoint minus the overlap window; first run looks back a bounded number of hours only. */
    private function startCursor(): Carbon
    {
        $stored = MailSyncState::get(self::CHECKPOINT);
        if ($stored) {
            try {
                return Carbon::parse($stored)->utc()->subMinutes(max(0, (int) config('services.microsoft.poll_overlap_minutes', 10)));
            } catch (\Throwable) {
            }
        }

        return now()->utc()->subHours(max(1, (int) config('services.microsoft.poll_lookback_hours', 48)));
    }
}
