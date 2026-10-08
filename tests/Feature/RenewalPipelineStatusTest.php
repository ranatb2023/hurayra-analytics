<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-11: the renewal pipeline holds active subscriptions only; ones whose
 * first renewal failed are on hold and listed separately.
 */
class RenewalPipelineStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));
    }

    private function sub(int $id, string $status, string $created, ?string $next): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'billing_period' => 'month', 'billing_interval' => 1, 'next_payment_at' => $next,
        ]);
    }

    private function order(int $id, int $sub, string $at, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => $status,
            'date_created_gmt' => $at, 'total_amount' => 22.5, 'subscription_id' => $sub,
            'order_relationship' => $id % 2 ? 'renewal' : 'parent', 'billing_email' => null,
        ]);
    }

    private function seedPipeline(): void
    {
        // Active, renews on the 10th.
        $this->sub(1, 'active', '2026-09-10 10:00:00', '2026-10-10 10:00:00');
        $this->order(100, 1, '2026-09-10 10:00:00');

        // First renewal failed on the 6th; on hold, but WooCommerce still has
        // the next attempt down for the 9th.
        $this->sub(2, 'on-hold', '2026-09-06 10:00:00', '2026-10-09 10:00:00');
        $this->order(200, 2, '2026-09-06 10:00:00');
        $this->order(201, 2, '2026-10-06 10:00:00', 'failed');
        DB::table('subscription_status_changes')->insert([
            ['subscription_id' => 2, 'changed_at' => '2026-09-06 10:01:00', 'from_status' => 'pending', 'to_status' => 'active'],
            ['subscription_id' => 2, 'changed_at' => '2026-10-06 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold'],
        ]);

        // On hold after several payments: not a FIRST renewal failure.
        $this->sub(3, 'on-hold', '2026-06-01 10:00:00', '2026-10-11 10:00:00');
        foreach ([300, 302, 304] as $i => $id) {
            $this->order($id, 3, '2026-0'.(6 + $i).'-01 10:00:00');
        }
    }

    public function test_no_on_hold_subscription_appears_in_the_pipeline(): void
    {
        $this->seedPipeline();

        $r = (new MetricsService)->upcomingRenewals(14);

        $this->assertSame([1], array_column($r['rows'], 'id'));
        $this->assertSame(1, $r['total']);
        $this->assertSame(['active'], array_unique(array_column($r['rows'], 'status')));
    }

    public function test_a_failed_first_renewal_is_listed_separately(): void
    {
        $this->seedPipeline();

        $failed = (new MetricsService)->upcomingRenewals(14)['failed_first_renewal'];

        $this->assertSame(1, $failed['total']);
        $this->assertSame(2, $failed['rows'][0]['id']);
        $this->assertSame('2026-10-06 10:00:00', $failed['rows'][0]['held_at']);
        $this->assertSame(22.5, $failed['value']);
    }

    public function test_the_failed_list_exports(): void
    {
        $this->seedPipeline();

        $csv = $this->get('/api/metrics/renewals/export?days=14&list=failed_first_renewal')->streamedContent();

        $this->assertStringContainsString('On hold since', $csv);
        $this->assertStringContainsString('s2@example.test', $csv);
        $this->assertStringNotContainsString('s1@example.test', $csv);
    }
}
