<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-4: one definition of "still active", so Overview, Acquisition and Cohorts
 * reconcile to the same number.
 */
class StillActiveDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
        $this->seedBook();
    }

    private function sub(int $id, string $status, string $created, ?string $source, ?string $ended = null): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'ended_at' => $ended, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'utm_source' => $source, 'billing_period' => 'month', 'billing_interval' => 1,
        ]);
        Record::create([
            'id' => 10000 + $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $created, 'total_amount' => 20, 'subscription_id' => $id,
            'order_relationship' => 'parent', 'billing_email' => null,
        ]);
    }

    /**
     * Twenty months of sign-ups across a big source, a mid-sized one and a
     * scatter of one-offs; some cancelled, some on hold, one whose live
     * status disagrees with its imported history.
     */
    private function seedBook(): void
    {
        $id = 1;
        $start = CarbonImmutable::parse('2025-03-01 12:00:00', 'UTC');

        for ($m = 0; $m < 20; $m++) {
            $created = $start->addMonths($m)->toDateTimeString();

            $this->sub($id++, 'active', $created, 'google');
            $this->sub($id++, 'active', $created, $m % 2 ? 'fb' : 'ig');
            $this->sub($id++, 'cancelled', $created, 'google', $start->addMonths($m)->addDays(20)->toDateTimeString());
            $this->sub($id++, 'on-hold', $created, null);
            $this->sub($id++, 'active', $created, 'one-off-'.$m); // each its own tiny segment
        }

        // Active in the live column, but history says it went on hold last week.
        DB::table('subscription_status_changes')->insert([
            ['subscription_id' => 1, 'changed_at' => '2025-03-01 12:01:00', 'from_status' => 'pending', 'to_status' => 'active'],
            ['subscription_id' => 1, 'changed_at' => '2026-10-01 09:00:00', 'from_status' => 'active', 'to_status' => 'on-hold'],
        ]);
    }

    private function overviewActive(): int
    {
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth())['subscribers_active'];
    }

    public function test_acquisition_still_active_sums_to_overview_active_including_other(): void
    {
        $seg = (new MetricsService)->segmentPerformance('utm_source', 5);

        $this->assertContains('Other', array_column($seg['rows'], 'segment'));
        $this->assertSame($this->overviewActive(), array_sum(array_column($seg['rows'], 'still_active')));
        $this->assertSame($this->overviewActive(), $seg['totals']['still_active']);
        $this->assertSame(100, $seg['totals']['subs']);
    }

    public function test_cohort_still_active_sums_to_overview_active_including_earlier(): void
    {
        $rows = (new MetricsService)->cohortValue(12)['rows'];

        $this->assertCount(13, $rows);              // 12 months + "Earlier"
        $this->assertTrue(end($rows)['rollup']);
        $this->assertSame($this->overviewActive(), array_sum(array_column($rows, 'still_active')));
    }

    public function test_the_shared_rule_follows_history_over_the_live_column(): void
    {
        // 20 months × 3 live-active rows = 60, less sub 1 now on hold per history.
        $this->assertSame(59, $this->overviewActive());
    }

    public function test_is_active_is_status_active_at_the_instant(): void
    {
        $metrics = new MetricsService;
        $book = (fn () => $this->lifecycleSubs())->call($metrics);

        $this->assertFalse($metrics->isActive($book[1], '2026-10-08 11:00:00'));  // on hold since 1 Oct
        $this->assertTrue($metrics->isActive($book[1], '2026-09-15 00:00:00'));   // active before that
        $this->assertFalse($metrics->isActive($book[3], '2026-10-08 11:00:00'));  // cancelled
        $this->assertFalse($metrics->isActive($book[2], '2025-02-01 00:00:00'));  // not yet signed up
    }
}
