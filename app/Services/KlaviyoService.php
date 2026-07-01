<?php

namespace App\Services;

use App\Models\KlaviyoSetting;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin, self-contained client for the Klaviyo Reporting API.
 *
 * Auth + headers are centralised in {@see client()}. Every request retries with
 * exponential backoff on 429 / 5xx and honours the Retry-After header.
 *
 * Public surface:
 *   - getConversionMetricId(): string   (resolves & caches the "Placed Order" id)
 *   - getCampaignValues($start, $end): array  (aggregated tile stats)
 *   - getSubscriberCount(): int
 */
class KlaviyoService
{
    private const CAMPAIGN_STATISTICS = [
        // tile stats…
        'delivery_rate', 'open_rate', 'click_rate', 'conversion_value', 'conversions',
        // …plus underlying counts so rates can be recomputed (weighted) rather than averaged.
        'recipients', 'delivered', 'opens_unique', 'clicks_unique',
    ];

    public function isConfigured(): bool
    {
        return ! empty(config('klaviyo.api_key')) && ! empty(config('klaviyo.list_id'));
    }

    /**
     * The timezone used to build report timeframes. Prefers an explicit config
     * override, else the Klaviyo account timezone (cached), else UTC. This makes
     * period boundaries line up with the Klaviyo UI.
     */
    public function reportTimezone(): string
    {
        if (filled(config('klaviyo.timezone'))) {
            return (string) config('klaviyo.timezone');
        }

        if ($cached = KlaviyoSetting::get('account_timezone')) {
            return $cached;
        }

        try {
            $response = $this->client()->get('/api/accounts/');
            if ($response->successful() && ($tz = $response->json('data.0.attributes.timezone'))) {
                KlaviyoSetting::put('account_timezone', $tz);

                return $tz;
            }
        } catch (\Throwable) {
            // fall through to default
        }

        return config('app.timezone') ?: 'UTC';
    }

    /**
     * Format a period boundary as ISO-8601 in the account timezone — the
     * wall-clock is preserved and stamped with the account's offset
     * (e.g. 2026-06-01 00:00 → 2026-06-01T00:00:00+01:00 for Europe/London).
     */
    private function iso(CarbonInterface $dt): string
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $dt->format('Y-m-d H:i:s'), $this->reportTimezone())
            ->toIso8601String();
    }

    /** A pre-configured HTTP client (base URL, auth, pinned revision, retry/backoff). */
    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('klaviyo.base_url'), '/'))
            ->withHeaders([
                'Authorization' => 'Klaviyo-API-Key '.config('klaviyo.api_key'),
                'revision' => config('klaviyo.revision'),
                'accept' => 'application/vnd.api+json',
                'content-type' => 'application/vnd.api+json',
            ])
            ->retry(
                (int) config('klaviyo.retries', 4),
                // Backoff (ms): honour Retry-After when present, else exponential.
                function (int $attempt, $exception) {
                    $retryAfter = optional($exception->response ?? null)->header('Retry-After');

                    return $retryAfter !== null && $retryAfter !== ''
                        ? ((int) $retryAfter) * 1000
                        : (int) (1000 * (2 ** ($attempt - 1)));
                },
                // Only retry on connection errors and 429/5xx.
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    $status = optional($exception->response ?? null)->status();

                    return in_array($status, [429, 500, 502, 503, 504], true);
                },
                throw: false,
            );
    }

    /**
     * Resolve the "Placed Order" conversion metric id (cached).
     * Required to request revenue/conversions from the campaign-values report.
     */
    public function getConversionMetricId(bool $refresh = false): string
    {
        $id = $this->resolveMetricId((string) config('klaviyo.conversion_metric_name'), $refresh);

        if ($id === null) {
            throw new RuntimeException('Conversion metric "'.config('klaviyo.conversion_metric_name').'" not found in Klaviyo.');
        }

        return $id;
    }

    /**
     * Resolve any metric id by name (cached per name in klaviyo_settings).
     * Returns null if the name is blank or the metric doesn't exist.
     */
    public function resolveMetricId(string $name, bool $refresh = false): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $cacheKey = 'metric_id:'.strtolower($name);
        if (! $refresh && ($cached = KlaviyoSetting::get($cacheKey))) {
            return $cached;
        }

        $wanted = strtolower($name);
        $url = '/api/metrics/';

        while ($url) {
            $response = $this->client()->get($url);
            $this->assertOk($response, 'metrics');

            foreach ($response->json('data', []) as $metric) {
                if (strtolower(trim($metric['attributes']['name'] ?? '')) === $wanted) {
                    KlaviyoSetting::put($cacheKey, $metric['id']);

                    return $metric['id'];
                }
            }

            $url = $response->json('links.next');
        }

        return null;
    }

    /**
     * Conversions + value for an arbitrary conversion metric (by name), attributed
     * to campaigns over a date range. Used for subscription / renewal attribution.
     * Returns null if the metric doesn't exist in the account.
     *
     * @return array{conversions:int, value:float}|null
     */
    public function getConversionsForMetric(string $metricName, CarbonInterface $start, CarbonInterface $end, string $channel = 'campaign'): ?array
    {
        $metricId = $this->resolveMetricId($metricName);
        if ($metricId === null) {
            return null;
        }

        $conversions = 0.0;
        $value = 0.0;
        foreach ($this->valuesReport($channel, ['conversions', 'conversion_value'], $start, $end, $metricId) as $row) {
            $conversions += (float) ($row['statistics']['conversions'] ?? 0);
            $value += (float) ($row['statistics']['conversion_value'] ?? 0);
        }

        return ['conversions' => (int) round($conversions), 'value' => round($value, 2)];
    }

    /**
     * Aggregated campaign stats for a date range, ready for the tiles.
     *
     * @return array{delivery_rate: float, open_rate: float, click_rate: float, revenue: float, conversions: int}
     */
    public function getCampaignValues(CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->aggregate($this->valuesReport('campaign', self::CAMPAIGN_STATISTICS, $start, $end, $this->getConversionMetricId()));
    }

    /** Same six tile stats, aggregated across automated flows instead of campaigns. */
    public function getFlowValues(CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->aggregate($this->valuesReport('flow', self::CAMPAIGN_STATISTICS, $start, $end, $this->getConversionMetricId()));
    }

    /**
     * Run a campaign- or flow-values report and return the per-row results.
     *
     * @param  'campaign'|'flow'  $channel
     * @return array<int, array{statistics?: array<string, mixed>}>
     */
    private function valuesReport(string $channel, array $statistics, CarbonInterface $start, CarbonInterface $end, string $conversionMetricId): array
    {
        $type = $channel === 'flow' ? 'flow-values-report' : 'campaign-values-report';
        $endpoint = "/api/{$type}s/";

        $response = $this->client()->post($endpoint, ['data' => [
            'type' => $type,
            'attributes' => [
                'statistics' => $statistics,
                'timeframe' => [
                    'start' => $this->iso($start),
                    'end' => $this->iso($end),
                ],
                'conversion_metric_id' => $conversionMetricId,
            ],
        ]]);
        $this->assertOk($response, $type);

        return $response->json('data.attributes.results', []);
    }

    /**
     * Aggregate per-campaign rows into single tile values.
     * Rates are recomputed from summed counts (weighted), never averaged.
     *
     * @param  array<int, array{statistics?: array<string, mixed>}>  $results
     */
    public function aggregate(array $results): array
    {
        $sum = ['recipients' => 0.0, 'delivered' => 0.0, 'opens_unique' => 0.0, 'clicks_unique' => 0.0,
            'conversion_value' => 0.0, 'conversions' => 0.0];
        // Fallback: recipient-weighted rate accumulation when raw counts are absent.
        $weightedRate = ['delivery_rate' => 0.0, 'open_rate' => 0.0, 'click_rate' => 0.0];
        $weight = 0.0;

        foreach ($results as $row) {
            $s = $row['statistics'] ?? [];
            foreach ($sum as $k => $_) {
                $sum[$k] += (float) ($s[$k] ?? 0);
            }
            $w = (float) ($s['recipients'] ?? $s['delivered'] ?? 0);
            $weight += $w;
            foreach ($weightedRate as $k => $_) {
                $weightedRate[$k] += ((float) ($s[$k] ?? 0)) * $w;
            }
        }

        $pct = fn (float $v) => round($v * 100, 1);

        // Prefer recomputing from counts; else recipient-weighted rates; else 0.
        $deliveryRate = $sum['recipients'] > 0
            ? $pct($sum['delivered'] / $sum['recipients'])
            : ($weight > 0 ? $pct($weightedRate['delivery_rate'] / $weight) : 0.0);

        $openRate = $sum['delivered'] > 0
            ? $pct($sum['opens_unique'] / $sum['delivered'])
            : ($weight > 0 ? $pct($weightedRate['open_rate'] / $weight) : 0.0);

        $clickRate = $sum['delivered'] > 0
            ? $pct($sum['clicks_unique'] / $sum['delivered'])
            : ($weight > 0 ? $pct($weightedRate['click_rate'] / $weight) : 0.0);

        return [
            'delivery_rate' => $deliveryRate,
            'open_rate' => $openRate,
            'click_rate' => $clickRate,
            'revenue' => round($sum['conversion_value'], 2),
            'conversions' => (int) round($sum['conversions']),
        ];
    }

    /**
     * Count profiles that JOINED the newsletter list within [start, end) — i.e.
     * new email subscribers gained in the selected period. Pages through the
     * list-profiles endpoint filtered on joined_group_at and counts the rows.
     */
    public function getNewSubscribers(CarbonInterface $start, CarbonInterface $end): int
    {
        $listId = config('klaviyo.list_id');
        $startIso = $this->iso($start);
        $endIso = $this->iso($end);
        $filter = "and(greater-or-equal(joined_group_at,{$startIso}),less-than(joined_group_at,{$endIso}))";

        $count = 0;
        $page = 0;
        $maxPages = 500; // safety cap (≈50k profiles)
        $url = "/api/lists/{$listId}/profiles/";
        $query = ['filter' => $filter, 'page[size]' => 100];

        while ($url && $page < $maxPages) {
            // The first request carries the query; subsequent links.next already include it.
            $response = $page === 0 ? $this->client()->get($url, $query) : $this->client()->get($url);
            $this->assertOk($response, 'list-profiles');

            $count += count($response->json('data', []));
            $url = $response->json('links.next');
            $page++;
        }

        return $count;
    }

    /** Current total subscriber count for the configured newsletter list. */
    public function getSubscriberCount(): int
    {
        $listId = config('klaviyo.list_id');

        // Preferred: ask for the profile_count additional field on the list object.
        $response = $this->client()->get("/api/lists/{$listId}/", [
            'additional-fields[list]' => 'profile_count',
        ]);
        $this->assertOk($response, 'list');

        $count = $response->json('data.attributes.profile_count');
        if ($count !== null) {
            return (int) $count;
        }

        // Fallback: count via the profiles relationship page meta.
        $fallback = $this->client()->get("/api/lists/{$listId}/profiles/", ['page[size]' => 1]);
        $this->assertOk($fallback, 'list-profiles');

        return (int) ($fallback->json('data.meta.total')
            ?? $fallback->json('meta.total')
            ?? count($fallback->json('data', [])));
    }

    private function assertOk($response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('errors.0.detail') ?? $response->json('message') ?? $response->body();

        throw new RuntimeException("Klaviyo {$context} request failed (HTTP {$response->status()}): {$detail}");
    }
}
