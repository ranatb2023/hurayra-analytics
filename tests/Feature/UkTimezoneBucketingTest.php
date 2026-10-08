<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use App\Support\Clock;
use App\Support\PeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P0-2: every period is bucketed in Europe/London, with DST from the tz
 * database rather than a fixed offset.
 */
class UkTimezoneBucketingTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $created): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "sub{$id}@example.test",
        ]);
    }

    private function newSubsIn(int $year, int $month): int
    {
        $p = (new PeriodResolver)->resolve(['granularity' => 'month', 'year' => $year, 'month' => $month]);

        return (new MetricsService)->compute($p->start, $p->end)['new_subscribers'];
    }

    public function test_a_sub_at_2330_gmt_on_30_september_buckets_into_october(): void
    {
        $this->sub(1, '2026-09-30 23:30:00'); // 00:30 BST on 1 Oct
        $this->sub(2, '2026-09-30 22:59:59'); // 23:59 BST on 30 Sep

        $this->assertSame(1, $this->newSubsIn(2026, 9));
        $this->assertSame(1, $this->newSubsIn(2026, 10));
    }

    public function test_in_winter_gmt_and_uk_time_agree(): void
    {
        // GMT is UK time between late October and late March.
        $this->sub(1, '2026-01-31 23:30:00');

        $this->assertSame(1, $this->newSubsIn(2026, 1));
        $this->assertSame(0, $this->newSubsIn(2026, 2));
    }

    public function test_bst_starts_on_the_last_sunday_of_march(): void
    {
        // BST began at 01:00 GMT on 29 March 2026. Before it, no shift.
        $this->assertSame('2026-03-29 00:30:00', Clock::toLocal('2026-03-29 00:30:00')->toDateTimeString());
        $this->assertSame('2026-03-29 02:30:00', Clock::toLocal('2026-03-29 01:30:00')->toDateTimeString());

        $this->sub(1, '2026-03-31 23:15:00'); // 00:15 BST on 1 Apr

        $this->assertSame(0, $this->newSubsIn(2026, 3));
        $this->assertSame(1, $this->newSubsIn(2026, 4));
    }

    public function test_trend_months_are_uk_months(): void
    {
        $this->sub(1, '2026-09-30 23:30:00');
        $this->sub(2, '2026-09-15 12:00:00');
        $this->sub(3, '2026-12-31 23:30:00'); // winter: stays in December

        $trend = (new MetricsService)->trend('new_subscribers', 'month');

        $this->assertSame(['2026-09', '2026-10', '2026-12'], $trend['labels']);
        $this->assertSame([1, 1, 1], $trend['values']);
    }

    public function test_trend_years_are_uk_years(): void
    {
        // 23:30 GMT on 31 Dec is still 31 Dec in the UK (no DST in winter).
        $this->sub(1, '2026-12-31 23:30:00');
        $this->sub(2, '2027-06-30 23:30:00');

        $trend = (new MetricsService)->trend('new_subscribers', 'year');

        $this->assertSame(['2026', '2027'], $trend['labels']);
    }

    public function test_cohorts_are_uk_months(): void
    {
        $this->sub(1, '2026-09-30 23:30:00');

        $cohorts = collect((new MetricsService)->cohortValue()['rows'])->pluck('cohort')->all();

        $this->assertSame(['2026-10'], $cohorts);
    }

    public function test_the_local_sql_matches_php_across_both_transitions(): void
    {
        $driver = DB::connection()->getDriverName();
        $expr = Clock::localSql("'%s'", $driver);

        foreach (['2026-03-29 00:59:59', '2026-03-29 01:00:00', '2026-10-25 00:59:59', '2026-10-25 01:00:00', '2026-07-01 12:00:00'] as $gmt) {
            $sql = str_replace("'%s'", "'{$gmt}'", $expr);
            $local = DB::selectOne("select {$sql} as v")->v;

            $this->assertSame(Clock::toLocal($gmt)->toDateTimeString(), (string) $local, "at {$gmt}");
        }
    }
}
