<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-3: a subscription is churned only when its status is terminal AND its
 * end date has happened. A future scheduled end is a pending cancellation.
 */
class ActualEndDatesTest extends TestCase
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

    private function october(): array
    {
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    public function test_a_future_scheduled_end_is_not_churn(): void
    {
        $this->sub(1, 'active', '2026-08-01 10:00:00');
        $this->sub(2, 'cancelled', '2026-08-01 10:00:00', '2026-10-25 10:00:00'); // scheduled, not yet

        $oct = $this->october();

        $this->assertSame(0, $oct['churned_in_period']);
        $this->assertSame(1, $oct['scheduled_cancellations']);
        $this->assertSame(1, $oct['pending_cancellation']);
        $this->assertSame(1, $oct['subscribers_active']); // sub 2's status is terminal now
    }

    public function test_an_end_date_that_has_happened_is_churn(): void
    {
        $this->sub(1, 'cancelled', '2026-08-01 10:00:00', '2026-10-06 18:00:00');

        $oct = $this->october();

        $this->assertSame(1, $oct['churned_in_period']);
        $this->assertSame(0, $oct['scheduled_cancellations']);
    }

    public function test_no_churn_is_counted_after_the_data_timestamp(): void
    {
        // The data was taken at 05:10 BST on 8 Oct; a cancellation dated later
        // that day cannot be in it.
        Import::create(['original_filename' => 'orders.csv', 'status' => 'completed'])
            ->forceFill(['updated_at' => '2026-10-08 04:10:00'])->save();
        $this->sub(1, 'cancelled', '2026-08-01 10:00:00', '2026-10-08 09:00:00');

        $this->assertSame(0, $this->october()['churned_in_period']);

        $history = collect((new MetricsService)->history(2)['rows'])->keyBy('month');
        $this->assertSame(0, $history['2026-10']['churned']);
    }

    public function test_the_history_and_the_drill_down_agree_on_future_ends(): void
    {
        Import::create(['original_filename' => 'orders.csv', 'status' => 'completed']);
        $this->sub(1, 'cancelled', '2026-08-01 10:00:00', '2026-10-05 10:00:00'); // real
        $this->sub(2, 'expired', '2026-08-01 10:00:00', '2026-11-03 10:00:00');   // scheduled

        $metrics = new MetricsService;
        $history = collect($metrics->history(3)['rows'])->keyBy('month');
        $list = $metrics->churnedSubscriptions('2026-09-30 23:00:00', '2026-11-30 23:00:00');

        // The walk ends at the data timestamp (October), and the scheduled
        // end is in no month at all.
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $history->keys()->all());
        $this->assertSame(1, $history->sum('churned'));
        $this->assertSame(1, $history['2026-10']['churned']);
        $this->assertSame([1], array_column($list['rows'], 'id'));
    }

    public function test_a_scheduled_end_still_counts_as_active_in_earlier_months(): void
    {
        $this->sub(1, 'cancelled', '2026-08-01 10:00:00', '2026-10-25 10:00:00');

        $sep = (new MetricsService)->compute(
            CarbonImmutable::parse('2026-09-01 00:00:00', 'Europe/London'),
            CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London'),
        );

        $this->assertSame(1, $sep['subscribers_active']);
        $this->assertSame(0, $sep['churned_in_period']);
    }
}
