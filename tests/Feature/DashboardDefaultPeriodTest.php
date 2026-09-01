<?php

namespace Tests\Feature;

use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The period the dashboard opens on.
 *
 * A CSV export is always a little behind the wall clock, so a filter defaulted
 * to today's calendar month lands on a window the data has not reached: every
 * period metric reads zero while the snapshot ones (active subscribers, MRR)
 * read fine, which is indistinguishable from a failed import. The opening
 * period is therefore anchored on the newest row, the same instant every month
 * walk in MetricsService already anchors on.
 */
class DashboardDefaultPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $id, string $created): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => 'completed', 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => 10, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'one_time', 'billing_email' => null,
        ]);
    }

    public function test_it_opens_on_the_newest_month_in_the_data_not_the_current_one(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $this->order(1, '2026-07-14 10:00:00');
        $this->order(2, '2026-08-22 10:00:00'); // newest

        $this->get('/')
            ->assertOk()
            ->assertSee('defaultYear: 2026', false)
            ->assertSee('defaultMonth: 8', false);
    }

    public function test_an_empty_dataset_falls_back_to_today(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $this->get('/')
            ->assertOk()
            ->assertSee('defaultYear: 2026', false)
            ->assertSee('defaultMonth: 9', false);
    }
}
