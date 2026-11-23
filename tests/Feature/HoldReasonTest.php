<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use App\Services\StatusHistoryImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Why a subscription is on hold: paused manually, or paused by WooCommerce
 * because a renewal payment is due. On Hold is split into the two, and the
 * two always add up to On Hold.
 */
class HoldReasonTest extends TestCase
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

    private function sub(int $id, string $status, string $created): void
    {
        Record::create([
            'id' => $id, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 0,
        ]);
    }

    private function order(int $id, int $sub, string $status, string $rel, string $created): void
    {
        Record::create([
            'id' => $id, 'record_type' => 'shop_order', 'status' => $status, 'subscription_id' => $sub,
            'order_relationship' => $rel, 'date_created_gmt' => $created, 'total_amount' => 10,
        ]);
    }

    private function change(int $sub, string $at, string $from, string $to, ?string $reason = null): void
    {
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => $sub, 'changed_at' => $at, 'from_status' => $from, 'to_status' => $to, 'hold_reason' => $reason,
        ]);
    }

    private function month(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0);

        return $this->metrics->compute($start, $start->addMonth());
    }

    // ---- Reading the reason out of the note --------------------------------

    public function test_a_renewal_payment_due_note_is_payment_due(): void
    {
        $this->assertSame('payment_due', $this->history->holdReason('', 'Subscription renewal payment due: Status changed from Active to On hold.'));
    }

    public function test_any_other_hold_note_is_manual(): void
    {
        $this->assertSame('manual', $this->history->holdReason('', 'Status changed from Active to On hold.'));
        $this->assertSame('manual', $this->history->holdReason('', 'Subscription status changed by bulk edit: Status changed from Active to On hold.'));
        $this->assertSame('manual', $this->history->holdReason('', 'Subscription put on hold by subscriber from their account page. Status changed from Active to On hold.'));
    }

    public function test_an_explicit_column_wins_and_no_note_stays_unknown(): void
    {
        $this->assertSame('manual', $this->history->holdReason('manual', 'Subscription renewal payment due: Status changed from Active to On hold.'));
        $this->assertNull($this->history->holdReason('', ''));
    }

    public function test_only_changes_to_on_hold_carry_a_reason(): void
    {
        $index = ['subscription_id' => 0, 'changed_at' => 1, 'note' => 2];

        $held = $this->history->normaliseRow(['7', '2026-03-01 10:00:00', 'Subscription renewal payment due: Status changed from Active to On hold.'], $index, null);
        $resumed = $this->history->normaliseRow(['7', '2026-03-02 10:00:00', 'Status changed from On hold to Active.'], $index, null);

        $this->assertSame('payment_due', $held['hold_reason']);
        $this->assertNull($resumed['hold_reason']);
    }

    // ---- The split ---------------------------------------------------------

    public function test_on_hold_splits_into_manual_and_payment_due(): void
    {
        $this->sub(1, 'on-hold', '2026-01-05 10:00:00');
        $this->change(1, '2026-03-10 10:00:00', 'active', 'on-hold', 'manual');

        $this->sub(2, 'on-hold', '2026-01-05 10:00:00');
        $this->change(2, '2026-03-12 10:00:00', 'active', 'on-hold', 'payment_due');

        $this->sub(3, 'on-hold', '2026-01-05 10:00:00');
        $this->change(3, '2026-03-15 10:00:00', 'active', 'on-hold', 'payment_due');

        $m = $this->month(2026, 3);

        $this->assertSame(3, $m['on_hold']);
        $this->assertSame(1, $m['on_hold_manual']);
        $this->assertSame(2, $m['on_hold_payment_due']);
    }

    public function test_the_latest_hold_decides_the_reason(): void
    {
        $this->sub(1, 'on-hold', '2026-01-05 10:00:00');
        $this->change(1, '2026-02-10 10:00:00', 'active', 'on-hold', 'payment_due');
        $this->change(1, '2026-02-11 10:00:00', 'on-hold', 'active');
        $this->change(1, '2026-03-10 10:00:00', 'active', 'on-hold', 'manual');

        $m = $this->month(2026, 3);
        $this->assertSame(1, $m['on_hold_manual']);
        $this->assertSame(0, $m['on_hold_payment_due']);

        // The February payment hold resumed on the 11th: nobody on hold at that month end.
        $this->assertSame(0, $this->month(2026, 2)['on_hold']);
    }

    public function test_without_a_note_an_unpaid_renewal_means_payment_due(): void
    {
        // No status history at all: the reason comes from the orders.
        $this->sub(1, 'on-hold', '2026-01-05 10:00:00');
        $this->order(101, 1, 'completed', 'parent', '2026-01-05 10:00:00');
        $this->order(102, 1, 'pending', 'renewal', '2026-02-05 10:00:00');

        $this->sub(2, 'on-hold', '2026-01-05 10:00:00');
        $this->order(201, 2, 'completed', 'parent', '2026-01-05 10:00:00');

        $reasons = $this->metrics->holdReasons('2026-12-01 00:00:00');

        $this->assertSame(['reason' => 'payment_due', 'source' => 'estimate'], $reasons[1]);
        $this->assertSame(['reason' => 'manual', 'source' => 'estimate'], $reasons[2]);
    }

    public function test_the_on_hold_list_names_each_subscriber_and_their_reason(): void
    {
        $this->sub(1, 'on-hold', '2026-01-05 10:00:00');
        $this->change(1, '2026-03-10 10:00:00', 'active', 'on-hold', 'manual');
        $this->sub(2, 'on-hold', '2026-01-05 10:00:00');
        $this->change(2, '2026-03-02 10:00:00', 'active', 'on-hold', 'payment_due');
        $this->sub(3, 'active', '2026-01-05 10:00:00');

        $res = $this->getJson('/api/metrics/on-hold?granularity=month&year=2026&month=3')->assertOk();

        $this->assertSame(2, $res->json('total'));
        $this->assertSame(1, $res->json('manual'));
        $this->assertSame(1, $res->json('payment_due'));
        // Longest on hold first.
        $this->assertSame([2, 1], array_column($res->json('rows'), 'id'));
        $this->assertSame('payment_due', $res->json('rows.0.reason'));
        $this->assertSame('2026-03-02 10:00', $res->json('rows.0.on_hold_since'));

        $csv = $this->get('/api/metrics/on-hold/export?granularity=month&year=2026&month=3')->assertOk()->streamedContent();
        $this->assertStringContainsString('Payment pending', $csv);
        $this->assertStringContainsString('Paused manually', $csv);
    }

    public function test_the_split_always_adds_up_to_on_hold(): void
    {
        foreach (range(1, 6) as $id) {
            $this->sub($id, 'on-hold', '2026-01-05 10:00:00');
            $this->change($id, '2026-03-0'.$id.' 10:00:00', 'active', 'on-hold', $id % 2 ? 'manual' : 'payment_due');
        }
        // On hold, but with an untagged history row: falls back to the orders.
        $this->sub(7, 'on-hold', '2026-01-05 10:00:00');
        $this->change(7, '2026-03-20 10:00:00', 'active', 'on-hold');

        $m = $this->month(2026, 3);

        $this->assertSame(7, $m['on_hold']);
        $this->assertSame($m['on_hold'], $m['on_hold_manual'] + $m['on_hold_payment_due']);
    }
}
