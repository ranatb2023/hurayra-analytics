<?php

namespace Tests\Feature;

use App\Models\KlaviyoMetric;
use App\Services\KlaviyoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KlaviyoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config()->set('klaviyo.api_key', 'pk_test');
        config()->set('klaviyo.list_id', 'LIST123');
        config()->set('klaviyo.revision', '2025-01-15');
        config()->set('klaviyo.conversion_metric_name', 'Placed Order');
    }

    private function twoCampaignResults(): array
    {
        return [
            ['groupings' => ['campaign_id' => 'A'], 'statistics' => [
                'recipients' => 1000, 'delivered' => 950, 'opens_unique' => 400, 'clicks_unique' => 100,
                'conversion_value' => 500.50, 'conversions' => 20,
                'delivery_rate' => 0.95, 'open_rate' => 0.42, 'click_rate' => 0.105,
            ]],
            ['groupings' => ['campaign_id' => 'B'], 'statistics' => [
                'recipients' => 1000, 'delivered' => 800, 'opens_unique' => 200, 'clicks_unique' => 50,
                'conversion_value' => 300.00, 'conversions' => 10,
                'delivery_rate' => 0.80, 'open_rate' => 0.25, 'click_rate' => 0.0625,
            ]],
        ];
    }

    public function test_aggregate_recomputes_rates_from_summed_counts(): void
    {
        $agg = (new KlaviyoService)->aggregate($this->twoCampaignResults());

        // delivered 1750 / recipients 2000
        $this->assertSame(87.5, $agg['delivery_rate']);
        // opens_unique 600 / delivered 1750
        $this->assertSame(34.3, $agg['open_rate']);
        // clicks_unique 150 / delivered 1750
        $this->assertSame(8.6, $agg['click_rate']);
        $this->assertSame(800.5, $agg['revenue']);
        $this->assertSame(30, $agg['conversions']);
    }

    public function test_get_campaign_values_resolves_metric_id_then_aggregates(): void
    {
        $this->configure();

        Http::fake([
            'a.klaviyo.com/api/metrics/*' => Http::response([
                'data' => [['id' => 'PLACED_ORDER_ID', 'attributes' => ['name' => 'Placed Order']]],
                'links' => ['next' => null],
            ], 200),
            'a.klaviyo.com/api/campaign-values-reports/*' => Http::response([
                'data' => ['attributes' => ['results' => $this->twoCampaignResults()]],
            ], 200),
        ]);

        $values = (new KlaviyoService)->getCampaignValues(
            Carbon::parse('2026-05-01 00:00:00'),
            Carbon::parse('2026-05-31 23:59:59'),
        );

        $this->assertSame(87.5, $values['delivery_rate']);
        $this->assertSame(800.5, $values['revenue']);

        // The conversion metric id must be passed in the report request body.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'campaign-values-reports')
                && data_get($request->data(), 'data.attributes.conversion_metric_id') === 'PLACED_ORDER_ID';
        });
    }

    public function test_get_conversions_for_metric_resolves_by_name_and_sums(): void
    {
        $this->configure();

        Http::fake([
            'a.klaviyo.com/api/metrics/*' => Http::response([
                'data' => [['id' => 'SUBID', 'attributes' => ['name' => 'WC Subscription Created']]],
                'links' => ['next' => null],
            ], 200),
            'a.klaviyo.com/api/campaign-values-reports/*' => Http::response([
                'data' => ['attributes' => ['results' => [
                    ['statistics' => ['conversions' => 25, 'conversion_value' => 600.00]],
                    ['statistics' => ['conversions' => 17, 'conversion_value' => 365.59]],
                ]]],
            ], 200),
        ]);

        $result = (new KlaviyoService)->getConversionsForMetric(
            'WC Subscription Created',
            Carbon::parse('2026-01-01 00:00:00'),
            Carbon::parse('2026-12-31 23:59:59'),
        );

        $this->assertSame(42, $result['conversions']);
        $this->assertSame(965.59, $result['value']);
    }

    public function test_get_conversions_for_metric_returns_null_when_metric_absent(): void
    {
        $this->configure();
        Http::fake([
            'a.klaviyo.com/api/metrics/*' => Http::response(['data' => [], 'links' => ['next' => null]], 200),
        ]);

        $this->assertNull((new KlaviyoService)->getConversionsForMetric('Nonexistent', now()->subMonth(), now()));
    }

    public function test_get_flow_values_hits_the_flow_report_endpoint(): void
    {
        $this->configure();

        Http::fake([
            'a.klaviyo.com/api/metrics/*' => Http::response([
                'data' => [['id' => 'PLACED_ORDER_ID', 'attributes' => ['name' => 'Placed Order']]],
                'links' => ['next' => null],
            ], 200),
            'a.klaviyo.com/api/flow-values-reports/*' => Http::response([
                'data' => ['attributes' => ['results' => $this->twoCampaignResults()]],
            ], 200),
        ]);

        $values = (new KlaviyoService)->getFlowValues(
            Carbon::parse('2026-05-01 00:00:00'),
            Carbon::parse('2026-05-31 23:59:59'),
        );

        $this->assertSame(87.5, $values['delivery_rate']);
        $this->assertSame(800.5, $values['revenue']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'flow-values-reports'));
    }

    public function test_get_subscriber_count_reads_profile_count(): void
    {
        $this->configure();

        Http::fake([
            'a.klaviyo.com/api/lists/*' => Http::response([
                'data' => ['attributes' => ['profile_count' => 4200]],
            ], 200),
        ]);

        $this->assertSame(4200, (new KlaviyoService)->getSubscriberCount());
    }

    public function test_get_new_subscribers_counts_joined_in_period_across_pages(): void
    {
        $this->configure();

        Http::fake([
            'a.klaviyo.com/api/lists/*/profiles*' => Http::sequence()
                ->push(['data' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
                    'links' => ['next' => 'https://a.klaviyo.com/api/lists/LIST123/profiles/?page%5Bcursor%5D=abc']], 200)
                ->push(['data' => [['id' => '4'], ['id' => '5']], 'links' => ['next' => null]], 200),
        ]);

        $count = (new KlaviyoService)->getNewSubscribers(
            Carbon::parse('2026-06-01 00:00:00'),
            Carbon::parse('2026-07-01 00:00:00'),
        );

        $this->assertSame(5, $count);

        // The join-date filter must be sent on the first request.
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'joined_group_at'));
    }

    public function test_tiles_endpoint_refreshes_stale_snapshot_missing_subscription_fields(): void
    {
        $this->configure();

        // A snapshot from before subscription attribution existed (null sub fields).
        KlaviyoMetric::create([
            'granularity' => 'month', 'period_start' => '2026-06-01 00:00:00', 'period_end' => '2026-07-01 00:00:00',
            'delivery_rate' => 90, 'open_rate' => 40, 'click_rate' => 5, 'revenue' => 100, 'conversions' => 5,
            'subscribers' => 20, 'sub_created_conversions' => null, 'status' => 'ok', 'synced_at' => now(),
        ]);

        // It should be treated as stale → state "syncing" (and still expose what we have).
        $this->getJson('/api/klaviyo/tiles?granularity=month&year=2026&month=6')
            ->assertOk()
            ->assertJson(['state' => 'syncing', 'tiles' => ['revenue' => 100]]);
    }

    public function test_tiles_endpoint_reports_not_configured_without_credentials(): void
    {
        config()->set('klaviyo.api_key', null);
        config()->set('klaviyo.list_id', null);

        $this->getJson('/api/klaviyo/tiles?granularity=month&year=2026&month=6')
            ->assertOk()
            ->assertJson(['state' => 'not_configured', 'configured' => false]);
    }

    public function test_tiles_endpoint_returns_stored_snapshot(): void
    {
        $this->configure();

        KlaviyoMetric::create([
            'granularity' => 'month',
            'period_start' => '2026-06-01 00:00:00',
            'period_end' => '2026-07-01 00:00:00',
            'delivery_rate' => 87.5, 'open_rate' => 34.3, 'click_rate' => 8.6,
            'revenue' => 800.50, 'conversions' => 30, 'subscribers' => 4200,
            'sub_created_conversions' => 12, 'sub_created_revenue' => 240.00,
            'sub_renewal_conversions' => 8, 'sub_renewal_revenue' => 160.00,
            'flow_delivery_rate' => 99.0, 'flow_open_rate' => 39.5, 'flow_click_rate' => 2.1,
            'flow_revenue' => 3012.28, 'flow_conversions' => 134,
            'status' => 'ok', 'synced_at' => now(),
        ]);

        $this->getJson('/api/klaviyo/tiles?granularity=month&year=2026&month=6')
            ->assertOk()
            ->assertJson([
                'state' => 'ok',
                'tiles' => ['delivery_rate' => 87.5, 'revenue' => 800.5, 'subscribers' => 4200],
            ]);
    }
}
