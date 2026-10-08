<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-1: churn = churned ÷ (active at start + new in period), so same-month
 * joiners who leave are in the denominator as well as the numerator.
 */
class ChurnDenominatorTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $status, string $created, ?string $ended = null): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'ended_at' => $ended, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
        ]);
    }

    private function paid(int $id, int $sub, string $at): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $at, 'total_amount' => 20, 'subscription_id' => $sub,
            'order_relationship' => 'parent', 'billing_email' => null,
        ]);
    }

    /**
     * October: one subscriber on the book at the start, five join and four of
     * those leave inside the month. The old formula read 4 / 1 = 400%.
     */
    private function seedHighTurnoverMonth(): void
    {
        $this->sub(1, 'active', '2026-08-01 10:00:00');
        $this->paid(100, 1, '2026-08-01 10:00:00');

        foreach ([2, 3, 4, 5] as $i) {
            $this->sub($i, 'cancelled', "2026-10-0{$i} 10:00:00", "2026-10-1{$i} 10:00:00");
            $this->paid(100 + $i, $i, "2026-10-0{$i} 10:00:00");
        }
        $this->sub(6, 'active', '2026-10-06 10:00:00');
    }

    private function october(): array
    {
        return (new MetricsService)->compute(
            CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London'),
            CarbonImmutable::parse('2026-11-01 00:00:00', 'Europe/London'),
        );
    }

    public function test_same_month_joiners_who_churn_are_in_the_denominator(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/London'));
        $this->seedHighTurnoverMonth();

        $m = $this->october();

        $this->assertSame(1, $m['active_at_period_start']);
        $this->assertSame(5, $m['new_subscribers']);
        $this->assertSame(6, $m['churn_base']);
        $this->assertSame(4, $m['churned_in_period']);
        $this->assertSame(66.7, $m['monthly_churn_rate']);    // 4 / 6
        $this->assertSame(66.7, $m['monthly_churn_rate_net']); // all four had paid
    }

    public function test_the_monthly_history_never_exceeds_100_percent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/London'));
        $this->seedHighTurnoverMonth();

        $history = (new MetricsService)->history(3)['rows'];
        $october = collect($history)->firstWhere('month', '2026-10');

        $this->assertSame(66.7, $october['churn_rate']);
        $this->assertSame(66.7, $october['churn_net']);

        foreach ($history as $row) {
            $this->assertLessThanOrEqual(100, $row['churn_rate'] ?? 0, $row['month']);
            $this->assertLessThanOrEqual(100, $row['churn_net'] ?? 0, $row['month']);
        }
    }

    public function test_every_joiner_leaving_is_exactly_100_percent_not_more(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/London'));

        foreach ([1, 2, 3] as $i) {
            $this->sub($i, 'cancelled', "2026-10-0{$i} 10:00:00", "2026-10-2{$i} 10:00:00");
            $this->paid(100 + $i, $i, "2026-10-0{$i} 10:00:00");
        }

        $this->assertSame(100.0, $this->october()['monthly_churn_rate']);
    }
}
