<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "one-time buyer -> subscriber" upsell list: who bought a one-off product
 * first and later took out a subscription, with both dates.
 */
class OneTimeToSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function record(int $id, array $attrs): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'status' => 'completed', 'total_amount' => 0,
            'subscription_id' => null, 'customer_id' => null, 'order_relationship' => null, 'billing_email' => null,
        ], $attrs));
    }

    private function seedJourneys(): void
    {
        // --- A: one-time (Jan 10) then subscribed (Mar 1) -> the classic conversion.
        $this->record(1, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'a@example.com', 'customer_id' => 10, 'total_amount' => 100]);
        $this->record(2, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-03-01 00:00:00', 'billing_email' => 'a@example.com', 'customer_id' => 10, 'total_amount' => 40]);
        $this->record(3, ['record_type' => 'shop_order', 'order_relationship' => 'parent', 'status' => 'completed',
            'date_created_gmt' => '2026-03-01 00:00:00', 'subscription_id' => 2, 'billing_email' => 'a@example.com', 'customer_id' => 10, 'total_amount' => 40]);
        $this->record(4, ['record_type' => 'shop_order', 'order_relationship' => 'renewal', 'status' => 'completed',
            'date_created_gmt' => '2026-04-01 00:00:00', 'subscription_id' => 2, 'billing_email' => 'a@example.com', 'customer_id' => 10, 'total_amount' => 60]);

        // --- B: subscribed first (Jan 5), one-time later -> not a conversion.
        $this->record(5, ['record_type' => 'shop_subscription', 'status' => 'cancelled',
            'date_created_gmt' => '2026-01-05 00:00:00', 'billing_email' => 'b@example.com', 'customer_id' => 11]);
        $this->record(6, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-02-01 00:00:00', 'billing_email' => 'b@example.com', 'customer_id' => 11, 'total_amount' => 20]);

        // --- C: one-time only, never subscribed.
        $this->record(7, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-02-02 00:00:00', 'billing_email' => 'c@example.com', 'customer_id' => 12, 'total_amount' => 30]);

        // --- D: subscriber who never bought one-off.
        $this->record(8, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-03 00:00:00', 'billing_email' => 'd@example.com', 'customer_id' => 13]);

        // --- E: no billing email -> matched on customer_id instead.
        $this->record(9, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-01 00:00:00', 'customer_id' => 55, 'total_amount' => 15]);
        $this->record(10, ['record_type' => 'shop_subscription', 'status' => 'on-hold',
            'date_created_gmt' => '2026-01-31 00:00:00', 'customer_id' => 55]);

        // --- F: only a cancelled one-time order, then subscribed.
        $this->record(11, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'cancelled',
            'date_created_gmt' => '2026-01-20 00:00:00', 'billing_email' => 'f@example.com', 'customer_id' => 14, 'total_amount' => 90]);
        $this->record(12, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-20 00:00:00', 'billing_email' => 'f@example.com', 'customer_id' => 14]);

        // --- Guests: customer_id 0 with no email must never be merged into one person.
        $this->record(13, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-02 00:00:00', 'customer_id' => 0, 'total_amount' => 25]);
        $this->record(14, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-02 00:00:00', 'customer_id' => 0]);
    }

    public function test_lists_one_time_buyers_who_later_subscribed(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription();

        // A, E and F converted. B subscribed first, C never subscribed, D never
        // bought one-off, and the two guest rows share no identity.
        $this->assertSame(['a@example.com', 'f@example.com', 'cid:55'], array_column($result['customers'], 'key'));
        $this->assertSame(3, $result['total']);

        $a = $result['customers'][0];
        $this->assertSame('2026-01-10 00:00:00', $a['first_one_time_at']);
        $this->assertSame('2026-03-01 00:00:00', $a['subscribed_at']);
        $this->assertSame(50, $a['days_to_convert']);
        $this->assertSame(1, $a['one_time_orders']);
        $this->assertSame(100.0, $a['one_time_spend']);
        $this->assertSame(2, $a['subscription_id']);
        $this->assertSame('active', $a['subscription_status']);
        $this->assertSame(100.0, $a['subscription_spend']); // parent 40 + renewal 60
        $this->assertTrue($a['is_conversion']);

        // E has no email at all - identified by customer_id.
        $e = $result['customers'][2];
        $this->assertNull($e['email']);
        $this->assertSame(55, $e['customer_id']);
        $this->assertSame(30, $e['days_to_convert']);
    }

    public function test_summary_counts_every_one_time_buyer(): void
    {
        $this->seedJourneys();
        $s = $this->metrics->oneTimeToSubscription()['summary'];

        $this->assertSame(5, $s['one_time_customers']); // A, B, C, E, F (guests have no identity)
        $this->assertSame(3, $s['converted']);
        $this->assertSame(60.0, $s['conversion_rate']);
        $this->assertSame(37, $s['avg_days_to_convert']); // (50 + 31 + 30) / 3
        $this->assertSame(100.0, $s['subscription_revenue']);
    }

    public function test_subscribed_first_customers_appear_only_when_asked(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription(conversionsOnly: false);

        $b = collect($result['customers'])->firstWhere('key', 'b@example.com');
        $this->assertNotNull($b);
        $this->assertFalse($b['is_conversion']);
        $this->assertNull($b['days_to_convert']);
        $this->assertSame('2026-01-05 00:00:00', $b['subscribed_at']);

        // The summary still only counts real conversions.
        $this->assertSame(3, $result['summary']['converted']);
    }

    public function test_completed_only_ignores_unpaid_one_time_orders(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription(completedOnly: true);

        // F's only one-time order was cancelled, so they are no longer a one-time buyer.
        $this->assertSame(['a@example.com', 'cid:55'], array_column($result['customers'], 'key'));
        $this->assertSame(4, $result['summary']['one_time_customers']);
        $this->assertSame(50.0, $result['summary']['conversion_rate']);
    }

    public function test_limit_caps_rows_but_not_the_total(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription(limit: 1);

        $this->assertCount(1, $result['customers']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['summary']['converted']);
    }

    public function test_endpoint_returns_the_list(): void
    {
        $this->seedJourneys();

        $this->getJson('/api/metrics/one-time-to-subscription')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('summary.converted', 3)
            ->assertJsonPath('customers.0.email', 'a@example.com')
            ->assertJsonPath('customers.0.subscribed_at', '2026-03-01 00:00:00');
    }

    public function test_export_streams_a_csv(): void
    {
        $this->seedJourneys();

        $response = $this->get('/api/metrics/one-time-to-subscription/export');
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('First one-time order', $csv);
        $this->assertStringContainsString('a@example.com', $csv);
        $this->assertStringContainsString('2026-03-01 00:00:00', $csv);
        $this->assertStringNotContainsString('b@example.com', $csv);
    }
}
