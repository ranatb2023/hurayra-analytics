<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\DataFreshness;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-6: a shop_order carrying a subscription status (wc-active) is a data
 * defect. It is excluded from every metric, as WP Admin excludes it, and is
 * never modified.
 */
class InvalidOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $id, string $status, string $rel = 'one_time', ?int $sub = null, ?int $customer = null): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => $status,
            'date_created_gmt' => '2026-09-10 10:00:00', 'total_amount' => 25, 'subscription_id' => $sub,
            'customer_id' => $customer, 'order_relationship' => $rel, 'billing_email' => null,
        ]);
    }

    private function september(): array
    {
        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    private function seedOrders(): void
    {
        $this->order(1, 'completed', 'one_time', null, 7);
        $this->order(2, 'cancelled');
        $this->order(3, 'failed', 'renewal');
        $this->order(5209, 'active', 'one_time', null, 8);  // subscription status on an order
        $this->order(5230, 'active', 'renewal');
        $this->order(6049, 'active', 'parent');
        $this->order(9, 'trash');
    }

    public function test_invalid_status_orders_are_in_no_count(): void
    {
        $this->seedOrders();

        $m = $this->september();

        $this->assertSame(2, $m['one_time_purchase']);       // #1 and #2, not #5209
        $this->assertSame(1, $m['subscription_purchases']);  // #3, not #5230 / #6049
        $this->assertSame(1, $m['renewal_purchases']);
        $this->assertSame(2, $m['new_not_completed_standard']); // cancelled + failed
        $this->assertSame(1, $m['unique_customers']);           // customer 8 only had the invalid order
        $this->assertSame(1, $m['new_customers']);
        $this->assertSame(['active' => 3], $m['unrecognised_statuses']); // surfaced, not counted
    }

    public function test_the_dashboard_order_count_matches_wp_admin_all(): void
    {
        $this->seedOrders();

        // WP Admin "All" hides trash and the wrong-type rows: 3 real orders.
        $this->assertSame(3, (new DataFreshness)->dashboardOrderCount());
        $this->assertSame(6, (new DataFreshness)->sourceOrderCount()['count']);
    }

    public function test_trends_skip_them_too(): void
    {
        $this->seedOrders();

        $trend = (new MetricsService)->trend('renewal_purchases', 'month');

        $this->assertSame([1], $trend['values']); // the failed renewal, not #5230
    }

    public function test_the_rows_themselves_are_left_untouched(): void
    {
        $this->seedOrders();
        $this->september();

        $this->assertSame('active', Record::find(5209)->status);
    }
}
