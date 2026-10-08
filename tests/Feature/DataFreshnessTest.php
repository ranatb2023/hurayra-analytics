<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\DataFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-3: the freshness strip states how old the data is before any number is read.
 */
class DataFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $id, string $created, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 10, 'subscription_id' => null,
            'order_relationship' => 'one_time', 'billing_email' => null,
        ]);
    }

    private function at(string $london): void
    {
        $this->travelTo(CarbonImmutable::parse($london, 'Europe/London'));
    }

    public function test_the_baseline_case_is_red_and_names_1_october(): void
    {
        $this->at('2026-10-08 12:00:00');
        $this->order(21025, '2026-09-30 23:20:18'); // 00:20 BST on 1 Oct

        $f = (new DataFreshness)->summary();

        $this->assertSame('red', $f['level']);
        $this->assertSame(21025, $f['latest_order']['id']);
        $this->assertSame('2026-10-01', $f['latest_order']['created_at']->toDateString());

        $this->get('/uploads')
            ->assertOk()
            ->assertSee('data-freshness="red"', false)
            ->assertSee('#21025')
            ->assertSee('1 Oct 2026, 00:20');
    }

    public function test_amber_between_12_and_24_hours(): void
    {
        $this->at('2026-10-08 12:00:00');
        $this->order(1, '2026-10-07 21:00:00'); // 22:00 BST, 14h earlier

        $this->assertSame('amber', (new DataFreshness)->summary()['level']);
    }

    public function test_green_within_12_hours(): void
    {
        $this->at('2026-10-08 12:00:00');
        $this->order(1, '2026-10-08 08:00:00');

        $this->assertSame('green', (new DataFreshness)->summary()['level']);
    }

    public function test_dashboard_and_source_order_counts_are_both_shown(): void
    {
        $this->order(1, '2026-09-01 10:00:00');
        $this->order(2, '2026-09-02 10:00:00', 'cancelled');
        $this->order(3, '2026-09-03 10:00:00', 'active'); // a subscription status on an order
        $this->order(4, '2026-09-04 10:00:00', 'trash');  // deleted: in neither

        $f = (new DataFreshness)->summary();

        $this->assertSame(2, $f['dashboard_orders']);
        $this->assertSame(3, $f['source_orders']['count']);
    }

    public function test_an_invalid_status_order_is_never_the_latest_order(): void
    {
        $this->order(1, '2026-09-01 10:00:00');
        $this->order(2, '2026-09-05 10:00:00', 'trash');

        $this->assertSame(1, (new DataFreshness)->latestOrder()['id']);
    }

    public function test_the_strip_shows_the_last_import(): void
    {
        $this->at('2026-10-08 12:00:00');
        $this->order(1, '2026-10-08 08:00:00');
        Import::create(['original_filename' => 'orders.csv', 'status' => 'completed'])
            ->forceFill(['updated_at' => '2026-10-08 04:10:00'])->save();

        $this->get('/uploads')->assertOk()->assertSee('Last import')->assertSee('8 Oct 2026, 05:10');
    }
}
