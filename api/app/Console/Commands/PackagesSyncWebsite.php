<?php

namespace App\Console\Commands;

use App\Services\WebsiteTours;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;

/** Nightly: bring the tours of indianrounds.in in as packages. Nothing is deleted; see App\Services\WebsiteTours. */
class PackagesSyncWebsite extends Command
{
    protected $signature = 'packages:sync-website {--force : run even when CRM_WEBSITE_SYNC is off}';

    protected $description = 'Import or refresh the packages from the public website';

    public function handle(WebsiteTours $sync): int
    {
        if (! config('crm.website.sync', true) && ! $this->option('force')) {
            $this->line('Website sync is off (CRM_WEBSITE_SYNC).');

            return self::SUCCESS;
        }
        try {
            $r = $sync->run();
        } catch (\Throwable $e) {
            $this->error('Website sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
        ActivityLogger::log(null, 'sync_website_packages', 'package', 'website', null, $r);
        $this->info(sprintf('%d tours: %d new, %d updated, %d unchanged, %d unlocked skipped, %d hidden, %d failed.', $r['tours'], $r['created'], $r['updated'], $r['unchanged'], $r['skipped_unlocked'], $r['deactivated'], $r['failed']));

        return self::SUCCESS;
    }
}
