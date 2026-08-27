<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenure-at-churn: how long the subscribers lost in a period had lasted.
 *
 * The property under test is that this splits exactly the same population the
 * churn rate counts — the bucket total must always equal `churned_in_period`,
 * or the panel would be describing a different set of people than the headline
 * number sitting above it.
 */
class TenureAtChurnTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null, ?string $email = null, int $customerId = 0): void
    {
        Record::create([
            'id' => $id,
            'import_id' => null,
            'record_type' => 'shop_subscription',
            'status' => $status,
            'date_created_gmt' => $created,
            'ended_at' => $ended,
            'total_amount' => 0,
            'subscription_id' => null,
            'customer_id' => $customerId,
            'order_relationship' => null,
            'billing_email' => $email,
        ]);
    }

    private function june(): array
    {
        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    private function order(int $id, int $subscriptionId, string $created, float $amount, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id,
            'import_id' => null,
            'record_type' => 'shop_order',
            'status' => $status,
            'date_created_gmt' => $created,
            'ended_at' => null,
            'total_amount' => $amount,
            'subscription_id' => $subscriptionId,
            'customer_id' => 0,
            'order_relationship' => 'renewal',
            'billing_email' => null,
        ]);
    }

    private function lostInJune(): array
    {
        return $this->metrics->churnedSubscriptions('2026-06-01 00:00:00', '2026-07-01 00:00:00');
    }

    private function seedJuneLeavers(): void
    {
        // Four subscriptions ending in June, one per bucket boundary.
        $this->sub(1, 'cancelled', '2026-05-20 00:00:00', '2026-06-10 00:00:00'); // 21d  -> 0-30
        $this->sub(2, 'cancelled', '2026-04-15 00:00:00', '2026-06-10 00:00:00'); // 56d  -> 31-60
        $this->sub(3, 'expired', '2026-02-01 00:00:00', '2026-06-15 00:00:00');   // 134d -> 91-180
        $this->sub(4, 'cancelled', '2025-06-01 00:00:00', '2026-06-20 00:00:00'); // 384d -> 181+
    }

    public function test_leavers_are_bucketed_by_how_long_they_had_been_subscribed(): void
    {
        $this->seedJuneLeavers();

        $tenure = $this->june()['tenure_at_churn'];
        $counts = array_column($tenure['buckets'], 'count', 'label');

        $this->assertSame([1, 1, 0, 1, 1], array_values($counts));
        $this->assertSame(4, $tenure['total']);
    }

    public function test_the_buckets_add_up_to_the_period_churn_count(): void
    {
        $this->seedJuneLeavers();

        // Somebody who ends in July must not leak into June's tenure panel.
        $this->sub(5, 'cancelled', '2026-01-01 00:00:00', '2026-07-04 00:00:00');
        // Nor may a subscription that is still running.
        $this->sub(6, 'active', '2026-01-01 00:00:00');

        $june = $this->june();

        $this->assertSame(
            $june['churned_in_period'],
            array_sum(array_column($june['tenure_at_churn']['buckets'], 'count')),
        );
        $this->assertSame(4, $june['churned_in_period']);
    }

    public function test_median_averages_the_two_middles_on_an_even_count(): void
    {
        $this->seedJuneLeavers();

        // Tenures are 21, 56, 134, 384 -> middles 56 and 134.
        $this->assertSame(95, $this->june()['tenure_at_churn']['median_days']);
    }

    public function test_shares_are_expressed_against_the_leavers_not_all_subscribers(): void
    {
        $this->seedJuneLeavers();
        // A long-standing active subscriber is no part of the denominator.
        $this->sub(7, 'active', '2024-01-01 00:00:00');

        $pcts = array_column($this->june()['tenure_at_churn']['buckets'], 'pct');

        $this->assertSame(100.0, array_sum($pcts));
    }

    public function test_part_days_are_floored_so_bucket_edges_do_not_drift(): void
    {
        // 30 days and 18 hours: still inside day 31, so the 31-60 bucket.
        $this->sub(1, 'cancelled', '2026-05-15 06:00:00', '2026-06-15 00:00:00');

        $tenure = $this->june()['tenure_at_churn'];

        $this->assertSame(30, $tenure['median_days']);
        $this->assertSame([1, 0, 0, 0, 0], array_column($tenure['buckets'], 'count'));
    }

    public function test_median_is_a_whole_number_of_days(): void
    {
        // Odd hours on both ends - the median must not come back fractional.
        $this->sub(1, 'cancelled', '2026-05-01 09:30:00', '2026-06-04 17:45:00');
        $this->sub(2, 'cancelled', '2026-03-02 11:15:00', '2026-06-08 03:20:00');
        $this->sub(3, 'cancelled', '2026-04-10 22:05:00', '2026-06-01 08:10:00');

        $median = $this->june()['tenure_at_churn']['median_days'];

        $this->assertIsInt($median);
        $this->assertSame(51, $median); // 10 Apr 22:05 -> 1 Jun 08:10 = 51.4 days
    }

    public function test_a_period_with_no_churn_reports_empty_buckets_not_a_missing_panel(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');

        $tenure = $this->june()['tenure_at_churn'];

        $this->assertSame(0, $tenure['total']);
        $this->assertNull($tenure['median_days']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($tenure['buckets'], 'count'));
    }

    public function test_the_drill_down_lists_exactly_the_subscriptions_the_rate_counts(): void
    {
        $this->seedJuneLeavers();
        $this->sub(5, 'cancelled', '2026-01-01 00:00:00', '2026-07-04 00:00:00'); // July, not June
        $this->sub(6, 'active', '2026-01-01 00:00:00');                            // never left

        $june = $this->june();
        $list = $this->metrics->churnedSubscriptions('2026-06-01 00:00:00', '2026-07-01 00:00:00');

        $this->assertSame($june['churned_in_period'], $list['total']);

        // Membership, not order: rows are sorted by end date and two of these
        // share one, so the tie-break is not part of the contract.
        $ids = array_column($list['rows'], 'id');
        sort($ids);
        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_the_drill_down_flags_subscriptions_that_joined_and_left_in_the_same_period(): void
    {
        // In the churn numerator, but never in the base the rate divides by.
        $this->sub(1, 'cancelled', '2026-06-05 00:00:00', '2026-06-20 00:00:00');
        $this->sub(2, 'cancelled', '2026-01-10 00:00:00', '2026-06-20 00:00:00');

        $rows = collect($this->metrics->churnedSubscriptions('2026-06-01 00:00:00', '2026-07-01 00:00:00')['rows'])
            ->keyBy('id');

        $this->assertTrue($rows[1]['joined_in_period']);
        $this->assertFalse($rows[2]['joined_in_period']);
    }

    public function test_the_drill_down_caps_rows_but_still_reports_the_true_total(): void
    {
        $this->seedJuneLeavers();

        $list = $this->metrics->churnedSubscriptions('2026-06-01 00:00:00', '2026-07-01 00:00:00', limit: 2);

        // A capped list that also reported a capped total would read as "only
        // two people churned".
        $this->assertSame(4, $list['total']);
        $this->assertSame(2, $list['returned']);
        $this->assertCount(2, $list['rows']);
    }

    public function test_endpoint_returns_the_churned_subscriptions_for_the_period(): void
    {
        $this->seedJuneLeavers();

        $this->getJson('/api/metrics/churned-subscriptions?granularity=month&year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonCount(4, 'rows')
            ->assertJsonPath('period.label', 'June 2026');
    }

    public function test_export_streams_a_row_per_churned_subscription(): void
    {
        $this->seedJuneLeavers();

        $res = $this->get('/api/metrics/churned-subscriptions/export?granularity=month&year=2026&month=6');
        $res->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringContainsString('Subscription ID', $csv);
        // Header + four leavers.
        $this->assertCount(5, array_filter(explode('
', trim($csv))));
    }

    public function test_a_customer_who_signs_up_again_after_leaving_is_flagged_as_returned(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'back@example.com');
        $this->sub(2, 'active', '2026-06-25 00:00:00', null, 'back@example.com');

        $rows = collect($this->lostInJune()['rows'])->keyBy('id');

        $this->assertTrue($rows[1]['returned']);
        $this->assertSame(2, $rows[1]['returned_subscription_id']);
        $this->assertSame(15, $rows[1]['days_to_return']);
        $this->assertSame('active', $rows[1]['returned_status']);
    }

    public function test_email_matching_ignores_case_and_surrounding_space(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'Back@Example.com ');
        $this->sub(2, 'active', '2026-06-25 00:00:00', null, 'back@example.com');

        $this->assertTrue(collect($this->lostInJune()['rows'])->firstWhere('id', 1)['returned']);
    }

    public function test_a_second_plan_started_before_the_end_is_not_a_return(): void
    {
        // Overlapping subscription: they were running two at once, then one
        // ended. Reading that as leaving-and-returning would be wrong.
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'two@example.com');
        $this->sub(2, 'active', '2026-03-01 00:00:00', null, 'two@example.com');

        $this->assertFalse(collect($this->lostInJune()['rows'])->firstWhere('id', 1)['returned']);
    }

    public function test_a_same_day_restart_is_flagged_as_a_switch(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 09:00:00', 'switch@example.com');
        $this->sub(2, 'active', '2026-06-10 09:05:00', null, 'switch@example.com');

        $row = collect($this->lostInJune()['rows'])->firstWhere('id', 1);

        $this->assertTrue($row['returned']);
        $this->assertTrue($row['same_day_switch']);
        $this->assertSame(1, $this->lostInJune()['summary']['same_day_switches']);
    }

    public function test_a_customer_with_no_email_is_unknown_rather_than_a_no(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00');

        $data = $this->lostInJune();

        $this->assertNull($data['rows'][0]['returned']);
        $this->assertSame(1, $data['summary']['unidentifiable']);
        $this->assertSame(0, $data['summary']['identifiable']);
        // No identifiable customers means no rate, not a rate of zero.
        $this->assertNull($data['summary']['rate']);
    }

    public function test_customers_without_an_email_fall_back_to_the_customer_id(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', null, 77);
        $this->sub(2, 'active', '2026-06-20 00:00:00', null, null, 77);

        $this->assertTrue(collect($this->lostInJune()['rows'])->firstWhere('id', 1)['returned']);
    }

    public function test_the_win_back_rate_holds_unidentifiable_customers_out_of_the_denominator(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'a@example.com');
        $this->sub(2, 'active', '2026-06-20 00:00:00', null, 'a@example.com');
        $this->sub(3, 'cancelled', '2026-01-10 00:00:00', '2026-06-11 00:00:00', 'b@example.com');
        $this->sub(4, 'cancelled', '2026-01-10 00:00:00', '2026-06-12 00:00:00'); // no identity

        $summary = $this->lostInJune()['summary'];

        // 1 of the 2 identifiable came back; the third is held out entirely.
        $this->assertSame(2, $summary['identifiable']);
        $this->assertSame(1, $summary['unidentifiable']);
        $this->assertSame(1, $summary['resubscribed']);
        $this->assertSame(50.0, $summary['rate']);
    }

    public function test_the_win_back_summary_covers_the_period_not_just_the_visible_page(): void
    {
        // Four leavers, all of whom returned; only two rows are requested.
        foreach ([1, 2, 3, 4] as $i) {
            $this->sub($i, 'cancelled', '2026-01-10 00:00:00', '2026-06-1'.$i.' 00:00:00', "c{$i}@example.com");
            $this->sub($i + 10, 'active', '2026-06-25 00:00:00', null, "c{$i}@example.com");
        }

        $data = $this->metrics->churnedSubscriptions('2026-06-01 00:00:00', '2026-07-01 00:00:00', limit: 2);

        $this->assertCount(2, $data['rows']);
        // A rate computed from the truncated page would still say 100%, so the
        // count is what proves the summary saw all four.
        $this->assertSame(4, $data['summary']['resubscribed']);
        $this->assertSame(4, $data['total']);
    }

    public function test_a_win_back_that_cancelled_again_is_not_counted_as_recovered(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'gone@example.com');
        $this->sub(2, 'cancelled', '2026-06-20 00:00:00', '2026-06-28 00:00:00', 'gone@example.com');

        $data = $this->lostInJune();
        $row = collect($data['rows'])->firstWhere('id', 1);

        $this->assertTrue($row['returned']);
        $this->assertSame('cancelled', $row['returned_status']);
        // They came back, but they are not a recovered customer.
        $this->assertSame(1, $data['summary']['resubscribed']);
        $this->assertSame(0, $data['summary']['still_active']);
    }

    public function test_churn_is_priced_by_what_each_leaver_was_last_paying(): void
    {
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'paid@example.com');
        $this->order(100, 1, '2026-03-01 00:00:00', 10.00);
        $this->order(101, 1, '2026-05-01 00:00:00', 25.00); // the price when they left

        $data = $this->lostInJune();

        $this->assertSame(25.00, $data['rows'][0]['last_payment']);
        $this->assertSame(25.00, $data['summary']['recurring_lost']);
        $this->assertSame(35.00, $data['summary']['lifetime_spend_lost']);
    }

    public function test_recovered_revenue_counts_only_win_backs_that_are_still_running(): void
    {
        // Left paying 20, came back and is still active.
        $this->sub(1, 'cancelled', '2026-01-10 00:00:00', '2026-06-10 00:00:00', 'a@example.com');
        $this->order(100, 1, '2026-05-01 00:00:00', 20.00);
        $this->sub(2, 'active', '2026-06-20 00:00:00', null, 'a@example.com');

        // Left paying 30, came back and cancelled again.
        $this->sub(3, 'cancelled', '2026-01-10 00:00:00', '2026-06-11 00:00:00', 'b@example.com');
        $this->order(101, 3, '2026-05-01 00:00:00', 30.00);
        $this->sub(4, 'cancelled', '2026-06-21 00:00:00', '2026-06-30 00:00:00', 'b@example.com');

        $summary = $this->lostInJune()['summary'];

        $this->assertSame(50.00, $summary['recurring_lost']);
        $this->assertSame(20.00, $summary['recurring_recovered']);
    }

    public function test_a_leaver_who_never_completed_an_order_is_priced_at_zero(): void
    {
        $this->sub(1, 'cancelled', '2026-06-01 00:00:00', '2026-06-10 00:00:00', 'free@example.com');

        $data = $this->lostInJune();

        $this->assertSame(0.0, $data['rows'][0]['last_payment']);
        $this->assertSame(0.0, $data['summary']['recurring_lost']);
    }

    public function test_tenure_falls_back_to_the_last_linked_order_without_an_end_date(): void
    {
        // No `ended_at`: the last order is the most recent proof it was live.
        $this->sub(1, 'cancelled', '2026-05-01 00:00:00');
        Record::create([
            'id' => 100,
            'import_id' => null,
            'record_type' => 'shop_order',
            'status' => 'completed',
            'date_created_gmt' => '2026-06-12 00:00:00',
            'ended_at' => null,
            'total_amount' => 10,
            'subscription_id' => 1,
            'order_relationship' => 'renewal',
            'billing_email' => null,
        ]);

        $tenure = $this->june()['tenure_at_churn'];

        // 1 May -> 12 June is 42 days, so the 31-60 bucket.
        $this->assertSame(1, $tenure['total']);
        $this->assertSame(42, $tenure['median_days']);
        $this->assertSame([0, 1, 0, 0, 0], array_column($tenure['buckets'], 'count'));
    }
}
