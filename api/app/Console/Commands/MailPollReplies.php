<?php

namespace App\Console\Commands;

use App\Services\Mail\ReplyPoller;
use Illuminate\Console\Command;

class MailPollReplies extends Command
{
    protected $signature = 'mail:poll-replies';

    protected $description = 'Pull new supplier replies from the shared Microsoft 365 mailbox, match them to leads and notify owners';

    public function handle(ReplyPoller $poller): int
    {
        $s = $poller->run();
        $this->line(sprintf('fetched=%d new=%d matched=%d unmatched=%d errors=%d', $s['fetched'], $s['new'], $s['matched'], $s['unmatched'], $s['errors']));

        return $s['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
