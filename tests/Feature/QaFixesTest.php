<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The QA pass of 2026-11-23: API input errors, what counts as paid, failed
 * renewals, plan switches, failed first renewals, and the acquisition splits.
 */
class QaFixesTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $status, string $created, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'billing_period' => 'month', 'billing_interval' => 1,
        ], $extra));
    }

    private function order(int $id, ?int $sub, string $at, string $status = 'completed', string $rel = 'renewal', array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => $status,
            'date_created_gmt' => $at, 'total_amount' => 20, 'subscription_id' => $sub,
            'order_relationship' => $rel, 'customer_id' => $sub ?? $id,
        ], $extra));
    }

    private function month(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    // ---- API input ---------------------------------------------------------

    public function test_bad_period_input_is_a_json_422_not_a_500_or_an_html_page(): void
    {
        foreach ([
            '/api/metrics/summary?granularity=custom&from=2026-11-20&to=2026-11-01',
            '/api/metrics/summary?granularity=custom&from=&to=',
            '/api/metrics/summary?granularity=month&year=abc',
            '/api/metrics/trend?metric=orders&granularity=month&from=2026-11-20&to=2026-11-01',
        ] as $url) {
            $this->get($url)->assertStatus(422)->assertHeader('Content-Type', 'application/json');
        }
    }

    // ---- Paid = completed + processing ------------------------------------

    public function test_processing_orders_count_as_paid_revenue_and_customers(): void
    {
        $this->order(1, null, '2026-03-05 10:00:00', 'completed', 'one_time', ['total_amount' => 100, 'customer_id' => 10]);
        $this->order(2, null, '2026-03-06 10:00:00', 'processing', 'one_time', ['total_amount' => 50, 'customer_id' => 11]);
        $this->order(3, null, '2026-03-07 10:00:00', 'cancelled', 'one_time', ['total_amount' => 70, 'customer_id' => 12]);

        $m = $this->month(2026, 3);

        $this->assertSame(150.0, $m['total_revenue']);
        $this->assertSame(2, $m['unique_customers']);        // the cancelled order is not a paying customer
        $this->assertSame(75.0, $m['revenue_per_customer']);
        $this->assertSame(75.0, $m['average_order_value']);  // 150 over 2 paid orders
        $this->assertSame(1, $m['new_not_completed']);       // only the cancelled one
    }

    // ---- Plan switches ----------------------------------------------------

    public function test_a_restart_within_14_days_is_a_plan_switch_not_churn(): void
    {
        // Switched plans: ended 10 Mar, same email back on 15 Mar.
        $this->sub(1, 'cancelled', '2026-01-05 10:00:00', ['ended_at' => '2026-03-10 10:00:00', 'billing_email' => 'a@x.test']);
        $this->order(101, 1, '2026-01-05 10:00:00', 'completed', 'parent');
        $this->sub(2, 'active', '2026-03-15 10:00:00', ['billing_email' => 'a@x.test']);
        $this->order(102, 2, '2026-03-15 10:00:00', 'completed', 'parent');

        // Really left: back only after 30 days.
        $this->sub(3, 'cancelled', '2026-01-05 10:00:00', ['ended_at' => '2026-03-01 10:00:00', 'billing_email' => 'b@x.test']);
        $this->order(103, 3, '2026-01-05 10:00:00', 'completed', 'parent');
        $this->sub(4, 'active', '2026-03-31 10:00:00', ['billing_email' => 'b@x.test']);
        $this->order(104, 4, '2026-03-31 10:00:00', 'completed', 'parent');

        $m = $this->month(2026, 3);

        $this->assertSame(1, $m['churned_in_period']);
        $this->assertSame(1, $m['plan_switches_in_period']);

        $lost = (new MetricsService)->churnedSubscriptions('2026-03-01 00:00:00', '2026-04-01 00:00:00');
        $this->assertSame(2, $lost['total']);     // both listed...
        $this->assertSame(1, $lost['counted']);   // ...one counted
        $this->assertSame([false, true], array_column($lost['rows'], 'plan_switch'));
    }

    public function test_adjusted_churn_is_built_on_real_churn(): void
    {
        // A sign-up that never paid: gross churn, not real churn.
        $this->sub(1, 'cancelled', '2026-03-02 10:00:00', ['ended_at' => '2026-03-20 10:00:00']);

        $m = $this->month(2026, 3);

        $this->assertSame(1, $m['churned_in_period']);
        $this->assertSame(0, $m['churned_net_of_failed']);
        $this->assertSame($m['churned_net_of_failed'] + $m['on_hold_dormant_in_period'], $m['churned_adjusted']);
    }

    // ---- Failed first renewal -----------------------------------------------

    public function test_a_manual_pause_or_an_early_hold_is_not_a_failed_first_renewal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));

        // Paid 6 Sep, paused by hand on 6 Oct.
        $this->sub(1, 'on-hold', '2026-09-06 10:00:00');
        $this->order(100, 1, '2026-09-06 10:00:00', 'completed', 'parent');
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => 1, 'changed_at' => '2026-10-06 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold', 'hold_reason' => 'manual',
        ]);

        // Paid 25 Sep, on hold for a payment on 1 Oct: the renewal was not due yet.
        $this->sub(2, 'on-hold', '2026-09-25 10:00:00');
        $this->order(200, 2, '2026-09-25 10:00:00', 'completed', 'parent');
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => 2, 'changed_at' => '2026-10-01 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold', 'hold_reason' => 'payment_due',
        ]);

        // Paid 5 Sep, renewal due 5 Oct failed: the real thing.
        $this->sub(3, 'on-hold', '2026-09-05 10:00:00');
        $this->order(300, 3, '2026-09-05 10:00:00', 'completed', 'parent');
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => 3, 'changed_at' => '2026-10-05 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold', 'hold_reason' => 'payment_due',
        ]);

        $failed = (new MetricsService)->upcomingRenewals(14)['failed_first_renewal'];

        $this->assertSame([3], array_column($failed['rows'], 'id'));
    }

    // ---- Acquisition splits -----------------------------------------------

    public function test_product_falls_back_to_the_parent_order_and_cycle_keeps_the_interval(): void
    {
        foreach (range(1, 5) as $i) {
            $this->sub($i, 'active', '2026-03-0'.$i.' 10:00:00', ['billing_interval' => 2]);
            $this->order(100 + $i, $i, '2026-03-0'.$i.' 10:00:00', 'completed', 'parent', ['primary_product' => 'Dry Chicken Cat Food']);
        }
        foreach (range(6, 10) as $i) {
            $this->sub($i, 'active', '2026-03-1'.($i - 6).' 10:00:00');
        }

        $products = array_column((new MetricsService)->segmentPerformance('primary_product')['rows'], 'subs', 'segment');
        $this->assertSame(5, $products['Dry Chicken Cat Food']);

        $cycles = array_column((new MetricsService)->segmentPerformance('billing_period')['rows'], 'subs', 'segment');
        $this->assertSame(['every 2 months' => 5, 'monthly' => 5], array_intersect_key($cycles, ['every 2 months' => 0, 'monthly' => 0]));
    }

    // ---- CSV ----------------------------------------------------------------

    public function test_the_csv_has_readable_labels_and_the_previous_period(): void
    {
        $this->order(1, null, '2026-02-05 10:00:00', 'completed', 'one_time', ['total_amount' => 40]);
        $this->order(2, null, '2026-03-05 10:00:00', 'completed', 'one_time', ['total_amount' => 60]);

        $csv = $this->get('/api/metrics/export?granularity=month&year=2026&month=3')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Revenue (paid, gross £)",60,40,50', $csv);
        $this->assertStringNotContainsString('snapshot_capped', $csv);
        $this->assertStringNotContainsString("\ntotal_revenue,", $csv);
    }
}
