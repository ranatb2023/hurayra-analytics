<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\MetricsService;
use App\Services\StatusHistoryImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Imported WooCommerce status history ("Status changed from X to Y" notes).
 *
 * The orders export only knows each subscription's status today, so a hold
 * that has since ended leaves no trace. With the history imported, every past
 * month reads the status the subscription was really in on the day.
 */
class StatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    private StatusHistoryImportService $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
        $this->history = new StatusHistoryImportService;
    }

    private function sub(int $id, string $status, string $created, ?string $ended = null): void
    {
        Record::create([
            'id' => $id, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'ended_at' => $ended, 'total_amount' => 0,
        ]);
    }

    private function change(int $sub, string $at, string $from, string $to): void
    {
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => $sub, 'changed_at' => $at, 'from_status' => $from, 'to_status' => $to,
        ]);
    }

    private function month(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    // ---- Parsing ----------------------------------------------------------

    public function test_the_statuses_are_read_out_of_the_note(): void
    {
        $this->assertSame(['active', 'on-hold'], $this->history->parseNote('Status changed from Active to On hold.'));
        $this->assertSame(['pending-cancel', 'cancelled'], $this->history->parseNote('Status changed from Pending Cancellation to Cancelled.'));
        $this->assertSame(['pending', 'active'], $this->history->parseNote('Status changed from Pending payment to Active.'));
    }

    public function test_a_reason_before_the_sentence_is_ignored(): void
    {
        $this->assertSame(
            ['active', 'on-hold'],
            $this->history->parseNote('Subscription renewal payment due: Status changed from Active to On hold.'),
        );
    }

    public function test_a_note_that_is_not_a_status_change_is_rejected(): void
    {
        $this->assertNull($this->history->parseNote('Payment failed. Card declined.'));
        $this->assertNull($this->history->parseNote('Status changed from Active to Somewhere else.'));
    }

    // ---- Import -----------------------------------------------------------

    public function test_the_history_file_is_recognised_by_its_header(): void
    {
        $this->assertTrue($this->history->isHistoryHeader(['subscription_id', 'changed_at', 'note']));
        $this->assertTrue($this->history->isHistoryHeader(['Subscription_ID', 'changed_at', 'from_status', 'to_status']));
        $this->assertFalse($this->history->isHistoryHeader(['id', 'record_type', 'status', 'subscription_id']));
    }

    public function test_importing_the_file_twice_does_not_duplicate_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hist');
        file_put_contents($path, implode("\n", [
            'subscription_id,changed_at,note',
            '7,"2026-03-05 10:00:00","Status changed from Active to On hold."',
            '7,"2026-04-20 10:00:00","Subscription reactivated: Status changed from On hold to Active."',
            '7,"2026-04-21 10:00:00","Customer added a note."',
        ]));

        foreach ([1, 2] as $_) {
            $import = Import::create(['original_filename' => 'history.csv', 'status' => 'processing']);
            $this->history->import($import, $path);
        }

        unlink($path);

        $this->assertSame(2, DB::table('subscription_status_changes')->count());
        $this->assertSame(1, $import->refresh()->skipped_rows);
        $this->assertSame(
            ['on-hold', 'active'],
            DB::table('subscription_status_changes')->orderBy('changed_at')->pluck('to_status')->all(),
        );
    }

    // ---- Metrics ----------------------------------------------------------

    public function test_a_hold_that_has_since_ended_shows_in_the_months_it_covered(): void
    {
        // Active today, but was on hold from 5 March to 20 April.
        $this->sub(1, 'active', '2026-01-10 00:00:00');
        $this->change(1, '2026-01-10 00:05:00', 'pending', 'active');
        $this->change(1, '2026-03-05 10:00:00', 'active', 'on-hold');
        $this->change(1, '2026-04-20 10:00:00', 'on-hold', 'active');

        $feb = $this->month(2026, 2);
        $mar = $this->month(2026, 3);
        $apr = $this->month(2026, 4);

        $this->assertSame([1, 0], [$feb['subscribers_active'], $feb['on_hold']]);
        $this->assertSame([0, 1], [$mar['subscribers_active'], $mar['on_hold']]);
        $this->assertSame([1, 0], [$apr['subscribers_active'], $apr['on_hold']]);
    }

    public function test_pending_cancellation_is_dated_from_history(): void
    {
        $this->sub(2, 'cancelled', '2026-01-15 00:00:00', '2026-06-20 00:00:00');
        $this->change(2, '2026-05-10 00:00:00', 'active', 'pending-cancel');
        $this->change(2, '2026-06-20 00:00:00', 'pending-cancel', 'cancelled');

        $apr = $this->month(2026, 4);
        $may = $this->month(2026, 5);
        $jun = $this->month(2026, 6);

        $this->assertSame([1, 0], [$apr['subscribers_active'], $apr['pending_cancellation']]);
        $this->assertSame([0, 1], [$may['subscribers_active'], $may['pending_cancellation']]);
        $this->assertSame([0, 0, 1], [$jun['subscribers_active'], $jun['pending_cancellation'], $jun['subscription_status_breakdown']['cancelled']]);
    }

    public function test_a_pending_signup_is_dated_from_history(): void
    {
        // Sat unpaid in pending until its first payment on 3 March.
        $this->sub(3, 'active', '2026-02-20 00:00:00');
        $this->change(3, '2026-03-03 00:00:00', 'pending', 'active');

        $feb = $this->month(2026, 2);

        $this->assertSame([0, 1], [$feb['subscribers_active'], $feb['subscription_status_breakdown']['pending']]);
        $this->assertSame(1, $this->month(2026, 3)['subscribers_active']);
    }

    public function test_subscriptions_without_history_keep_the_export_rules(): void
    {
        $this->sub(4, 'active', '2026-01-01 00:00:00');
        $this->sub(5, 'cancelled', '2026-01-01 00:00:00', '2026-03-15 00:00:00');

        // Another subscription's history must not change how these read.
        $this->sub(1, 'active', '2026-01-10 00:00:00');
        $this->change(1, '2026-03-05 10:00:00', 'active', 'on-hold');
        $this->change(1, '2026-04-20 10:00:00', 'on-hold', 'active');

        $this->assertSame(3, $this->month(2026, 2)['subscribers_active']); // 4, 5, 1
        $this->assertSame(1, $this->month(2026, 3)['subscribers_active']); // 4 only
        $this->assertSame(1, $this->month(2026, 3)['subscription_status_breakdown']['cancelled']);
    }

    public function test_the_month_walk_reads_the_history_too(): void
    {
        $this->sub(1, 'active', '2026-01-10 00:00:00');
        $this->change(1, '2026-03-05 10:00:00', 'active', 'on-hold');
        $this->change(1, '2026-04-20 10:00:00', 'on-hold', 'active');

        // Anchors the walk's trailing month at May 2026.
        Record::create([
            'id' => 900, 'record_type' => 'shop_order', 'status' => 'completed', 'order_relationship' => 'one_time',
            'date_created_gmt' => '2026-05-04 00:00:00', 'total_amount' => 10,
        ]);

        $rows = collect($this->metrics->churnSeries(4)['rows'])->keyBy('month');

        $this->assertSame(1, $rows['2026-03']['paused']);  // went on hold
        $this->assertSame(0, $rows['2026-03']['active_end']);
        $this->assertSame(-1, $rows['2026-04']['paused']); // came back
        $this->assertSame(1, $rows['2026-04']['active_end']);
        $this->assertSame(0, $rows['2026-04']['churned']);
    }

    public function test_the_hold_estimate_is_not_used_when_history_exists(): void
    {
        $this->sub(6, 'on-hold', '2026-01-10 00:00:00');
        $this->change(6, '2026-02-14 00:00:00', 'active', 'on-hold');

        $this->assertSame([], $this->metrics->holdStarts());
        $this->assertSame(1, $this->month(2026, 1)['subscribers_active']);
        $this->assertSame(1, $this->month(2026, 2)['on_hold']);
    }
}
