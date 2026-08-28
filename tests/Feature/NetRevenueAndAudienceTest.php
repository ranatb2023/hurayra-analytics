<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\CsvImportService;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Net revenue, refunds, status whitelisting, customer-level churn and the
 * campaign audiences.
 *
 * The theme is that several headline figures were quietly wrong: revenue was
 * gross and included money owed to HMRC, deleted orders counted as failed
 * business, and churn counted subscriptions when the business has customers.
 */
class NetRevenueAndAudienceTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function order(int $id, array $attrs = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => 'completed', 'date_created_gmt' => '2026-06-10 00:00:00', 'ended_at' => null,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'one_time', 'billing_email' => null,
        ], $attrs));
    }

    private function sub(int $id, string $status, string $created, array $attrs = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'subscription', 'billing_email' => null,
        ], $attrs));
    }

    private function june(): array
    {
        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    // ----------------------------------------------------------- net revenue

    public function test_revenue_is_reported_gross_and_net(): void
    {
        $this->order(1, ['total_amount' => 120, 'net_amount' => 90, 'tax_amount' => 20, 'shipping_amount' => 10]);

        $m = $this->june();

        $this->assertSame(120.0, $m['gross_revenue']);
        $this->assertSame(90.0, $m['net_revenue']);
        $this->assertSame(20.0, $m['tax_collected']);
        $this->assertSame(10.0, $m['shipping_collected']);
        $this->assertTrue($m['net_revenue_known']);
    }

    public function test_refunds_come_off_the_net_figure(): void
    {
        $this->order(1, ['total_amount' => 120, 'net_amount' => 90, 'tax_amount' => 20,
            'shipping_amount' => 10, 'refunded_amount' => 15]);

        $m = $this->june();

        $this->assertSame(15.0, $m['refunded']);
        $this->assertSame(75.0, $m['net_revenue_after_refunds']);
        // Gross is left alone so a previously published figure keeps its meaning.
        $this->assertSame(120.0, $m['gross_revenue']);
    }

    public function test_a_file_without_the_net_columns_reports_net_as_unknown(): void
    {
        $this->order(1, ['total_amount' => 120]);

        $m = $this->june();

        $this->assertFalse($m['net_revenue_known']);
        // Null, not the gross figure: presenting gross as net would be a lie.
        $this->assertNull($m['net_revenue']);
        $this->assertNull($m['net_revenue_after_refunds']);
    }

    public function test_contribution_is_null_until_a_margin_is_configured(): void
    {
        config(['metrics.gross_margin_pct' => null]);
        $this->order(1, ['total_amount' => 120, 'net_amount' => 100]);

        $this->assertNull($this->june()['contribution']);
    }

    public function test_contribution_uses_the_configured_margin(): void
    {
        config(['metrics.gross_margin_pct' => 60]);
        $this->order(1, ['total_amount' => 120, 'net_amount' => 100, 'refunded_amount' => 0]);

        $m = $this->june();

        $this->assertSame(60.0, $m['gross_margin_pct']);
        $this->assertSame(60.0, $m['contribution']);
    }

    // ------------------------------------------------------ status whitelist

    public function test_deleted_orders_are_excluded_entirely(): void
    {
        $this->order(1, ['total_amount' => 50]);                      // completed
        $this->order(2, ['status' => 'trash', 'total_amount' => 500]); // deleted
        $this->order(3, ['status' => 'failed', 'total_amount' => 20]);

        $m = $this->june();

        // Trash used to be swept into "not completed" simply because it is not
        // 'completed' — 26 deleted orders worth GBP 3,099 on the real data.
        $this->assertSame(1, $m['new_not_completed']);
        $this->assertSame(50.0, $m['gross_revenue']);
    }

    public function test_an_unrecognised_status_is_surfaced_not_absorbed(): void
    {
        $this->order(1, ['status' => 'awaiting-shipment', 'total_amount' => 10]);

        $m = $this->june();

        $this->assertSame(['awaiting-shipment' => 1], $m['unrecognised_statuses']);
        // Counted nowhere silently.
        $this->assertSame(0, $m['new_not_completed']);
    }

    // --------------------------------------------------- customer-level churn

    public function test_a_customer_with_another_live_subscription_has_not_churned(): void
    {
        $this->sub(1, 'cancelled', '2026-01-01 00:00:00', [
            'billing_email' => 'both@example.com', 'ended_at' => '2026-06-15 00:00:00',
        ]);
        $this->sub(2, 'active', '2026-01-01 00:00:00', ['billing_email' => 'both@example.com']);

        $m = $this->june();

        $this->assertSame(1, $m['churned_in_period']);   // one subscription left
        $this->assertSame(0, $m['customers_churned']);   // nobody actually left
        $this->assertSame(0.0, $m['customer_churn_rate']);
    }

    public function test_a_customer_counts_as_churned_once_everything_has_ended(): void
    {
        $this->sub(1, 'cancelled', '2026-01-01 00:00:00', [
            'billing_email' => 'gone@example.com', 'ended_at' => '2026-06-10 00:00:00',
        ]);
        $this->sub(2, 'cancelled', '2026-01-01 00:00:00', [
            'billing_email' => 'gone@example.com', 'ended_at' => '2026-06-20 00:00:00',
        ]);

        $m = $this->june();

        $this->assertSame(2, $m['churned_in_period']);
        $this->assertSame(1, $m['customers_active_at_start']);
        $this->assertSame(1, $m['customers_churned']);
    }

    public function test_customers_are_deduplicated_on_billing_email(): void
    {
        // Same person, two WooCommerce customer ids.
        $this->order(1, ['billing_email' => 'one@example.com', 'customer_id' => 5, 'total_amount' => 10]);
        $this->order(2, ['billing_email' => 'one@example.com', 'customer_id' => 9, 'total_amount' => 10]);

        $m = $this->june();

        $this->assertSame(2, $m['unique_customers']);          // by customer_id
        $this->assertSame(1, $m['unique_customers_deduped']);  // by email
    }

    // ------------------------------------------------------- segment reliability

    public function test_a_small_segment_is_flagged_with_its_interval(): void
    {
        config(['metrics.segment_min_reliable_n' => 30]);

        foreach (range(1, 4) as $i) {
            $this->sub($i, 'active', '2026-01-01 00:00:00', ['utm_source' => 'tiny']);
            $this->order(100 + $i, ['subscription_id' => $i, 'total_amount' => 10, 'order_relationship' => 'renewal']);
        }

        $row = $this->metrics->segmentPerformance('utm_source', 1)['rows'][0];

        $this->assertSame(4, $row['subs']);
        $this->assertFalse($row['reliable']);
        $this->assertGreaterThan(0, $row['repeat_margin']);
        $this->assertLessThanOrEqual($row['repeat_pct'], $row['repeat_low']);
        $this->assertGreaterThanOrEqual($row['repeat_pct'], $row['repeat_high']);
    }

    public function test_a_confidence_interval_never_leaves_the_zero_to_hundred_range(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['utm_source' => 'solo']);

        $row = $this->metrics->segmentPerformance('utm_source', 1)['rows'][0];

        $this->assertGreaterThanOrEqual(0, $row['repeat_low']);
        $this->assertLessThanOrEqual(100, $row['repeat_high']);
    }

    // ------------------------------------------------------------- audiences

    public function test_cross_sell_lists_single_flavour_subscribers_only(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['billing_email' => 'single@example.com']);
        $this->order(100, ['subscription_id' => 1, 'total_amount' => 20, 'order_relationship' => 'renewal',
            'primary_product' => 'Dry Chicken Cat Food']);

        $this->sub(2, 'active', '2026-01-01 00:00:00', ['billing_email' => 'combo@example.com']);
        $this->order(101, ['subscription_id' => 2, 'total_amount' => 20, 'order_relationship' => 'renewal',
            'primary_product' => 'Dry Tuna and Chicken Combo']);

        $rows = $this->metrics->audience('cross_sell')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('single@example.com', $rows[0]['customer']);
    }

    public function test_win_back_excludes_anyone_still_subscribed_and_lists_each_person_once(): void
    {
        // Two ended subscriptions, one person.
        foreach ([1, 2] as $i) {
            $this->sub($i, 'cancelled', '2026-01-01 00:00:00', [
                'billing_email' => 'lapsed@example.com', 'ended_at' => '2026-0'.($i + 4).'-01 00:00:00',
            ]);
            foreach (range(1, 3) as $k) {
                $this->order(100 + $i * 10 + $k, ['subscription_id' => $i, 'total_amount' => 20,
                    'order_relationship' => 'renewal']);
            }
        }

        // Ended, but still has a live one elsewhere: a cross-sell, not a win-back.
        $this->sub(3, 'cancelled', '2026-01-01 00:00:00', [
            'billing_email' => 'kept@example.com', 'ended_at' => '2026-06-01 00:00:00',
        ]);
        $this->sub(4, 'active', '2026-01-01 00:00:00', ['billing_email' => 'kept@example.com']);
        foreach (range(1, 3) as $k) {
            $this->order(200 + $k, ['subscription_id' => 3, 'total_amount' => 20, 'order_relationship' => 'renewal']);
        }

        $rows = $this->metrics->audience('win_back')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('lapsed@example.com', $rows[0]['customer']);

        // And that person shows up on the cross-sell-style list instead.
        $partial = $this->metrics->audience('partial_churn')['rows'];
        $this->assertSame(['kept@example.com'], array_column($partial, 'customer'));
    }

    public function test_win_back_ignores_customers_who_barely_bought(): void
    {
        $this->sub(1, 'cancelled', '2026-01-01 00:00:00', [
            'billing_email' => 'once@example.com', 'ended_at' => '2026-06-01 00:00:00',
        ]);
        $this->order(100, ['subscription_id' => 1, 'total_amount' => 20, 'order_relationship' => 'renewal']);

        $this->assertCount(0, $this->metrics->audience('win_back')['rows']);
    }

    public function test_never_subscribed_lists_one_time_buyers_with_no_subscription(): void
    {
        $this->order(1, ['billing_email' => 'prospect@example.com', 'customer_id' => 1, 'total_amount' => 30]);
        $this->order(2, ['billing_email' => 'prospect@example.com', 'customer_id' => 1, 'total_amount' => 20]);

        // Has a subscription, so not a prospect.
        $this->order(3, ['billing_email' => 'member@example.com', 'customer_id' => 2, 'total_amount' => 40]);
        $this->sub(9, 'active', '2026-01-01 00:00:00', ['billing_email' => 'member@example.com', 'customer_id' => 2]);

        $rows = $this->metrics->audience('never_subscribed')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('prospect@example.com', $rows[0]['customer']);
        $this->assertSame(50.0, $rows[0]['value']);
        $this->assertSame(2, $rows[0]['payments']);
    }

    public function test_audiences_are_sorted_by_spend_and_the_endpoint_serves_them(): void
    {
        $this->order(1, ['billing_email' => 'small@example.com', 'customer_id' => 1, 'total_amount' => 10]);
        $this->order(2, ['billing_email' => 'big@example.com', 'customer_id' => 2, 'total_amount' => 900]);

        $rows = $this->metrics->audience('never_subscribed')['rows'];
        $this->assertSame('big@example.com', $rows[0]['customer']);

        $this->getJson('/api/metrics/audience?audience=never_subscribed')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.customer', 'big@example.com');
    }

    public function test_an_unknown_audience_falls_back_rather_than_erroring(): void
    {
        $this->getJson('/api/metrics/audience?audience=nonsense')
            ->assertOk()
            ->assertJsonPath('audience', 'cross_sell');
    }

    // ---------------------------------------------------------------- import

    public function test_net_columns_import_and_stay_null_when_absent(): void
    {
        $head = 'id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email';
        $path = sys_get_temp_dir().'/net-'.uniqid().'.csv';

        file_put_contents($path, $head.",net_amount,tax_amount,shipping_amount,refunded_amount\n".
            "1,shop_order,wc-completed,2026-06-01 00:00:00,120,,one_time,a@b.com,90,20,10,5\n".
            "2,shop_order,wc-completed,2026-06-01 00:00:00,60,,one_time,c@d.com,,,,\n");

        $import = Import::create(['original_filename' => 'x.csv', 'stored_path' => $path, 'status' => 'pending']);
        app(CsvImportService::class)->import($import, $path);

        $this->assertSame('90.00', Record::find(1)->net_amount);
        $this->assertSame('5.00', Record::find(1)->refunded_amount);
        // Blank means unknown, not zero.
        $this->assertNull(Record::find(2)->net_amount);
    }
}
