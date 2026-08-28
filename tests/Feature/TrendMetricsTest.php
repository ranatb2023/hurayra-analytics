<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trend engine: money net of VAT and refunds, ratios, and splitting any
 * metric by an attribution column.
 *
 * A count is the only thing a single aggregate can answer. An average order
 * value, a renewal success rate and "what the business actually kept" each
 * need more than one, which is what the metric specs exist to express — and
 * each of them has a null case that must plot as a gap rather than a zero.
 */
class TrendMetricsTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function order(int $id, string $created, array $attrs = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => 'completed', 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'one_time', 'billing_email' => null,
        ], $attrs));
    }

    /** @return array<string, ?float> month => value */
    private function series(string $metric): array
    {
        $trend = $this->metrics->trend($metric, 'month');

        return array_combine($trend['labels'], $trend['values']);
    }

    // ------------------------------------------------------------ net revenue

    public function test_net_revenue_takes_out_vat_shipping_and_refunds(): void
    {
        // Gross 120: 100 net + 15 VAT + 5 shipping. 30 of it refunded.
        $this->order(1, '2026-03-04 00:00:00', [
            'total_amount' => 120, 'net_amount' => 100, 'tax_amount' => 15,
            'shipping_amount' => 5, 'refunded_amount' => 30,
        ]);

        $this->assertSame(120.0, $this->series('total_revenue')['2026-03']);
        $this->assertSame(70.0, $this->series('net_revenue')['2026-03']);
    }

    public function test_net_revenue_is_a_gap_not_gross_when_the_export_predates_the_column(): void
    {
        // A file exported before net_amount existed. Falling back to gross here
        // would restore the ~24% overstatement the metric exists to remove.
        $this->order(1, '2026-03-04 00:00:00', ['total_amount' => 120]);

        $this->assertNull($this->series('net_revenue')['2026-03']);
        $this->assertSame(120.0, $this->series('total_revenue')['2026-03']);
    }

    public function test_refunds_are_reported_as_their_own_positive_series(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['total_amount' => 120, 'refunded_amount' => 30]);

        $this->assertSame(30.0, $this->series('refunded')['2026-03']);
    }

    // ------------------------------------------------------------------ ratios

    public function test_average_order_value_is_revenue_over_completed_orders(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['total_amount' => 30]);
        $this->order(2, '2026-03-09 00:00:00', ['total_amount' => 20]);
        // Not completed: outside both halves of the average.
        $this->order(3, '2026-03-11 00:00:00', ['total_amount' => 900, 'status' => 'failed']);

        $this->assertSame(25.0, $this->series('average_order_value')['2026-03']);
    }

    public function test_renewal_success_rate_is_completed_renewals_over_all_renewals(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['order_relationship' => 'renewal']);
        $this->order(2, '2026-03-05 00:00:00', ['order_relationship' => 'renewal']);
        $this->order(3, '2026-03-06 00:00:00', ['order_relationship' => 'renewal', 'status' => 'failed']);
        $this->order(4, '2026-03-07 00:00:00', ['order_relationship' => 'renewal', 'status' => 'pending']);

        $this->assertSame(50.0, $this->series('renewal_success_rate')['2026-03']);
    }

    public function test_a_month_with_no_renewals_is_a_gap_not_a_total_failure(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['order_relationship' => 'renewal']);
        // April has orders but no renewals, so it has no renewal success rate.
        $this->order(2, '2026-04-04 00:00:00', ['order_relationship' => 'one_time']);

        $rate = $this->series('renewal_success_rate');

        $this->assertSame(100.0, $rate['2026-03']);
        $this->assertArrayNotHasKey('2026-04', $rate);
    }

    // ---------------------------------------------------------------- hygiene

    public function test_trashed_orders_are_left_out_the_way_the_cards_leave_them_out(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['total_amount' => 50]);
        $this->order(2, '2026-03-05 00:00:00', ['total_amount' => 999, 'status' => 'trash']);

        $this->assertSame(50.0, $this->series('total_revenue')['2026-03']);
        $this->assertSame(1, $this->series('completed')['2026-03']);
    }

    public function test_an_unknown_metric_falls_back_rather_than_erroring(): void
    {
        $this->order(1, '2026-03-04 00:00:00');

        $this->assertSame('new_subscribers', $this->metrics->trend('nonsense', 'month')['metric']);
    }

    // -------------------------------------------------------------- breakdown

    public function test_a_split_returns_one_series_per_segment(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['total_amount' => 10, 'utm_source' => 'google']);
        $this->order(2, '2026-03-05 00:00:00', ['total_amount' => 20, 'utm_source' => 'google']);
        $this->order(3, '2026-03-06 00:00:00', ['total_amount' => 5, 'utm_source' => 'fb']);
        $this->order(4, '2026-04-02 00:00:00', ['total_amount' => 40, 'utm_source' => 'google']);

        $trend = $this->metrics->trend('total_revenue', 'month', null, 'utm_source');

        $this->assertSame('utm_source', $trend['breakdown']);
        $this->assertSame(['2026-03', '2026-04'], $trend['labels']);

        $series = array_column($trend['series'], 'values', 'label');

        $this->assertSame([30.0, 40.0], $series['google']);
        // April has no Facebook orders: a sum of nothing really is zero.
        $this->assertSame([5.0, 0.0], $series['fb']);
    }

    public function test_rows_with_no_source_are_labelled_rather_than_dropped(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['utm_source' => 'google']);
        $this->order(2, '2026-03-05 00:00:00', ['utm_source' => null]);
        $this->order(3, '2026-03-06 00:00:00', ['utm_source' => '']);

        $labels = array_column($this->metrics->trend('completed', 'month', null, 'utm_source')['series'], 'label');

        $this->assertContains('Not recorded', $labels);
        // Both the null and the empty string land in the one segment.
        $series = array_column($this->metrics->trend('completed', 'month', null, 'utm_source')['series'], 'values', 'label');
        $this->assertSame([2], $series['Not recorded']);
    }

    public function test_a_split_caps_the_lines_but_reports_what_it_left_out(): void
    {
        foreach (range(1, 9) as $i) {
            $this->order($i, '2026-03-0'.$i.' 00:00:00', ['utm_source' => 'source-'.$i]);
        }

        $trend = $this->metrics->trend('completed', 'month', null, 'utm_source');

        $this->assertCount(6, $trend['series']);
        $this->assertSame(3, $trend['other_segments']);
    }

    public function test_a_split_ratio_is_computed_per_segment_not_pooled(): void
    {
        // Google: 2 renewals, 1 completed. Facebook: 1 renewal, completed.
        $this->order(1, '2026-03-04 00:00:00', ['order_relationship' => 'renewal', 'utm_source' => 'google']);
        $this->order(2, '2026-03-05 00:00:00', ['order_relationship' => 'renewal', 'utm_source' => 'google', 'status' => 'failed']);
        $this->order(3, '2026-03-06 00:00:00', ['order_relationship' => 'renewal', 'utm_source' => 'fb']);

        $series = array_column(
            $this->metrics->trend('renewal_success_rate', 'month', null, 'utm_source')['series'],
            'values',
            'label',
        );

        $this->assertSame([50.0], $series['google']);
        $this->assertSame([100.0], $series['fb']);
    }

    public function test_an_unknown_breakdown_column_is_ignored_not_interpolated(): void
    {
        $this->order(1, '2026-03-04 00:00:00');

        $trend = $this->metrics->trend('completed', 'month', null, 'id; DROP TABLE records');

        $this->assertNull($trend['breakdown']);
        $this->assertSame([], $trend['series']);
    }

    // --------------------------------------------------------------- endpoint

    public function test_endpoint_returns_a_split_trend(): void
    {
        $this->order(1, '2026-03-04 00:00:00', ['utm_source' => 'google']);

        $this->getJson('/api/metrics/trend?metric=completed&granularity=month&breakdown=utm_source')
            ->assertOk()
            ->assertJsonPath('breakdown', 'utm_source')
            ->assertJsonPath('unit', 'count')
            ->assertJsonPath('series.0.label', 'google');
    }

    public function test_endpoint_rejects_a_breakdown_column_that_is_not_a_segment(): void
    {
        $this->getJson('/api/metrics/trend?metric=completed&granularity=month&breakdown=billing_email')
            ->assertStatus(422);
    }
}
