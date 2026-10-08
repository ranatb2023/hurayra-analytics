<?php

namespace App\Console\Commands;

use App\Services\WooSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncWooCommerce extends Command
{
    protected $signature = 'woo:sync {--full : Re-read everything instead of only what changed since the last sync}';

    protected $description = 'Pull new and changed orders, subscriptions and status notes from the WooCommerce database (read only)';

    public function handle(WooSyncService $sync): int
    {
        if (! $sync->isConfigured()) {
            $this->warn('WooCommerce sync is not configured (WC_DB_HOST / WC_DB_NAME). CSV upload is still available.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync((bool) $this->option('full'));
        } catch (Throwable $e) {
            $this->error('WooCommerce sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Synced %d orders/subscriptions and %d status changes %s.',
            $result['records'],
            $result['changes'],
            $result['since'] === null ? '(full read)' : 'changed since '.$result['since'].' GMT',
        ));

        return self::SUCCESS;
    }
}
