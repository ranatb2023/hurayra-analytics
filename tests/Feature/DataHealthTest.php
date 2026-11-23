<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\DataHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P3-3: the Data Health page lists, counts and exports each problem.
 */
class DataHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
        $this->seedProblems();
    }

    private function row(int $id, string $type, string $status, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => $type, 'status' => $status,
            'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 20, 'subscription_id' => null,
            'order_relationship' => $type === 'shop_order' ? 'one_time' : 'subscription', 'billing_email' => null,
        ], $extra));
    }

    private function seedProblems(): void
    {
        // An order with a subscription status, and a normal one.
        $this->row(5209, 'shop_order', 'active');
        $this->row(1, 'shop_order', 'completed', ['utm_source' => 'google', 'utm_medium' => 'organic']);

        // A £0 completed order.
        $this->row(2, 'shop_order', 'completed', ['total_amount' => 0, 'billing_email' => 'free@example.test']);

        // One customer, two active subs to the same product.
        foreach ([10, 11] as $id) {
            $this->row($id, 'shop_subscription', 'active', ['billing_email' => 'twice@example.test', 'utm_source' => 'snapchat.com']);
            $this->row(100 + $id, 'shop_order', 'completed', ['subscription_id' => $id, 'order_relationship' => 'parent',
                'primary_product' => 'Chicken (HPF001)']);
        }

        // On hold for 50 days (history) and 10 days (history).
        $this->row(20, 'shop_subscription', 'on-hold');
        $this->row(21, 'shop_subscription', 'on-hold');
        DB::table('subscription_status_changes')->insert([
            ['subscription_id' => 20, 'changed_at' => '2026-08-19 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold'],
            ['subscription_id' => 21, 'changed_at' => '2026-09-28 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold'],
        ]);
    }

    private function section(string $key): array
    {
        return app(DataHealthService::class)->section($key);
    }

    public function test_invalid_status_rows_are_listed(): void
    {
        $s = $this->section('invalid_status');

        $this->assertSame(1, $s['count']);
        $this->assertSame(5209, $s['rows'][0]['id']);
        $this->assertSame('wc-active', $s['rows'][0]['status']);
    }

    public function test_zero_value_completed_orders_are_listed(): void
    {
        $this->assertSame([2], array_column($this->section('zero_completed')['rows'], 'id'));
    }

    public function test_duplicate_active_subscriptions_to_one_product_are_listed(): void
    {
        $rows = $this->section('duplicate_active')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('twice@example.test', $rows[0]['customer']);
        $this->assertSame(2, $rows[0]['subscriptions']);
    }

    public function test_unmapped_sources_are_listed(): void
    {
        $this->assertSame(['snapchat.com'], array_column($this->section('unmapped_sources')['rows'], 'source'));
    }

    public function test_on_hold_subscriptions_are_bucketed_by_age(): void
    {
        $s = $this->section('on_hold_age');

        $this->assertSame(['0–15 days' => 1, '16–30 days' => 0, '31–45 days' => 0, '45+ days' => 1], $s['buckets']);
        $this->assertSame(50, $s['rows'][0]['days']);
    }

    public function test_status_counts_compare_source_and_dashboard(): void
    {
        $rows = collect($this->section('status_counts')['rows'])->keyBy(fn ($r) => $r['type'].' '.$r['status']);

        $this->assertSame(0, $rows['shop_order wc-active']['dashboard']);
        $this->assertSame(1, $rows['shop_order wc-active']['source']);
        $this->assertStringStartsWith('excluded', $rows['shop_order wc-active']['note']);
        $this->assertSame('match', $rows['shop_order wc-completed']['note']);
    }

    public function test_the_page_renders_and_every_section_exports(): void
    {
        $this->get('/data-health')->assertOk()->assertSee('Invalid status for type')->assertSee('On-hold subscriptions by age');

        foreach (array_keys(app(DataHealthService::class)->sections()) as $key) {
            $response = $this->get("/data-health/{$key}/export");
            $response->assertOk();
            $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));
        }

        $this->assertStringContainsString('5209', $this->get('/data-health/invalid_status/export')->streamedContent());
        $this->get('/data-health/nope/export')->assertNotFound();
    }
}
