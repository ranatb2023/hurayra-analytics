<?php

namespace App\Jobs;

use App\Models\KlaviyoMetric;
use App\Models\KlaviyoSetting;
use App\Services\KlaviyoService;
use App\Support\PeriodResolver;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fetches a snapshot of the six Klaviyo tiles and stores it per period bucket.
 *
 * - No args  → syncs the current week, month and year buckets (the scheduled run).
 * - With args → syncs a single explicit bucket (manual refresh / custom range).
 */
class SyncKlaviyoMetrics implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // multiple throttled report calls per bucket

    /**
     * @param  string|null  $granularity  week|month|year|custom
     * @param  string|null  $start  'Y-m-d H:i:s' (inclusive)
     * @param  string|null  $end    'Y-m-d H:i:s' (exclusive upper bound)
     */
    public function __construct(
        public ?string $granularity = null,
        public ?string $start = null,
        public ?string $end = null,
    ) {
    }

    public function handle(KlaviyoService $klaviyo, PeriodResolver $resolver): void
    {
        if (! $klaviyo->isConfigured()) {
            KlaviyoSetting::put('last_status', 'not_configured');
            KlaviyoSetting::put('last_error', 'Klaviyo API key or list id is not set.');

            return;
        }

        $runError = null;

        foreach ($this->buckets($resolver) as [$granularity, $start, $end]) {
            $key = ['granularity' => $granularity, 'period_start' => $start, 'period_end' => $end];

            try {
                // The API timeframe end is inclusive; our bucket end is exclusive.
                $values = $klaviyo->getCampaignValues($start, $end->copy()->subSecond());

                $attributes = $values + [
                    'status' => 'ok',
                    'error' => null,
                    'synced_at' => now(),
                ];

                // New email subscribers gained in this bucket (non-fatal if it fails).
                try {
                    $attributes['subscribers'] = $klaviyo->getNewSubscribers($start, $end);
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }

                // Subscription purchases / renewals attributed to campaigns (non-fatal).
                $apiEnd = $end->copy()->subSecond();
                try {
                    if ($created = $klaviyo->getConversionsForMetric((string) config('klaviyo.subscription_created_metric'), $start, $apiEnd)) {
                        $attributes['sub_created_conversions'] = $created['conversions'];
                        $attributes['sub_created_revenue'] = $created['value'];
                    }
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }
                try {
                    if ($renewal = $klaviyo->getConversionsForMetric((string) config('klaviyo.subscription_renewal_metric'), $start, $apiEnd)) {
                        $attributes['sub_renewal_conversions'] = $renewal['conversions'];
                        $attributes['sub_renewal_revenue'] = $renewal['value'];
                    }
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }

                // ---- Automated flows (mirror of campaigns) — all non-fatal ----
                try {
                    $flow = $klaviyo->getFlowValues($start, $apiEnd);
                    $attributes['flow_delivery_rate'] = $flow['delivery_rate'];
                    $attributes['flow_open_rate'] = $flow['open_rate'];
                    $attributes['flow_click_rate'] = $flow['click_rate'];
                    $attributes['flow_revenue'] = $flow['revenue'];
                    $attributes['flow_conversions'] = $flow['conversions'];
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }
                try {
                    if ($fc = $klaviyo->getConversionsForMetric((string) config('klaviyo.subscription_created_metric'), $start, $apiEnd, 'flow')) {
                        $attributes['flow_sub_created_conversions'] = $fc['conversions'];
                        $attributes['flow_sub_created_revenue'] = $fc['value'];
                    }
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }
                try {
                    if ($fr = $klaviyo->getConversionsForMetric((string) config('klaviyo.subscription_renewal_metric'), $start, $apiEnd, 'flow')) {
                        $attributes['flow_sub_renewal_conversions'] = $fr['conversions'];
                        $attributes['flow_sub_renewal_revenue'] = $fr['value'];
                    }
                } catch (Throwable $e) {
                    $runError = $e->getMessage();
                }

                KlaviyoMetric::updateOrCreate($key, $attributes);
            } catch (Throwable $e) {
                $runError = $e->getMessage();
                KlaviyoMetric::updateOrCreate($key, [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'synced_at' => now(),
                ]);
            }
        }

        KlaviyoSetting::put('last_status', $runError ? 'failed' : 'ok');
        KlaviyoSetting::put('last_error', $runError);
        KlaviyoSetting::put('last_synced_at', now()->toDateTimeString());
    }

    /**
     * @return array<int, array{0:string,1:Carbon,2:Carbon}>  [granularity, start, end(exclusive)]
     */
    private function buckets(PeriodResolver $resolver): array
    {
        if ($this->granularity && $this->start && $this->end) {
            return [[$this->granularity, Carbon::parse($this->start), Carbon::parse($this->end)]];
        }

        $now = now();
        $weekOfMonth = (int) ceil($now->day / 7);

        $periods = [
            'week' => $resolver->resolve(['granularity' => 'week', 'year' => $now->year, 'month' => $now->month, 'week' => $weekOfMonth]),
            'month' => $resolver->resolve(['granularity' => 'month', 'year' => $now->year, 'month' => $now->month]),
            'year' => $resolver->resolve(['granularity' => 'year', 'year' => $now->year]),
        ];

        return collect($periods)->map(fn ($p, $g) => [
            $g,
            Carbon::parse($p->start->toDateTimeString()),
            Carbon::parse($p->end->toDateTimeString()),
        ])->values()->all();
    }

    public function failed(Throwable $e): void
    {
        KlaviyoSetting::put('last_status', 'failed');
        KlaviyoSetting::put('last_error', $e->getMessage());
    }
}
