<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionCustomerCohortTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function record(int $id, array $attrs): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'status' => 'completed', 'total_amount' => 0,
            'subscription_id' => null, 'customer_id' => null, 'order_relationship' => null, 'billing_email' => null,
        ], $attrs));
    }

    private function march(): Period
    {
        return new Period(
            CarbonImmutable::parse('2026-03-01 00:00:00'),
            CarbonImmutable::parse('2026-04-01 00:00:00'),
            'month',
            'March 2026',
        );
    }

    private function seedRetentionCustomer(): void
    {
        // Subscriptions (snapshot: date < end).
        $this->record(1, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2026-01-05 00:00:00']);
        $this->record(2, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2026-03-02 00:00:00']);
        $this->record(3, ['record_type' => 'shop_subscription', 'status' => 'cancelled', 'date_created_gmt' => '2026-02-10 00:00:00']);
        $this->record(4, ['record_type' => 'shop_subscription', 'status' => 'expired', 'date_created_gmt' => '2026-03-15 00:00:00']);
        $this->record(5, ['record_type' => 'shop_subscription', 'status' => 'on-hold', 'date_created_gmt' => '2026-03-20 00:00:00']);

        // Renewals in window for sub 1, customer 10.
        $this->record(101, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'completed', 'date_created_gmt' => '2026-03-05 00:00:00', 'subscription_id' => 1, 'customer_id' => 10, 'total_amount' => 50]);
        $this->record(102, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'failed', 'date_created_gmt' => '2026-03-06 00:00:00', 'subscription_id' => 1, 'customer_id' => 10, 'total_amount' => 50]);
        $this->record(103, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'pending', 'date_created_gmt' => '2026-03-07 00:00:00', 'subscription_id' => 1, 'customer_id' => 10, 'total_amount' => 50]);

        // One-time + parent orders with customers.
        $this->record(104, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed', 'date_created_gmt' => '2026-03-10 00:00:00', 'customer_id' => 10, 'total_amount' => 100]);
        $this->record(105, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed', 'date_created_gmt' => '2026-03-11 00:00:00', 'customer_id' => 11, 'total_amount' => 200]);
        $this->record(106, ['record_type' => 'shop_order', 'order_relationship' => 'parent', 'status' => 'completed', 'date_created_gmt' => '2026-03-12 00:00:00', 'subscription_id' => 2, 'customer_id' => 12, 'total_amount' => 75]);

        // Customer 11 also ordered BEFORE the window -> they are "returning", not new.
        $this->record(100, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed', 'date_created_gmt' => '2026-01-15 00:00:00', 'customer_id' => 11, 'total_amount' => 30]);
    }

    public function test_retention_metrics(): void
    {
        $this->seedRetentionCustomer();
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(5, $m['total_subscriptions']);
        $this->assertSame(40.0, $m['churn_rate']);                 // (1 cancelled + 1 expired) / 5
        $this->assertSame(33.3, $m['renewal_success_rate']);       // 1 completed of 3 renewals
        $this->assertSame(2, $m['failed_renewals']);               // failed + pending
        $this->assertSame(100.0, $m['revenue_at_risk']);           // 50 failed + 50 pending

        $this->assertSame(
            ['active' => 2, 'on-hold' => 1, 'cancelled' => 1, 'pending-cancel' => 0, 'expired' => 1, 'pending' => 0],
            $m['subscription_status_breakdown'],
        );
    }

    public function test_customer_metrics(): void
    {
        $this->seedRetentionCustomer();
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(3, $m['unique_customers']);       // 10, 11, 12 ordered in window
        $this->assertSame(2, $m['new_customers']);          // 10 and 12 first ordered in March
        $this->assertSame(1, $m['returning_customers']);    // 11 first ordered in January
        $this->assertSame(33.3, $m['repeat_rate']);         // only customer 10 has >= 2 orders in window
        $this->assertSame(141.67, $m['revenue_per_customer']); // 425 completed revenue / 3 customers
    }

    public function test_top_customers_ranked_by_completed_spend(): void
    {
        $this->seedRetentionCustomer();
        $top = $this->metrics->topCustomers(3);

        // Customer 11: 200 + 30 = 230 lifetime completed -> rank 1.
        $this->assertSame(11, $top[0]['customer_id']);
        $this->assertSame(230.0, $top[0]['spend']);
        // Customer 10: 50 (renewal) + 100 (one-time) = 150 completed.
        $this->assertSame(10, $top[1]['customer_id']);
        $this->assertSame(150.0, $top[1]['spend']);
    }

    public function test_cohort_retention(): void
    {
        // Two subscriptions in the Jan 2025 cohort.
        $this->record(1, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2025-01-10 00:00:00']);
        $this->record(2, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2025-01-20 00:00:00']);

        // Sub 1 stays active across 3 months; sub 2 only has its parent order.
        $this->record(11, ['record_type' => 'shop_order', 'status' => 'completed', 'order_relationship' => 'parent', 'subscription_id' => 1, 'date_created_gmt' => '2025-01-15 00:00:00']);
        $this->record(12, ['record_type' => 'shop_order', 'status' => 'completed', 'order_relationship' => 'renewal', 'subscription_id' => 1, 'date_created_gmt' => '2025-02-15 00:00:00']);
        $this->record(13, ['record_type' => 'shop_order', 'status' => 'completed', 'order_relationship' => 'renewal', 'subscription_id' => 1, 'date_created_gmt' => '2025-03-15 00:00:00']);
        $this->record(21, ['record_type' => 'shop_order', 'status' => 'completed', 'order_relationship' => 'parent', 'subscription_id' => 2, 'date_created_gmt' => '2025-01-25 00:00:00']);

        $cohort = $this->metrics->cohortRetention(6);
        $row = collect($cohort['rows'])->firstWhere('cohort', '2025-01');

        $this->assertSame(2, $row['size']);
        $this->assertSame(2, $row['cells'][0]['count']);   // both active month 0
        $this->assertSame(100.0, $row['cells'][0]['pct']);
        $this->assertSame(1, $row['cells'][1]['count']);   // only sub 1 in month 1
        $this->assertSame(50.0, $row['cells'][1]['pct']);
        $this->assertSame(1, $row['cells'][2]['count']);   // only sub 1 in month 2
        $this->assertSame(0, $row['cells'][3]['count']);
    }
}
