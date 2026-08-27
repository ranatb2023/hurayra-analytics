<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenure-at-churn: how long the subscribers lost in a period had lasted.
 *
 * The property under test is that this splits exactly the same population the
 * churn rate counts — the bucket total must always equal `churned_in_period`,
 * or the panel would be describing a different set of people than the headline
 * number sitting above it.
 */
class TenureAtChurnTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null): void
    {
        Record::create([
            'id' => $id,
            'import_id' => null,
            'record_type' => 'shop_subscription',
            'status' => $status,
            'date_created_gmt' => $created,
            'ended_at' => $ended,
            'total_amount' => 0,
            'subscription_id' => null,
            'order_relationship' => null,
            'billing_email' => null,
        ]);
    }

    private function june(): array
    {
        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    private function seedJuneLeavers(): void
    {
        // Four subscriptions ending in June, one per bucket boundary.
        $this->sub(1, 'cancelled', '2026-05-20 00:00:00', '2026-06-10 00:00:00'); // 21d  -> 0-30
        $this->sub(2, 'cancelled', '2026-04-15 00:00:00', '2026-06-10 00:00:00'); // 56d  -> 31-60
        $this->sub(3, 'expired', '2026-02-01 00:00:00', '2026-06-15 00:00:00');   // 134d -> 91-180
        $this->sub(4, 'cancelled', '2025-06-01 00:00:00', '2026-06-20 00:00:00'); // 384d -> 181+
    }

    public function test_leavers_are_bucketed_by_how_long_they_had_been_subscribed(): void
    {
        $this->seedJuneLeavers();

        $tenure = $this->june()['tenure_at_churn'];
        $counts = array_column($tenure['buckets'], 'count', 'label');

        $this->assertSame([1, 1, 0, 1, 1], array_values($counts));
        $this->assertSame(4, $tenure['total']);
    }

    public function test_the_buckets_add_up_to_the_period_churn_count(): void
    {
        $this->seedJuneLeavers();

        // Somebody who ends in July must not leak into June's tenure panel.
        $this->sub(5, 'cancelled', '2026-01-01 00:00:00', '2026-07-04 00:00:00');
        // Nor may a subscription that is still running.
        $this->sub(6, 'active', '2026-01-01 00:00:00');

        $june = $this->june();

        $this->assertSame(
            $june['churned_in_period'],
            array_sum(array_column($june['tenure_at_churn']['buckets'], 'count')),
        );
        $this->assertSame(4, $june['churned_in_period']);
    }

    public function test_median_averages_the_two_middles_on_an_even_count(): void
    {
        $this->seedJuneLeavers();

        // Tenures are 21, 56, 134, 384 -> middles 56 and 134.
        $this->assertSame(95, $this->june()['tenure_at_churn']['median_days']);
    }

    public function test_shares_are_expressed_against_the_leavers_not_all_subscribers(): void
    {
        $this->seedJuneLeavers();
        // A long-standing active subscriber is no part of the denominator.
        $this->sub(7, 'active', '2024-01-01 00:00:00');

        $pcts = array_column($this->june()['tenure_at_churn']['buckets'], 'pct');

        $this->assertSame(100.0, array_sum($pcts));
    }

    public function test_a_period_with_no_churn_reports_empty_buckets_not_a_missing_panel(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');

        $tenure = $this->june()['tenure_at_churn'];

        $this->assertSame(0, $tenure['total']);
        $this->assertNull($tenure['median_days']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($tenure['buckets'], 'count'));
    }

    public function test_tenure_falls_back_to_the_last_linked_order_without_an_end_date(): void
    {
        // No `ended_at`: the last order is the most recent proof it was live.
        $this->sub(1, 'cancelled', '2026-05-01 00:00:00');
        Record::create([
            'id' => 100,
            'import_id' => null,
            'record_type' => 'shop_order',
            'status' => 'completed',
            'date_created_gmt' => '2026-06-12 00:00:00',
            'ended_at' => null,
            'total_amount' => 10,
            'subscription_id' => 1,
            'order_relationship' => 'renewal',
            'billing_email' => null,
        ]);

        $tenure = $this->june()['tenure_at_churn'];

        // 1 May -> 12 June is 42 days, so the 31-60 bucket.
        $this->assertSame(1, $tenure['total']);
        $this->assertSame(42, $tenure['median_days']);
        $this->assertSame([0, 1, 0, 0, 0], array_column($tenure['buckets'], 'count'));
    }
}
