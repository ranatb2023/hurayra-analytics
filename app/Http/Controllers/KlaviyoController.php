<?php

namespace App\Http\Controllers;

use App\Jobs\SyncKlaviyoMetrics;
use App\Models\KlaviyoMetric;
use App\Services\KlaviyoService;
use App\Support\Period;
use App\Support\PeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KlaviyoController extends Controller
{
    public function __construct(
        private readonly KlaviyoService $klaviyo,
        private readonly PeriodResolver $resolver,
    ) {
    }

    /** Tiles for the active filter — reads the stored snapshot, never calls Klaviyo live. */
    public function tiles(Request $request): JsonResponse
    {
        $period = $this->resolver->resolve($this->validateFilter($request));
        $snapshot = $this->findSnapshot($period);

        $base = [
            'configured' => $this->klaviyo->isConfigured(),
            'revision' => config('klaviyo.revision'),
            'period' => $period->toArray(),
        ];

        // A complete, successful snapshot — serve it directly.
        if ($snapshot && $this->isComplete($snapshot)) {
            return response()->json($base + [
                'state' => 'ok',
                'error' => $snapshot->error,
                'synced_at' => optional($snapshot->synced_at)->toIso8601String(),
                'tiles' => $this->tilesFrom($snapshot),
            ]);
        }

        if (! $this->klaviyo->isConfigured()) {
            return response()->json($base + ['state' => 'not_configured']);
        }

        // Missing, failed, or stale (pre-subscription-columns) snapshot — (re)sync it.
        $this->dispatchSync($period);

        $payload = $base + ['state' => 'syncing'];
        if ($snapshot) {
            // Show whatever we already have while the refresh runs.
            $payload['tiles'] = $this->tilesFrom($snapshot);
            $payload['synced_at'] = optional($snapshot->synced_at)->toIso8601String();
        }

        return response()->json($payload);
    }

    /** A snapshot is usable as-is only if it succeeded and isn't missing newer fields. */
    private function isComplete(KlaviyoMetric $s): bool
    {
        if ($s->status !== 'ok') {
            return false;
        }

        // If subscription attribution is configured, a snapshot predating it
        // (null sub fields) is stale and should be refreshed.
        if (filled(config('klaviyo.subscription_created_metric')) && $s->sub_created_conversions === null) {
            return false;
        }

        // Snapshot predating flow metrics is also stale.
        if ($s->flow_delivery_rate === null) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function tilesFrom(KlaviyoMetric $s): array
    {
        return [
            'delivery_rate' => $s->delivery_rate,
            'open_rate' => $s->open_rate,
            'click_rate' => $s->click_rate,
            'revenue' => (float) $s->revenue,
            'conversions' => $s->conversions,
            'subscribers' => $s->subscribers,
            'sub_created_conversions' => $s->sub_created_conversions,
            'sub_created_revenue' => $s->sub_created_revenue !== null ? (float) $s->sub_created_revenue : null,
            'sub_renewal_conversions' => $s->sub_renewal_conversions,
            'sub_renewal_revenue' => $s->sub_renewal_revenue !== null ? (float) $s->sub_renewal_revenue : null,
            // Flows
            'flow_delivery_rate' => $s->flow_delivery_rate,
            'flow_open_rate' => $s->flow_open_rate,
            'flow_click_rate' => $s->flow_click_rate,
            'flow_revenue' => $s->flow_revenue !== null ? (float) $s->flow_revenue : null,
            'flow_conversions' => $s->flow_conversions,
            'flow_sub_created_conversions' => $s->flow_sub_created_conversions,
            'flow_sub_created_revenue' => $s->flow_sub_created_revenue !== null ? (float) $s->flow_sub_created_revenue : null,
            'flow_sub_renewal_conversions' => $s->flow_sub_renewal_conversions,
            'flow_sub_renewal_revenue' => $s->flow_sub_renewal_revenue !== null ? (float) $s->flow_sub_renewal_revenue : null,
        ];
    }

    /** Manual "Refresh now" — dispatches a sync for the active bucket. */
    public function refresh(Request $request): JsonResponse
    {
        $period = $this->resolver->resolve($this->validateFilter($request));

        if (! $this->klaviyo->isConfigured()) {
            return response()->json(['state' => 'not_configured'], 422);
        }

        $this->dispatchSync($period);

        return response()->json(['state' => 'syncing'], 202);
    }

    private function findSnapshot(Period $period): ?KlaviyoMetric
    {
        return KlaviyoMetric::query()
            ->where('granularity', $period->granularity)
            ->where('period_start', $period->start->toDateTimeString())
            ->where('period_end', $period->end->toDateTimeString())
            ->first();
    }

    private function dispatchSync(Period $period): void
    {
        SyncKlaviyoMetrics::dispatch(
            $period->granularity,
            $period->start->toDateTimeString(),
            $period->end->toDateTimeString(),
        );
    }

    /** @return array<string, mixed> */
    private function validateFilter(Request $request): array
    {
        return $request->validate([
            'granularity' => ['required', 'in:week,month,year,custom'],
            'year' => ['nullable', 'integer', 'min:1970', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'week' => ['nullable', 'integer', 'min:1', 'max:6'],
            'from' => ['nullable', 'date', 'required_if:granularity,custom'],
            'to' => ['nullable', 'date', 'required_if:granularity,custom'],
        ]);
    }
}
