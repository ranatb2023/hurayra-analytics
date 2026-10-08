<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-7: cohort median tenure is over every subscription, with active ones
 * measured to now rather than given 0 or left out.
 */
class CohortTenureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'ended_at' => $ended, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
        ]);
        Record::create([
            'id' => 1000 + $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $created, 'total_amount' => 20, 'subscription_id' => $id,
            'order_relationship' => 'parent', 'billing_email' => null,
        ]);
    }

    public function test_a_mostly_active_cohort_has_a_median_at_least_its_age(): void
    {
        // August: 4 of 7 still active (57%), 3 left within a week.
        foreach ([1, 2, 3, 4] as $i) {
            $this->sub($i, 'active', "2026-08-0{$i} 10:00:00");
        }
        $this->sub(5, 'cancelled', '2026-08-10 10:00:00', '2026-08-13 10:00:00');
        $this->sub(6, 'cancelled', '2026-08-11 10:00:00', '2026-08-15 10:00:00');
        $this->sub(7, 'cancelled', '2026-08-20 10:00:00', '2026-08-25 10:00:00');

        $aug = collect((new MetricsService)->cohortValue()['rows'])->firstWhere('cohort', '2026-08');

        // Days from the end of August to now: every active sub is at least
        // this old, and they are the majority.
        $sinceMonthEnd = (int) CarbonImmutable::parse('2026-09-01 00:00:00', 'Europe/London')
            ->diffInDays(CarbonImmutable::now('Europe/London'));

        $this->assertSame(57.1, $aug['retained_pct']);
        $this->assertGreaterThanOrEqual($sinceMonthEnd, $aug['median_tenure_days']);
        // Sorted: 3, 4, 5, 65, 66, 67, 68 -- the 4th of 7 is sub 4 (4 Aug -> 8 Oct).
        $this->assertSame(65, $aug['median_tenure_days']);
    }

    public function test_a_fully_churned_cohort_uses_end_dates_only(): void
    {
        $this->sub(1, 'cancelled', '2026-03-01 10:00:00', '2026-03-11 10:00:00'); // 10 days
        $this->sub(2, 'cancelled', '2026-03-02 10:00:00', '2026-04-01 10:00:00'); // 30 days
        $this->sub(3, 'expired', '2026-03-03 10:00:00', '2026-05-02 10:00:00');   // 60 days

        $mar = collect((new MetricsService)->cohortValue()['rows'])->firstWhere('cohort', '2026-03');

        $this->assertSame(30, $mar['median_tenure_days']);
    }

    public function test_on_hold_subscriptions_have_not_ended_so_run_to_now(): void
    {
        $this->sub(1, 'on-hold', '2026-09-01 10:00:00');

        $sep = collect((new MetricsService)->cohortValue()['rows'])->firstWhere('cohort', '2026-09');

        $this->assertSame(37, $sep['median_tenure_days']); // 1 Sep -> 8 Oct
        $this->assertSame(0, $sep['churned']);
    }
}
