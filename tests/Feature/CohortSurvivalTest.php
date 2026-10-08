<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-8: cohort retention is survival (not ended by the end of month n), so it
 * cannot rise over time even when plans skip months.
 */
class CohortSurvivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null, int $interval = 1): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'ended_at' => $ended, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'billing_period' => 'month', 'billing_interval' => $interval,
        ]);
    }

    private function paid(int $id, int $sub, string $at): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $at, 'total_amount' => 20, 'subscription_id' => $sub,
            'order_relationship' => 'renewal', 'billing_email' => null,
        ]);
    }

    /**
     * A February cohort of four: two two-monthly plans that pay in Feb, Apr,
     * Jun (so they "skip" Mar and May), one monthly that cancels in April,
     * and one that goes on hold in May.
     */
    private function seedFebruary(): void
    {
        foreach ([1, 2] as $i) {
            $this->sub($i, 'active', '2026-02-10 10:00:00', null, 2);
            foreach (['02', '04', '06', '08'] as $j => $m) {
                $this->paid($i * 100 + $j, $i, "2026-{$m}-10 10:00:00");
            }
        }

        $this->sub(3, 'cancelled', '2026-02-12 10:00:00', '2026-04-15 10:00:00');
        $this->paid(301, 3, '2026-02-12 10:00:00');
        $this->paid(302, 3, '2026-03-12 10:00:00');

        $this->sub(4, 'on-hold', '2026-02-14 10:00:00');
        foreach (['02', '03', '04'] as $j => $m) {
            $this->paid(400 + $j, 4, "2026-{$m}-14 10:00:00");
        }
    }

    private function february(): array
    {
        return collect((new MetricsService)->cohortRetention(8)['rows'])->firstWhere('cohort', '2026-02');
    }

    public function test_the_curve_never_goes_up(): void
    {
        $this->seedFebruary();

        $pcts = array_map(fn ($c) => $c['pct'], array_filter($this->february()['cells']));

        // M0 Feb, M1 Mar: all four. M2 Apr: sub 3 has ended. On hold is not
        // gone. M8 is October, in progress.
        $this->assertSame([100.0, 100.0, 75.0, 75.0, 75.0, 75.0, 75.0, 75.0, 75.0], array_values($pcts));

        for ($k = 1; $k < count($pcts); $k++) {
            $this->assertLessThanOrEqual($pcts[$k - 1], $pcts[$k], "M{$k}");
        }
    }

    public function test_order_activity_still_dips_and_recovers_with_the_billing_cycle(): void
    {
        $this->seedFebruary();

        $activity = array_column($this->february()['activity'], 'pct');

        // Mar: only subs 3 and 4 paid; Apr: 1, 2 and 4. The old "retention".
        $this->assertSame(50.0, $activity[1]);
        $this->assertSame(75.0, $activity[2]);
        $this->assertGreaterThan($activity[1], $activity[2]);
    }

    public function test_months_not_yet_reached_are_null_and_the_current_one_is_partial(): void
    {
        $this->sub(1, 'active', '2026-08-05 10:00:00');

        $cells = collect((new MetricsService)->cohortRetention(6)['rows'])->firstWhere('cohort', '2026-08')['cells'];

        $this->assertFalse($cells[0]['partial']); // August
        $this->assertFalse($cells[1]['partial']); // September
        $this->assertTrue($cells[2]['partial']);  // October, in progress
        $this->assertNull($cells[3]);             // November has not happened
    }
}
