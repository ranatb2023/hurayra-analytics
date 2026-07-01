<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hand-built, fully enumerated dataset so every metric has a provable value.
 *
 * Focus window  : March 2026  -> [2026-03-01, 2026-04-01)
 * Previous window: February 2026 -> [2026-02-01, 2026-03-01)
 */
class MetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
        $this->seedDataset();
    }

    private function record(int $id, array $attrs): void
    {
        Record::create(array_merge([
            'id' => $id,
            'import_id' => null,
            'status' => 'completed',
            'total_amount' => 0,
            'subscription_id' => null,
            'order_relationship' => null,
            'billing_email' => null,
        ], $attrs));
    }

    private function seedDataset(): void
    {
        // ---- Subscriptions (snapshot uses date_created_gmt < window end) ----
        $this->record(1, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2026-01-10 00:00:00']);       // A active, before window
        $this->record(2, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2026-03-05 00:00:00']);       // B active, new in March
        $this->record(3, ['record_type' => 'shop_subscription', 'status' => 'active', 'date_created_gmt' => '2026-05-01 00:00:00']);       // C active, AFTER window end
        $this->record(4, ['record_type' => 'shop_subscription', 'status' => 'on-hold', 'date_created_gmt' => '2026-02-01 00:00:00']);      // D on-hold
        $this->record(5, ['record_type' => 'shop_subscription', 'status' => 'pending-cancel', 'date_created_gmt' => '2026-03-20 00:00:00']); // E
        $this->record(6, ['record_type' => 'shop_subscription', 'status' => 'cancelled', 'date_created_gmt' => '2026-03-10 00:00:00']);    // F cancelled WITH purchase
        $this->record(7, ['record_type' => 'shop_subscription', 'status' => 'cancelled', 'date_created_gmt' => '2026-02-15 00:00:00']);    // G cancelled, no orders
        $this->record(8, ['record_type' => 'shop_subscription', 'status' => 'cancelled', 'date_created_gmt' => '2026-03-25 00:00:00']);    // H cancelled, only failed order

        // ---- Orders (cohort: counted if date in [start, end)) ----
        $this->record(101, ['record_type' => 'shop_order', 'order_relationship' => 'parent', 'status' => 'completed', 'date_created_gmt' => '2026-03-08 00:00:00', 'subscription_id' => 6, 'total_amount' => 100]); // F's completed -> with purchase
        $this->record(102, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'completed', 'date_created_gmt' => '2026-03-12 00:00:00', 'subscription_id' => 1, 'total_amount' => 50]);
        $this->record(103, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'pending', 'date_created_gmt' => '2026-03-15 00:00:00', 'subscription_id' => 1, 'total_amount' => 50]);
        $this->record(104, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed', 'date_created_gmt' => '2026-03-02 00:00:00', 'total_amount' => 200]);
        $this->record(105, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'refunded', 'date_created_gmt' => '2026-03-18 00:00:00', 'total_amount' => 30]);
        $this->record(106, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'cancelled', 'date_created_gmt' => '2026-03-22 00:00:00', 'total_amount' => 40]);
        $this->record(107, ['record_type' => 'shop_order', 'order_relationship' => 'parent', 'status' => 'completed', 'date_created_gmt' => '2026-03-28 00:00:00', 'subscription_id' => 2, 'total_amount' => 75]);
        $this->record(108, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'failed', 'date_created_gmt' => '2026-03-09 00:00:00', 'subscription_id' => 8, 'total_amount' => 20]); // H only failed
        $this->record(109, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'processing', 'date_created_gmt' => '2026-03-30 00:00:00', 'total_amount' => 60]);

        // Outside the window (February) — must be excluded from March cohort.
        $this->record(110, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed', 'date_created_gmt' => '2026-02-20 00:00:00', 'total_amount' => 999]);
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

    public function test_subscription_metrics(): void
    {
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(4, $m['new_subscribers']);            // B, E, F, H
        $this->assertSame(2, $m['subscribers_active']);          // A, B (C is after window end)
        $this->assertSame(1, $m['pending_cancellation']);        // E
        $this->assertSame(1, $m['on_hold']);                     // D
        $this->assertSame(1, $m['cancelled_with_purchase']);     // F (signed up in March, has completed order)
        $this->assertSame(1, $m['cancelled_without_purchase']);  // H (signed up in March, only a failed order); G excluded (Feb signup)
    }

    public function test_order_metrics(): void
    {
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(4, $m['one_time_purchase']);         // 104,105,106,109
        $this->assertSame(5, $m['subscription_purchases']);    // 101,102,103,107,108
        $this->assertSame(3, $m['renewal_purchases']);         // 102,103,108
        $this->assertSame(4, $m['completed']);                 // 101,102,104,107
        $this->assertSame(5, $m['new_not_completed_standard']); // 103,105,106,108,109
        $this->assertSame(2, $m['new_not_completed_strict']);   // 103 pending, 109 processing
    }

    public function test_not_completed_breakdown(): void
    {
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(
            ['cancelled' => 1, 'failed' => 1, 'refunded' => 1, 'pending' => 1, 'processing' => 1],
            $m['not_completed_breakdown'],
        );
    }

    public function test_strict_toggle_changes_new_not_completed(): void
    {
        $standard = $this->metrics->compute($this->march()->start, $this->march()->end, strictNotCompleted: false);
        $strict = $this->metrics->compute($this->march()->start, $this->march()->end, strictNotCompleted: true);

        $this->assertSame(5, $standard['new_not_completed']);
        $this->assertSame(2, $strict['new_not_completed']);
    }

    public function test_supporting_totals(): void
    {
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        $this->assertSame(425.0, $m['total_revenue']);          // 100+50+200+75
        $this->assertSame(106.25, $m['average_order_value']);   // 425 / 4
        $this->assertSame(225.0, $m['subscription_revenue']);   // 100+50+75
        $this->assertSame(200.0, $m['one_time_revenue']);       // 204 only
        $this->assertSame(0.67, $m['active_cancelled_ratio']);  // 2 active / 3 cancelled
    }

    public function test_february_order_is_excluded_from_march(): void
    {
        $m = $this->metrics->compute($this->march()->start, $this->march()->end);

        // The 999 February order must not leak into March revenue.
        $this->assertSame(425.0, $m['total_revenue']);
    }

    public function test_compare_to_previous_period(): void
    {
        $summary = $this->metrics->summary($this->march(), compare: true);

        // Previous = February: only order 110 (completed, one_time, 999).
        $this->assertNotNull($summary['comparison']);
        $this->assertSame(1, $summary['comparison']['completed']['previous']);
        // current 4 vs previous 1 => +300%
        $this->assertSame(300.0, $summary['comparison']['completed']['change']);
    }

    public function test_snapshot_ignores_signup_lower_bound_but_respects_period_end(): void
    {
        // A whole-2026 window still excludes sub C (created 2026-05-01) when the
        // window ends before it; and a window ending in Feb sees fewer cancels.
        $febEnd = new Period(
            CarbonImmutable::parse('2026-02-01 00:00:00'),
            CarbonImmutable::parse('2026-03-01 00:00:00'),
            'month',
            'Feb 2026',
        );

        $feb = $this->metrics->compute($febEnd->start, $febEnd->end);

        // subscribers_active is a snapshot (date < end): A only, B created Mar 5.
        $this->assertSame(1, $feb['subscribers_active']);
        // Cancelled cards are now a cohort (signed up in Feb): G (2026-02-15) only.
        $this->assertSame(1, $feb['cancelled_without_purchase']); // G signed up in Feb, no completed order
        $this->assertSame(0, $feb['cancelled_with_purchase']);    // F signed up in March, outside the window
    }
}
