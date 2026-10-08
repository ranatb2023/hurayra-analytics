<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\CsvImportService;
use App\Services\DataFreshness;
use App\Services\StatusHistoryImportService;
use App\Services\WooSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * P4-1: incremental, read-only sync from the WooCommerce database.
 */
class WooSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['woocommerce.enabled' => true, 'woocommerce.table_prefix' => 'wp_']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
    }

    /** A row shaped exactly like 04-export-with-net-revenue.sql returns it. */
    private function exportRow(int $id, string $type, string $status, string $created, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'record_type' => $type, 'status' => $status, 'customer_id' => 7,
            'billing_email' => 'Buyer@Example.test', 'date_created_gmt' => $created, 'total_amount' => '22.50',
            'subscription_id' => null, 'order_relationship' => $type === 'shop_subscription' ? 'subscription' : 'one_time',
            'ended_at' => null, 'attribution_type' => 'utm', 'utm_source' => 'google', 'utm_medium' => 'cpc',
            'utm_campaign' => null, 'device_type' => 'Mobile', 'referrer' => null, 'billing_period' => null,
            'billing_interval' => null, 'next_payment_at' => null, 'coupon_code' => null, 'discount_amount' => '0',
            'primary_product' => 'Chicken (HPF001)', 'net_amount' => '17.50', 'tax_amount' => '3.00',
            'shipping_amount' => '2.00', 'refunded_amount' => '0',
        ], $extra);
    }

    /** @param  array<int, array<string, mixed>>  $orders */
    private function syncWith(array $orders, array $notes = [], ?callable $expectSince = null): WooSyncService
    {
        $sync = Mockery::mock(WooSyncService::class, [new CsvImportService, new StatusHistoryImportService])->makePartial();
        $sync->shouldReceive('fetchOrders')->andReturnUsing(function (?string $since) use ($orders, $expectSince) {
            $expectSince && $expectSince($since);

            return $orders;
        });
        $sync->shouldReceive('fetchStatusNotes')->andReturn($notes);

        return $sync;
    }

    public function test_a_new_order_lands_in_records_and_sets_last_sync_at(): void
    {
        $sync = $this->syncWith([
            $this->exportRow(21026, 'shop_order', 'wc-completed', '2026-10-08 10:45:00'),
            $this->exportRow(21027, 'shop_subscription', 'wc-active', '2026-10-08 10:46:00', [
                'billing_period' => 'month', 'billing_interval' => '1', 'next_payment_at' => '2026-11-08 10:46:00',
            ]),
        ], [
            ['subscription_id' => 21027, 'changed_at' => '2026-10-08 10:47:00', 'note' => 'Status changed from Pending payment to Active.'],
        ]);

        $result = $sync->sync();

        $this->assertSame(2, $result['records']);
        $this->assertSame(1, $result['changes']);
        $this->assertNull($result['since']); // first run reads everything

        $order = Record::find(21026);
        $this->assertSame('completed', $order->status);
        $this->assertSame('buyer@example.test', $order->billing_email);
        $this->assertSame('17.50', (string) $order->net_amount);
        $this->assertSame('month', Record::find(21027)->billing_period);
        $this->assertSame('active', DB::table('subscription_status_changes')->value('to_status'));

        // The freshness banner reads it.
        $this->assertSame('2026-10-08 12:00:00', (new DataFreshness)->lastSyncAt()->toDateTimeString());
    }

    public function test_the_next_run_reads_only_what_changed_since_the_last_one(): void
    {
        $this->syncWith([])->sync(); // last_sync_at = 11:00 GMT

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:15:00', 'Europe/London'));

        $seen = null;
        $this->syncWith([$this->exportRow(1, 'shop_order', 'wc-completed', '2026-10-08 11:05:00')], [],
            function (?string $since) use (&$seen) { $seen = $since; })->sync();

        // 11:00 GMT less the five-minute overlap.
        $this->assertSame('2026-10-08 10:55:00', $seen);
    }

    public function test_re_reading_a_row_updates_it_rather_than_duplicating_it(): void
    {
        $this->syncWith([$this->exportRow(5, 'shop_subscription', 'wc-active', '2026-09-01 10:00:00')])->sync();
        $this->syncWith([$this->exportRow(5, 'shop_subscription', 'wc-cancelled', '2026-09-01 10:00:00', ['ended_at' => '2026-10-08 09:00:00'])])->sync();

        $this->assertSame(1, Record::count());
        $this->assertSame('cancelled', Record::find(5)->status);
    }

    public function test_a_full_sync_ignores_the_last_sync(): void
    {
        $this->syncWith([])->sync();

        $seen = 'not called';
        $this->syncWith([], [], function (?string $since) use (&$seen) { $seen = $since; })->sync(full: true);

        $this->assertNull($seen);
    }

    public function test_the_export_queries_get_the_prefix_and_the_since_bound(): void
    {
        config(['woocommerce.table_prefix' => 'hp7_']);
        $sync = app(WooSyncService::class);

        $orders = $sync->ordersSql(true);
        $this->assertStringContainsString('FROM hp7_wc_orders o', $orders);
        $this->assertStringContainsString('FROM hp7_wc_orders_meta', $orders);
        $this->assertStringNotContainsString('wp_wc_orders', $orders);
        $this->assertStringContainsString('AND o.date_updated_gmt > ?', $orders);
        $this->assertStringNotContainsString('--', $orders);
        $this->assertSame(1, substr_count($orders, '?'));

        $this->assertStringNotContainsString('date_updated_gmt > ?', $sync->ordersSql(false));
        $this->assertStringContainsString('AND c.comment_date_gmt > ?', $sync->notesSql(true));
        $this->assertStringContainsString('FROM hp7_comments c', $sync->notesSql(true));
    }

    public function test_an_unsafe_prefix_is_refused(): void
    {
        config(['woocommerce.table_prefix' => "wp_; DROP TABLE x; --"]);

        $this->expectExceptionMessage('WC_TABLE_PREFIX');
        app(WooSyncService::class)->ordersSql(true);
    }

    public function test_the_source_order_count_comes_from_woocommerce_when_connected(): void
    {
        // A stand-in WooCommerce database: SQLite in memory, same table.
        config(['database.connections.woocommerce' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('woocommerce');
        Schema::connection('woocommerce')->create('wp_wc_orders', function ($t) {
            $t->id();
            $t->string('type');
            $t->string('status');
        });
        DB::connection('woocommerce')->table('wp_wc_orders')->insert([
            ['type' => 'shop_order', 'status' => 'wc-completed'],
            ['type' => 'shop_order', 'status' => 'wc-active'],   // invalid, but WooCommerce holds it
            ['type' => 'shop_order', 'status' => 'trash'],
            ['type' => 'shop_subscription', 'status' => 'wc-active'],
        ]);

        $this->assertSame(['count' => 2, 'from' => 'WooCommerce'], (new DataFreshness)->sourceOrderCount());
        $this->assertEquals( // key order is not meaningful
            ['shop_order' => ['active' => 1, 'completed' => 1, 'trash' => 1], 'shop_subscription' => ['active' => 1]],
            (new DataFreshness)->sourceStatusCounts()['counts'],
        );
    }

    public function test_without_configuration_the_command_says_so_and_csv_still_works(): void
    {
        config(['woocommerce.enabled' => false]);

        $this->artisan('woo:sync')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertSame('last import', (new DataFreshness)->sourceOrderCount()['from']);
        $this->get('/uploads')->assertOk();
    }

    public function test_it_is_scheduled_every_15_minutes(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'woo:sync'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
