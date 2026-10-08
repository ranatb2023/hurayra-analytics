<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-2: Churn · Adjusted counts dormant on-hold subscriptions, and Revenue at
 * Risk prices them.
 */
class AdjustedChurnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
    }

    private function sub(int $id, string $status, string $created, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'billing_period' => 'month', 'billing_interval' => 1,
        ], $extra));
    }

    private function order(int $id, int $sub, string $at, float $amount, string $status = 'completed', string $rel = 'renewal'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => $status,
            'date_created_gmt' => $at, 'total_amount' => $amount, 'subscription_id' => $sub,
            'order_relationship' => $rel, 'billing_email' => null,
        ]);
    }

    private function month(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    /**
     * Four active monthly subscribers on 1 Aug. Sub 1 cancels in September.
     * Sub 2 last paid on 1 Aug and went on hold when the next renewal failed:
     * 45 days after that payment (15 Sep) it is dormant. Subs 3 and 4 keep
     * paying.
     */
    private function seedBook(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->sub($i, 'active', '2026-07-01 10:00:00');
            $this->order(100 + $i, $i, '2026-07-01 10:00:00', 20.0, 'completed', 'parent');
            $this->order(200 + $i, $i, '2026-08-01 10:00:00', 20.0);
        }
        foreach ([3, 4] as $i) {
            $this->order(300 + $i, $i, '2026-09-01 10:00:00', 20.0);
            $this->order(400 + $i, $i, '2026-10-01 10:00:00', 20.0);
        }

        Record::where('id', 1)->update(['status' => 'cancelled', 'ended_at' => '2026-09-10 10:00:00']);
        Record::where('id', 2)->update(['status' => 'on-hold']);
    }

    public function test_a_dormant_on_hold_is_adjusted_churn_in_the_period_it_went_dormant(): void
    {
        $this->seedBook();

        $sep = $this->month(2026, 9);

        $this->assertSame(1, $sep['churned_in_period']);
        $this->assertSame(1, $sep['on_hold_dormant_in_period']);
        $this->assertSame(2, $sep['churned_adjusted']);
        // Base 3: sub 2's hold is estimated from one cycle after its last
        // payment (31 Aug), so it was already on hold when September opened.
        $this->assertSame(3, $sep['churn_base']);
        $this->assertSame(33.3, $sep['monthly_churn_rate_net']);       // 1 / 3
        $this->assertSame(66.7, $sep['monthly_churn_rate_adjusted']);  // 2 / 3
    }

    public function test_it_is_not_counted_again_the_month_after(): void
    {
        $this->seedBook();

        $oct = $this->month(2026, 10);

        $this->assertSame(1, $oct['on_hold_dormant']);            // still dormant
        $this->assertSame(0, $oct['on_hold_dormant_in_period']);  // but crossed in September
        $this->assertSame(0, $oct['churned_adjusted']);
    }

    public function test_revenue_at_risk_prices_dormant_subscriptions_monthly(): void
    {
        $this->seedBook();

        $oct = $this->month(2026, 10);

        $this->assertSame(20.0, $oct['revenue_at_risk_dormant']);
        $this->assertSame(0.0, $oct['revenue_at_risk_failed_renewals']);
        $this->assertSame(20.0, $oct['revenue_at_risk']);
    }

    public function test_a_two_monthly_plan_is_priced_at_half_its_payment(): void
    {
        $this->sub(1, 'on-hold', '2026-04-01 10:00:00', ['billing_interval' => 2]);
        $this->order(100, 1, '2026-04-01 10:00:00', 40.0, 'completed', 'parent');

        // 40 every two months = 20 a month. Dormant after 90 days (1.5 × 60).
        $this->assertSame(20.0, $this->month(2026, 10)['revenue_at_risk_dormant']);
    }

    public function test_failed_renewals_in_the_period_are_added(): void
    {
        $this->seedBook();
        $this->order(500, 3, '2026-10-02 10:00:00', 15.0, 'failed');

        $oct = $this->month(2026, 10);

        $this->assertSame(15.0, $oct['revenue_at_risk_failed_renewals']);
        $this->assertSame(35.0, $oct['revenue_at_risk']);
    }

    public function test_a_recent_hold_is_not_dormant_yet(): void
    {
        $this->sub(1, 'on-hold', '2026-09-01 10:00:00');
        $this->order(100, 1, '2026-09-01 10:00:00', 20.0, 'completed', 'parent');

        $oct = $this->month(2026, 10);

        $this->assertSame(0, $oct['on_hold_dormant']);
        $this->assertSame(0.0, $oct['revenue_at_risk']);
    }
}
