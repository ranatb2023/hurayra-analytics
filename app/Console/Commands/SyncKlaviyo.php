<?php

namespace App\Console\Commands;

use App\Jobs\SyncKlaviyoMetrics;
use Illuminate\Console\Command;

class SyncKlaviyo extends Command
{
    protected $signature = 'klaviyo:sync';

    protected $description = 'Fetch Klaviyo metrics now (current week/month/year) — runs synchronously, no queue worker needed';

    public function handle(): int
    {
        $this->info('Syncing Klaviyo metrics…');
        SyncKlaviyoMetrics::dispatchSync();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
