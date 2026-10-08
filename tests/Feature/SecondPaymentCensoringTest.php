<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-9: "reached 2nd payment" only judges subscriptions whose second payment
 * was due by now.
 */
class SecondPaymentCensoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));
    }

    private function sub(int $id, string $created, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => $created, 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
            'utm_source' => 'Klaviyo', 'billing_period' => 'month', 'billing_interval' => 1,
        ], $extra));
    }

    private function paid(int $id, int $sub, string $at): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => $at, 'total_amount' => 20, 'subscription_id' => $sub,
            'order_relationship' => 'renewal', 'billing_email' => null,
        ]);
    }

    private function klaviyo(): array
    {
        return collect((new MetricsService)->segmentPerformance('utm_source', 1)['rows'])->firstWhere('segment', 'Klaviyo');
    }

    public function test_a_sub_signed_up_yesterday_is_not_in_the_denominator(): void
    {
        // Old enough: one reached its second payment, one did not.
        $this->sub(1, '2026-07-01 10:00:00');
        $this->paid(101, 1, '2026-07-01 10:00:00');
        $this->paid(102, 1, '2026-08-01 10:00:00');
        $this->sub(2, '2026-07-02 10:00:00');
        $this->paid(201, 2, '2026-07-02 10:00:00');

        // Signed up yesterday: its second payment is a month away.
        $this->sub(3, '2026-10-07 10:00:00', ['next_payment_at' => '2026-11-07 10:00:00']);
        $this->paid(301, 3, '2026-10-07 10:00:00');

        $row = $this->klaviyo();

        $this->assertSame(3, $row['subs']);
        $this->assertSame(2, $row['eligible']);
        $this->assertSame(50.0, $row['repeat_pct']); // 1 of 2, not 1 of 3
    }

    public function test_the_scheduled_next_payment_decides_for_a_first_cycle_sub(): void
    {
        // Six-weekly plan, signed up 40 days ago: one "month" has passed but
        // WooCommerce has the second payment down for next week.
        $this->sub(1, '2026-08-29 10:00:00', [
            'billing_period' => 'week', 'billing_interval' => 6, 'next_payment_at' => '2026-10-10 10:00:00',
        ]);
        $this->paid(101, 1, '2026-08-29 10:00:00');

        $row = $this->klaviyo();

        $this->assertSame(0, $row['eligible']);
        $this->assertNull($row['repeat_pct']);
    }

    public function test_an_overdue_second_payment_counts_as_not_reached(): void
    {
        $this->sub(1, '2026-08-01 10:00:00', ['status' => 'on-hold', 'next_payment_at' => '2026-09-01 10:00:00']);
        $this->paid(101, 1, '2026-08-01 10:00:00');

        $row = $this->klaviyo();

        $this->assertSame(1, $row['eligible']);
        $this->assertSame(0.0, $row['repeat_pct']);
    }
}
