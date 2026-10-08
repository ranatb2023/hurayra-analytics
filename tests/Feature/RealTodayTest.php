<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\MetricsService;
use App\Support\PeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-1: "today" is the real date, never the newest row in the data.
 */
class RealTodayTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $status, string $created, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "sub{$id}@example.test",
        ], $extra));
    }

    private function order(int $id, int $subscriptionId, string $created, float $amount = 20.0): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $created, 'total_amount' => $amount, 'subscription_id' => $subscriptionId,
            'order_relationship' => 'parent', 'billing_email' => null,
        ]);
    }

    public function test_renewal_days_are_counted_from_the_real_date_not_the_newest_row(): void
    {
        // The data stops on 1 Oct; the clock says 8 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));

        $this->sub(1, 'active', '2026-09-01 10:00:00', ['next_payment_at' => '2026-10-16 10:00:00']);
        $this->order(100, 1, '2026-10-01 00:10:00');

        $row = (new MetricsService)->upcomingRenewals(14)['rows'][0];

        // 16 Oct is 8 days after 8 Oct. Anchored on the newest row (1 Oct) it read 15.
        $this->assertSame(8, $row['days_away']);
    }

    public function test_a_renewal_later_today_reads_as_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));

        $this->sub(1, 'active', '2026-09-08 10:00:00', ['next_payment_at' => '2026-10-08 16:00:00']); // 17:00 BST
        $this->order(100, 1, '2026-09-08 10:00:00');

        $this->assertSame(0, (new MetricsService)->upcomingRenewals(14)['rows'][0]['days_away']);
    }

    public function test_a_renewal_due_before_now_is_not_in_the_pipeline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));

        // Due on the 5th: already past, so it is not "upcoming".
        $this->sub(1, 'active', '2026-09-05 10:00:00', ['next_payment_at' => '2026-10-05 10:00:00']);
        $this->order(100, 1, '2026-09-05 10:00:00');

        $this->assertSame(0, (new MetricsService)->upcomingRenewals(14)['total']);
    }

    public function test_the_snapshot_of_a_period_running_past_the_data_is_the_data_timestamp(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));

        $this->sub(1, 'active', '2026-09-01 10:00:00');
        Import::create(['original_filename' => 'orders.csv', 'status' => 'completed'])
            ->forceFill(['updated_at' => '2026-10-08 04:10:00'])->save(); // 05:10 BST

        $period = (new PeriodResolver)->resolve(['granularity' => 'month', 'year' => 2026, 'month' => 10]);
        $m = (new MetricsService)->compute($period->start, $period->end);

        $this->assertTrue($m['snapshot_capped']);
        $this->assertSame('2026-10-08 05:10:00', $m['snapshot_at']);
    }

    public function test_no_snapshot_label_is_ever_in_the_future(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
        $this->sub(1, 'active', '2026-09-01 10:00:00');

        // No import on record: capped at now.
        $period = (new PeriodResolver)->resolve(['granularity' => 'year', 'year' => 2026]);
        $m = (new MetricsService)->compute($period->start, $period->end);

        $this->assertLessThanOrEqual(
            CarbonImmutable::now('Europe/London')->toDateTimeString(),
            $m['snapshot_at'],
        );
        $this->assertSame(1, $m['subscribers_active']);
    }

    public function test_a_closed_period_is_measured_at_its_own_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
        $this->sub(1, 'active', '2026-08-01 10:00:00');

        $period = (new PeriodResolver)->resolve(['granularity' => 'month', 'year' => 2026, 'month' => 8]);
        $m = (new MetricsService)->compute($period->start, $period->end);

        $this->assertFalse($m['snapshot_capped']);
    }
}
