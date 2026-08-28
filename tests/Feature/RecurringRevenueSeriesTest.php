<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recurring revenue as a series rather than an instant.
 *
 * The MRR card reads `status = 'active'`, which is a live value — it can say
 * what the book is worth now, but asking it about March would price March
 * using the subscriptions still running today. The series has to walk the same
 * sign-up and end dates the subscriber counts walk, and the property that
 * matters is the same one: a closed month never moves.
 */
class RecurringRevenueSeriesTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null, array $attrs = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => $ended,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'subscription', 'billing_email' => null,
            'billing_period' => 'month', 'billing_interval' => 1,
        ], $attrs));
    }

    private function order(int $id, ?int $sub, string $created, float $amount, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => $amount, 'subscription_id' => $sub, 'customer_id' => 0,
            'order_relationship' => 'renewal', 'billing_email' => null,
        ]);
    }

    /** @return array<string, array<string, mixed>> month => row */
    private function series(int $months = 6): array
    {
        return array_column($this->metrics->mrrSeries($months)['rows'], null, 'month');
    }

    public function test_a_monthly_plan_contributes_what_it_last_paid(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        // Anchors the trailing month at April.
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = $this->series();

        $this->assertSame(20.0, $rows['2026-02']['mrr']);
        $this->assertSame(1, $rows['2026-02']['paying']);
    }

    public function test_a_two_monthly_plan_is_normalised_to_thirty_days(): void
    {
        // £40 every two months is £20 of monthly recurring revenue. Booking the
        // whole £40 as a month would overstate this subscriber by half.
        $this->sub(1, 'active', '2026-01-05 00:00:00', null, ['billing_interval' => 2]);
        $this->order(100, 1, '2026-02-01 00:00:00', 40.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $this->assertSame(20.0, $this->series()['2026-02']['mrr']);
    }

    public function test_a_subscription_that_never_paid_adds_nothing(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        // Signed up, never billed: not yet recurring anything.
        $this->sub(2, 'active', '2026-01-06 00:00:00');
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = $this->series();

        $this->assertSame(20.0, $rows['2026-02']['mrr']);
        $this->assertSame(1, $rows['2026-02']['paying']);
    }

    public function test_revenue_leaves_the_series_in_the_month_the_subscription_ended(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->sub(2, 'cancelled', '2026-01-05 00:00:00', '2026-03-10 00:00:00');
        $this->order(101, 2, '2026-02-01 00:00:00', 30.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = $this->series();

        $this->assertSame(50.0, $rows['2026-02']['mrr']);  // both still billing
        $this->assertSame(20.0, $rows['2026-03']['mrr']);  // #2 ended 10 March
    }

    public function test_a_closed_month_does_not_move_when_someone_cancels_later(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->order(199, null, '2026-06-02 00:00:00', 5.0);

        $before = $this->series()['2026-03']['mrr'];

        // Cancels in May — long after March closed.
        Record::where('id', 1)->update(['status' => 'cancelled', 'ended_at' => '2026-05-14 00:00:00']);

        $this->assertSame($before, $this->series()['2026-03']['mrr']);
        $this->assertSame(0.0, $this->series()['2026-06']['mrr']);
    }

    public function test_per_subscriber_revenue_separates_more_customers_from_a_bigger_basket(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->sub(2, 'active', '2026-02-20 00:00:00');
        $this->order(101, 2, '2026-03-01 00:00:00', 40.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = $this->series();

        // MRR doubles and then some, but the average tells you it was one new
        // subscriber paying more, not the same people paying more.
        $this->assertSame(20.0, $rows['2026-02']['arpu']);
        $this->assertSame(60.0, $rows['2026-03']['mrr']);
        $this->assertSame(30.0, $rows['2026-03']['arpu']);
    }

    public function test_arr_is_twelve_months_of_the_run_rate(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $this->assertSame(240.0, $this->series()['2026-02']['arr']);
    }

    public function test_a_month_with_no_payers_reports_no_average_rather_than_zero(): void
    {
        $this->sub(1, 'active', '2026-03-05 00:00:00');
        $this->order(100, 1, '2026-03-10 00:00:00', 20.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        // Nobody was paying anything in January.
        $this->assertSame(0.0, $this->series()['2026-01']['mrr']);
        $this->assertNull($this->series()['2026-01']['arpu']);
    }

    public function test_the_series_agrees_with_the_card_at_the_latest_month(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->sub(2, 'active', '2026-01-06 00:00:00', null, ['billing_interval' => 2]);
        $this->order(101, 2, '2026-03-01 00:00:00', 40.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = $this->series();
        $latest = end($rows);

        $card = $this->metrics->compute(
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-05-01'),
        );

        // Same book, same prices, two different code paths.
        $this->assertSame($card['mrr'], $latest['mrr']);
    }

    public function test_history_carries_recurring_revenue_alongside_the_counts(): void
    {
        $this->sub(1, 'active', '2026-01-05 00:00:00');
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);
        $this->order(199, null, '2026-04-02 00:00:00', 5.0);

        $rows = array_column($this->metrics->history(6)['rows'], null, 'month');

        $this->assertSame(20.0, $rows['2026-02']['mrr']);
        $this->assertSame(20.0, $rows['2026-02']['arpu']);
        $this->assertSame(1, $rows['2026-02']['paying']);
        // And still the subscriber counts it always carried.
        $this->assertArrayHasKey('active_end', $rows['2026-02']);
    }
}
