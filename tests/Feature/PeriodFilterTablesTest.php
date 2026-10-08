<?php

namespace Tests\Feature;

use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every table follows the period filter, not just the cards.
 */
class PeriodFilterTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));

        // Two sign-ups in August (google), one in September (fb); each pays.
        foreach ([[1, '2026-08-05', 'google'], [2, '2026-08-20', 'google'], [3, '2026-09-10', 'fb']] as [$id, $day, $source]) {
            Record::create([
                'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
                'date_created_gmt' => "{$day} 10:00:00", 'total_amount' => 0, 'subscription_id' => null,
                'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
                'customer_id' => 10 + $id, 'utm_source' => $source,
            ]);
            Record::create([
                'id' => 100 + $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
                'date_created_gmt' => "{$day} 10:00:00", 'total_amount' => 10 * $id, 'subscription_id' => $id,
                'order_relationship' => 'parent', 'billing_email' => "s{$id}@example.test", 'customer_id' => 10 + $id,
            ]);
        }
    }

    private const AUGUST = 'granularity=month&year=2026&month=8';

    private const SEPTEMBER = 'granularity=month&year=2026&month=9';

    public function test_acquisition_shows_the_periods_sign_ups(): void
    {
        $aug = $this->getJson('/api/metrics/segments?dimension=utm_source&min=1&'.self::AUGUST)->json();
        $sep = $this->getJson('/api/metrics/segments?dimension=utm_source&min=1&'.self::SEPTEMBER)->json();

        $this->assertSame(['google'], array_column($aug['rows'], 'segment'));
        $this->assertSame(2, $aug['totals']['subs']);
        $this->assertSame(['fb'], array_column($sep['rows'], 'segment'));
    }

    public function test_acquisition_all_time_ignores_the_period(): void
    {
        $all = $this->getJson('/api/metrics/segments?dimension=utm_source&min=1&scope=all&'.self::AUGUST)->json();

        $this->assertSame(3, $all['totals']['subs']);
    }

    public function test_cohort_tables_stop_at_the_selected_month(): void
    {
        $aug = $this->getJson('/api/metrics/cohort-value?'.self::AUGUST)->json('rows');
        $this->assertSame(['2026-08'], array_column($aug, 'cohort'));

        $sep = $this->getJson('/api/metrics/cohorts?'.self::SEPTEMBER)->json('rows');
        $this->assertSame(['2026-08', '2026-09'], array_column($sep, 'cohort'));
    }

    public function test_history_ends_at_the_selected_month(): void
    {
        $rows = $this->getJson('/api/metrics/history?months=3&'.self::AUGUST)->json('rows');

        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_column($rows, 'month'));
    }

    public function test_top_customers_are_ranked_on_spend_in_the_period(): void
    {
        $aug = $this->getJson('/api/metrics/top-customers?'.self::AUGUST)->json('customers');

        $this->assertSame([12, 11], array_column($aug, 'customer_id')); // 20 then 10; #13 spent in Sept
    }

    public function test_without_a_period_the_tables_are_all_time(): void
    {
        $this->assertCount(3, $this->getJson('/api/metrics/top-customers')->json('customers'));
        $this->assertSame(3, $this->getJson('/api/metrics/segments?dimension=utm_source&min=1')->json('totals.subs'));
    }

    public function test_one_time_to_subscription_follows_the_first_one_off_purchase(): void
    {
        foreach ([[201, 'a@example.test', '2026-08-02', '2026-08-10'], [202, 'b@example.test', '2026-09-03', '2026-09-20']] as [$id, $email, $bought, $subscribed]) {
            Record::create([
                'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
                'date_created_gmt' => "{$bought} 10:00:00", 'total_amount' => 15, 'subscription_id' => null,
                'order_relationship' => 'one_time', 'billing_email' => $email,
            ]);
            Record::create([
                'id' => $id + 100, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
                'date_created_gmt' => "{$subscribed} 10:00:00", 'total_amount' => 0, 'subscription_id' => null,
                'order_relationship' => 'subscription', 'billing_email' => $email,
            ]);
        }

        $aug = $this->getJson('/api/metrics/one-time-to-subscription?'.self::AUGUST)->json();

        $this->assertSame(['a@example.test'], array_column($aug['customers'], 'email'));
        $this->assertSame(1, $aug['summary']['one_time_customers']);
    }
}
