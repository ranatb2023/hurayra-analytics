<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-5: ARPU is MRR per paying subscriber, and the count it is divided by is
 * reported, so ARPU × count reconciles to MRR.
 */
class ArpuTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $created, int $interval = 1): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'billing_period' => 'month', 'billing_interval' => $interval,
        ]);
    }

    private function paid(int $id, int $sub, float $amount): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => '2026-09-20 10:00:00', 'total_amount' => $amount, 'subscription_id' => $sub,
            'order_relationship' => 'renewal', 'billing_email' => null,
        ]);
    }

    private function october(): array
    {
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    public function test_arpu_times_paying_subscribers_is_mrr(): void
    {
        // Prices chosen so the per-subscriber figure does not divide evenly.
        $this->sub(1, '2026-08-01 10:00:00');
        $this->paid(101, 1, 19.99);
        $this->sub(2, '2026-08-01 10:00:00', 2);
        $this->paid(102, 2, 37.50); // 18.75 a month
        $this->sub(3, '2026-08-01 10:00:00');
        $this->paid(103, 3, 21.37);
        $this->sub(4, '2026-09-30 10:00:00'); // active, never billed

        $m = $this->october();

        $this->assertSame(4, $m['subscribers_active']);
        $this->assertSame(3, $m['paying_subscribers']);
        $this->assertSame(60.11, $m['mrr']);     // 19.99 + 18.75 + 21.37
        $this->assertSame(20.0367, $m['arpu']);  // 60.11 / 3, shown as £20.04

        // To the penny: rounding ARPU to 2 dp first loses up to half a penny
        // per subscriber, which at 235 subscribers is 68p.
        $this->assertEqualsWithDelta($m['mrr'], $m['arpu'] * $m['paying_subscribers'], 0.005);
    }

    public function test_mrr_is_built_from_the_same_subscribers_as_the_active_card(): void
    {
        // Live column says active; history says it went on hold in September.
        $this->sub(1, '2026-08-01 10:00:00');
        $this->paid(101, 1, 20.0);
        DB::table('subscription_status_changes')->insert([
            'subscription_id' => 1, 'changed_at' => '2026-09-25 10:00:00', 'from_status' => 'active', 'to_status' => 'on-hold',
        ]);

        $m = $this->october();

        $this->assertSame(0, $m['subscribers_active']);
        $this->assertSame(0, $m['paying_subscribers']);
        $this->assertSame(0.0, $m['mrr']);
    }
}
