<?php

use App\Jobs\SyncKlaviyoMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh the Klaviyo snapshots (current week/month/year) hourly.
Schedule::job(new SyncKlaviyoMetrics)->hourly()->withoutOverlapping();

// Direct read-only sync from WooCommerce, when WC_DB_* is configured. A new
// order reaches the dashboard within 15 minutes without an upload.
Schedule::command('woo:sync')->everyFifteenMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('woocommerce.enabled'));

// "No orders for N hours" alert. Fires once per incident; see NoOrdersAlert.
Schedule::command('alerts:no-orders')->hourly()->withoutOverlapping();
