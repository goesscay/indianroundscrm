<?php

namespace App\Console\Commands;

use App\Services\Mail\GraphMailTransport;
use App\Services\Mail\MailTransportResolver;
use Illuminate\Console\Command;

class MailTestGraph extends Command
{
    protected $signature = 'mail:test-graph';

    protected $description = 'Check Microsoft Graph credentials: token acquisition + access to the shared mailbox';

    public function handle(): int
    {
        if (! MailTransportResolver::graphConfigured()) {
            $missing = array_filter([
                'MS_TENANT_ID' => config('services.microsoft.tenant_id'), 'MS_CLIENT_ID' => config('services.microsoft.client_id'),
                'MS_CLIENT_SECRET' => config('services.microsoft.client_secret'), 'MS_MAILBOX' => config('services.microsoft.mailbox'),
            ], fn ($v) => trim((string) $v) === '');
            $this->error('FAIL - not configured. Missing: '.implode(', ', array_keys($missing)));

            return self::FAILURE;
        }
        $ok = true;
        foreach ((new GraphMailTransport)->diagnose() as $step) {
            $this->line(sprintf('[%s] %s - %s', $step['ok'] ? 'OK' : 'FAIL', $step['step'], $step['detail']));
            $ok = $ok && $step['ok'];
        }
        if (strtolower((string) config('services.microsoft.transport')) !== 'graph') {
            $this->warn('Note: MAIL_TRANSPORT is not "graph"; set MAIL_TRANSPORT=graph to actually send/receive through Microsoft 365.');
        }
        $ok ? $this->info('OK - Microsoft Graph mail is working.') : $this->error('FAIL - see the steps above.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
