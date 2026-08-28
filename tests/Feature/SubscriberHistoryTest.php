<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Point-in-time subscriber counts and monthly churn.
 *
 * The property under test is that a finished month's numbers never move: a
 * customer who was an active subscriber in March stays in March's count even
 * after they cancel in June.
 */
class SubscriberHistoryTest extends TestCase
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
            'ended_at' => null,
            'order_relationship' => null,
            'billing_email' => null,
        ], $attrs));
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null): void
    {
        $this->record($id, [
            'record_type' => 'shop_subscription',
            'status' => $status,
            'date_created_gmt' => $created,
            'ended_at' => $ended,
        ]);
    }

    private function seedDataset(): void
    {
        // S1 still running.
        $this->sub(1, 'active', '2026-01-10 00:00:00');
        // S2 was a subscriber all through spring, cancelled in June.
        $this->sub(2, 'cancelled', '2026-01-15 00:00:00', '2026-06-20 00:00:00');
        // S3 joined in March, cancelled in April.
        $this->sub(3, 'cancelled', '2026-03-05 00:00:00', '2026-04-10 00:00:00');
        // S4 on hold - a live state with no history, never counted as active.
        $this->sub(4, 'on-hold', '2026-02-01 00:00:00');
        // S5 cancelled with NO end date: the last linked order is the fallback.
        $this->sub(5, 'cancelled', '2026-01-05 00:00:00');
        $this->record(105, [
            'record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'completed',
            'date_created_gmt' => '2026-05-12 00:00:00', 'subscription_id' => 5, 'total_amount' => 10,
        ]);

        // Anchors churnSeries()'s trailing month at July 2026.
        $this->record(106, [
            'record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-07-04 00:00:00', 'total_amount' => 25,
        ]);
    }

    private function month(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    public function test_active_subscribers_include_everyone_who_cancelled_later(): void
    {
        // As of 1 April: S1 (active), S2 (ends June), S3 (ends 10 April),
        // S5 (last order 12 May). S4 is on hold.
        $this->assertSame(4, $this->month(2026, 3)['subscribers_active']);
    }

    public function test_a_finished_month_does_not_move_when_someone_cancels_later(): void
    {
        $before = $this->month(2026, 3)['subscribers_active'];

        // S1 cancels in August - long after March closed.
        Record::where('id', 1)->update(['status' => 'cancelled', 'ended_at' => '2026-08-14 00:00:00']);

        $this->assertSame($before, $this->month(2026, 3)['subscribers_active']);
    }

    public function test_subscribers_drop_out_of_months_after_they_end(): void
    {
        // As of 1 August only S1 is left: S2 ended in June, S3 in April,
        // S5's last order was in May.
        $this->assertSame(1, $this->month(2026, 7)['subscribers_active']);
    }

    public function test_monthly_churn_rate_is_leavers_over_the_opening_base(): void
    {
        $june = $this->month(2026, 6);

        // Active on 1 June: S1 and S2 (S3 ended in April, S5 in May). S2 leaves.
        $this->assertSame(2, $june['active_at_period_start']);
        $this->assertSame(1, $june['churned_in_period']);
        $this->assertSame(50.0, $june['monthly_churn_rate']);
    }

    public function test_monthly_churn_rate_is_null_without_an_opening_base(): void
    {
        // Nobody had signed up before January 2026.
        $january = $this->month(2026, 1);

        $this->assertSame(0, $january['active_at_period_start']);
        $this->assertNull($january['monthly_churn_rate']);
    }

    public function test_churn_series_reports_a_fixed_month_by_month_history(): void
    {
        $rows = collect($this->metrics->churnSeries(6)['rows'])->keyBy('month');

        // Trailing six months anchored on the newest record (July 2026).
        $this->assertSame(['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'], $rows->keys()->all());

        $this->assertSame(3, $rows['2026-03']['active_start']);  // S1, S2, S5
        $this->assertSame(1, $rows['2026-03']['new']);           // S3
        $this->assertSame(0, $rows['2026-03']['churned']);
        $this->assertSame(4, $rows['2026-03']['active_end']);    // + S3

        $this->assertSame(1, $rows['2026-04']['churned']);       // S3
        $this->assertSame(25.0, $rows['2026-04']['churn_rate']); // 1 of 4

        $this->assertSame(1, $rows['2026-06']['churned']);       // S2
        $this->assertSame(50.0, $rows['2026-06']['churn_rate']); // 1 of 2

        $this->assertSame(1, $rows['2026-07']['active_start']);  // S1 only
        $this->assertSame(0, $rows['2026-07']['churned']);
    }

    public function test_churn_series_history_is_stable_across_later_cancellations(): void
    {
        $before = collect($this->metrics->churnSeries(6)['rows'])->keyBy('month');

        Record::where('id', 1)->update(['status' => 'cancelled', 'ended_at' => '2026-07-20 00:00:00']);

        $after = collect($this->metrics->churnSeries(6)['rows'])->keyBy('month');

        foreach (['2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->assertSame(
                $before[$month]['active_start'],
                $after[$month]['active_start'],
                "{$month} changed after a July cancellation",
            );
        }

        // Only the month the cancellation actually happened in moves.
        $this->assertSame(1, $after['2026-07']['churned']);
    }

    public function test_end_date_coverage_reports_how_much_timing_is_imported(): void
    {
        // Of the three cancelled subs, two carry a real ended_at.
        $this->assertSame(66.7, $this->month(2026, 7)['end_date_coverage']);
    }

    public function test_endpoint_returns_the_churn_series(): void
    {
        $response = $this->getJson('/api/metrics/churn?months=6');

        $response->assertOk()
            ->assertJsonPath('rows.0.month', '2026-02')
            ->assertJsonCount(6, 'rows');
    }

    public function test_the_month_the_data_stops_inside_is_flagged_as_partial(): void
    {
        // The newest record is 4 July, so July is still filling up and every
        // month before it is closed. Charting July as a finished month draws a
        // fall that is only missing rows.
        $rows = collect($this->metrics->churnSeries(6)['rows'])->keyBy('month');

        $this->assertTrue($rows['2026-07']['partial']);

        foreach (['2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $this->assertFalse($rows[$month]['partial'], "{$month} should be closed");
        }
    }

    public function test_history_joins_the_rate_series_onto_the_subscriber_months(): void
    {
        $history = collect($this->metrics->history(6)['rows'])->keyBy('month');
        $churn = collect($this->metrics->churnSeries(6)['rows'])->keyBy('month');
        $rates = collect($this->metrics->retentionSeries(6)['rows'])->keyBy('month');

        $this->assertSame($churn->keys()->all(), $history->keys()->all());

        foreach ($history as $month => $row) {
            // One row per month carrying both walks, so a chart and the table
            // under it cannot disagree about the same month.
            $this->assertSame($churn[$month]['active_end'], $row['active_end']);
            $this->assertSame($churn[$month]['churn_rate'], $row['churn_rate']);
            $this->assertSame($rates[$month]['churn_net'], $row['churn_net']);
            $this->assertSame($rates[$month]['nrr'], $row['nrr']);
        }
    }

    public function test_history_endpoint_returns_the_merged_series(): void
    {
        $response = $this->getJson('/api/metrics/history?months=6');

        $response->assertOk()
            ->assertJsonCount(6, 'rows')
            ->assertJsonPath('rows.0.month', '2026-02')
            ->assertJsonPath('rows.5.partial', true)
            ->assertJsonStructure(['rows' => [['month', 'active_start', 'new', 'churned', 'active_end',
                'churn_rate', 'partial', 'churn_net', 'nrr']], 'end_date_coverage']);
    }
}
