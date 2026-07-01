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
