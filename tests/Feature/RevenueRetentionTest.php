<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revenue retention, failed sign-ups, dormant holds and cohort value.
 *
 * These four exist because a headcount churn rate hides things: a leaver who
 * never paid is not a lost customer, a subscription stalled in `on-hold` never
 * becomes churn at all, and two leavers on different plans are not equal
 * losses.
 */
class RevenueRetentionTest extends TestCase
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
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => $ended,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => null, 'billing_email' => null,
        ]);
    }

    private function order(int $id, ?int $sub, string $created, float $amount, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => $amount, 'subscription_id' => $sub, 'customer_id' => 0,
            'order_relationship' => $sub === null ? 'one_time' : 'renewal', 'billing_email' => null,
        ]);
    }

    private function june(): array
    {
        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    // ---------------------------------------------------------- failed sign-ups

    public function test_a_leaver_that_never_completed_an_order_is_a_failed_signup(): void
    {
        $this->sub(1, 'cancelled', '2026-06-02 00:00:00', '2026-06-03 00:00:00');
        $this->order(100, 1, '2026-06-02 00:00:00', 20.0, 'failed');

        $m = $this->june();

        $this->assertSame(1, $m['churned_in_period']);
        $this->assertSame(1, $m['failed_signups']);
        $this->assertSame(0, $m['churned_net_of_failed']);
    }

    public function test_a_leaver_that_paid_once_is_not_a_failed_signup(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');
        $this->sub(2, 'cancelled', '2026-01-01 00:00:00', '2026-06-10 00:00:00');
        $this->order(100, 2, '2026-02-01 00:00:00', 20.0);

        $m = $this->june();

        $this->assertSame(1, $m['churned_in_period']);
        $this->assertSame(0, $m['failed_signups']);
        $this->assertSame(1, $m['churned_net_of_failed']);
    }

    public function test_the_net_rate_is_the_gross_rate_without_the_failed_signups(): void
    {
        // Four active at the start; two leave, one of which never paid.
        foreach ([1, 2, 3, 4] as $i) {
            $this->sub($i, 'active', '2026-01-01 00:00:00');
            $this->order(100 + $i, $i, '2026-05-01 00:00:00', 10.0);
        }
        Record::where('id', 1)->update(['status' => 'cancelled', 'ended_at' => '2026-06-10 00:00:00']);
        $this->sub(9, 'cancelled', '2026-06-05 00:00:00', '2026-06-06 00:00:00'); // never paid

        $m = $this->june();

        $this->assertSame(4, $m['active_at_period_start']);
        $this->assertSame(2, $m['churned_in_period']);
        $this->assertSame(1, $m['failed_signups']);
        $this->assertSame(50.0, $m['monthly_churn_rate']);
        $this->assertSame(25.0, $m['monthly_churn_rate_net']);
    }

    // ------------------------------------------------------------ dormant holds

    public function test_an_on_hold_subscription_that_stopped_paying_is_flagged_dormant(): void
    {
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 10.0); // long before the cutoff

        $this->assertSame(1, $this->june()['on_hold_dormant']);
    }

    public function test_a_recently_paid_on_hold_subscription_is_not_dormant(): void
    {
        // A failed payment mid-retry, not an abandoned subscription.
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00');
        $this->order(100, 1, '2026-06-20 00:00:00', 10.0);

        $this->assertSame(0, $this->june()['on_hold_dormant']);
    }

    public function test_an_on_hold_subscription_that_never_paid_counts_as_dormant(): void
    {
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00');

        $this->assertSame(1, $this->june()['on_hold_dormant']);
    }

    // -------------------------------------------------------- revenue retention

    public function test_revenue_retention_is_full_when_everyone_keeps_paying_the_same(): void
    {
        foreach ([1, 2] as $i) {
            $this->sub($i, 'active', '2026-01-01 00:00:00');
            $this->order(100 + $i, $i, '2026-05-01 00:00:00', 25.0);
        }

        $m = $this->june();

        $this->assertSame(100.0, $m['net_revenue_retention']);
        $this->assertSame(50.0, $m['recurring_revenue_start']);
    }

    public function test_a_departure_removes_its_revenue_from_retention(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');
        $this->order(100, 1, '2026-05-01 00:00:00', 30.0);
        $this->sub(2, 'cancelled', '2026-01-01 00:00:00', '2026-06-15 00:00:00');
        $this->order(101, 2, '2026-05-01 00:00:00', 10.0);

        $m = $this->june();

        // 40 on the books, 30 still billing.
        $this->assertSame(40.0, $m['recurring_revenue_start']);
        $this->assertSame(30.0, $m['recurring_revenue_retained']);
        $this->assertSame(75.0, $m['net_revenue_retention']);
    }

    public function test_an_upgrade_can_carry_net_retention_above_one_hundred(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');
        $this->order(100, 1, '2026-05-01 00:00:00', 20.0);
        $this->order(101, 1, '2026-06-15 00:00:00', 50.0); // upgraded mid-period

        $m = $this->june();

        $this->assertSame(250.0, $m['net_revenue_retention']);
        // Gross caps each subscriber at its starting price, so it cannot.
        $this->assertSame(100.0, $m['gross_revenue_retention']);
    }

    public function test_new_signups_do_not_flatter_revenue_retention(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');
        $this->order(100, 1, '2026-05-01 00:00:00', 20.0);
        // Joined during the window: not part of the opening book.
        $this->sub(2, 'active', '2026-06-02 00:00:00');
        $this->order(101, 2, '2026-06-03 00:00:00', 500.0);

        $m = $this->june();

        $this->assertSame(20.0, $m['recurring_revenue_start']);
        $this->assertSame(100.0, $m['net_revenue_retention']);
    }

    public function test_revenue_retention_is_null_with_nothing_on_the_books(): void
    {
        $this->sub(1, 'active', '2026-06-05 00:00:00');

        $m = $this->june();

        $this->assertNull($m['net_revenue_retention']);
        $this->assertNull($m['gross_revenue_retention']);
    }

    // ------------------------------------------------------------ cohort value

    public function test_cohort_value_reports_earnings_per_sign_up_month(): void
    {
        $this->sub(1, 'active', '2026-03-05 00:00:00');
        $this->order(100, 1, '2026-04-01 00:00:00', 60.0);
        $this->sub(2, 'cancelled', '2026-03-20 00:00:00', '2026-04-20 00:00:00');
        $this->order(101, 2, '2026-03-25 00:00:00', 40.0);
        // Anchors "now" so March is a mature cohort.
        $this->order(102, null, '2026-09-01 00:00:00', 5.0);

        $row = collect($this->metrics->cohortValue()['rows'])->firstWhere('cohort', '2026-03');

        $this->assertSame(2, $row['size']);
        $this->assertSame(1, $row['still_active']);
        $this->assertSame(50.0, $row['retained_pct']);
        $this->assertSame(100.0, $row['total_spend']);
        $this->assertSame(50.0, $row['value_per_subscriber']);
        $this->assertSame(31, $row['median_tenure_days']);
        $this->assertFalse($row['immature']);
    }

    public function test_recent_cohorts_are_marked_immature(): void
    {
        $this->sub(1, 'active', '2026-08-05 00:00:00');
        $this->order(100, 1, '2026-08-10 00:00:00', 10.0);

        $row = collect($this->metrics->cohortValue()['rows'])->firstWhere('cohort', '2026-08');

        // Still accruing revenue, so its value cannot be judged yet.
        $this->assertTrue($row['immature']);
    }

    public function test_endpoint_returns_cohort_value(): void
    {
        $this->sub(1, 'active', '2026-03-05 00:00:00');
        $this->order(100, 1, '2026-04-01 00:00:00', 60.0);

        $this->getJson('/api/metrics/cohort-value?cohorts=6')
            ->assertOk()
            ->assertJsonPath('rows.0.cohort', '2026-03');
    }
}
