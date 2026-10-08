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

        // A and E converted. B subscribed first, C never subscribed, D never
        // bought one-off, F's only one-time order was cancelled (not a
        // purchase), and the two guest rows share no identity.
        $this->assertSame(['a@example.com', 'cid:55'], array_column($result['customers'], 'key'));
        $this->assertSame(2, $result['total']);

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
        $e = $result['customers'][1];
        $this->assertNull($e['email']);
        $this->assertSame(55, $e['customer_id']);
        $this->assertSame(30, $e['days_to_convert']);
    }

    public function test_source_comes_from_the_first_one_time_order(): void
    {
        // Two one-time orders from different channels. The list shows the first
        // order's date, so it must show that order's source - "google" in April
        // is the alphabetically later value an aggregate would have picked.
        $this->record(20, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'g@example.com', 'customer_id' => 20,
            'total_amount' => 40, 'utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring',
            'attribution_type' => 'utm']);
        $this->record(21, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-04-10 00:00:00', 'billing_email' => 'g@example.com', 'customer_id' => 20,
            'total_amount' => 40, 'utm_source' => 'google', 'utm_medium' => 'organic', 'attribution_type' => 'organic']);
        $this->record(22, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-05-01 00:00:00', 'billing_email' => 'g@example.com', 'customer_id' => 20]);

        $g = collect($this->metrics->oneTimeToSubscription()['customers'])->firstWhere('key', 'g@example.com');

        $this->assertSame('facebook', $g['one_time_source']);
        $this->assertSame('cpc', $g['one_time_medium']);
        $this->assertSame('spring', $g['one_time_campaign']);
        $this->assertSame('utm', $g['one_time_attribution']);
    }

    public function test_source_falls_back_to_the_attribution_bucket_and_then_to_null(): void
    {
        // No UTMs captured - WooCommerce's coarser bucket is all there is.
        $this->record(30, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'h@example.com', 'customer_id' => 30,
            'total_amount' => 40, 'attribution_type' => 'admin']);
        $this->record(31, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-01 00:00:00', 'billing_email' => 'h@example.com', 'customer_id' => 30]);

        // Nothing at all - null, which is not the same fact as "(direct)".
        $this->record(32, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'i@example.com', 'customer_id' => 31,
            'total_amount' => 40]);
        $this->record(33, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-01 00:00:00', 'billing_email' => 'i@example.com', 'customer_id' => 31]);

        $rows = collect($this->metrics->oneTimeToSubscription()['customers']);

        $this->assertSame('admin', $rows->firstWhere('key', 'h@example.com')['one_time_source']);
        $this->assertNull($rows->firstWhere('key', 'i@example.com')['one_time_source']);
    }

    public function test_the_source_is_the_first_paid_order(): void
    {
        // The first order failed, so it was never a purchase: the list's
        // first-order date is the completed one - and so is its source.
        $this->record(40, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'failed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'j@example.com', 'customer_id' => 40,
            'total_amount' => 40, 'utm_source' => 'facebook']);
        $this->record(41, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-02-10 00:00:00', 'billing_email' => 'j@example.com', 'customer_id' => 40,
            'total_amount' => 40, 'utm_source' => 'newsletter']);
        $this->record(42, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-03-01 00:00:00', 'billing_email' => 'j@example.com', 'customer_id' => 40]);

        $paid = collect($this->metrics->oneTimeToSubscription()['customers'])->firstWhere('key', 'j@example.com');
        $this->assertSame('newsletter', $paid['one_time_source']);
        $this->assertSame('2026-02-10 00:00:00', $paid['first_one_time_at']);
    }

    public function test_summary_counts_every_one_time_buyer(): void
    {
        $this->seedJourneys();
        $s = $this->metrics->oneTimeToSubscription()['summary'];

        // A, B, C, E. F only had a cancelled order; guests have no identity.
        $this->assertSame(4, $s['one_time_customers']);
        $this->assertSame(2, $s['converted']);
        $this->assertSame(50.0, $s['conversion_rate']);
        $this->assertSame(40, $s['avg_days_to_convert']); // (50 + 30) / 2
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
        $this->assertSame(2, $result['summary']['converted']);
    }

    public function test_unpaid_one_time_orders_are_not_purchases(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription(conversionsOnly: false);

        // F's only one-time order was cancelled, so F is not a one-time buyer.
        $this->assertNull(collect($result['customers'])->firstWhere('key', 'f@example.com'));
    }

    public function test_a_customer_whose_only_one_time_order_was_free_does_not_appear(): void
    {
        $this->record(50, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-01-10 00:00:00', 'billing_email' => 'k@example.com', 'customer_id' => 50,
            'total_amount' => 0]);
        $this->record(51, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-02-01 00:00:00', 'billing_email' => 'k@example.com', 'customer_id' => 50]);

        $result = $this->metrics->oneTimeToSubscription(conversionsOnly: false);

        $this->assertSame([], $result['customers']);
        $this->assertSame(0, $result['summary']['one_time_customers']);
    }

    public function test_a_same_day_sign_up_is_not_a_conversion_unless_asked(): void
    {
        // 09:00 one-off, 09:05 subscription: one checkout, not a conversion.
        $this->record(60, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-05-10 08:00:00', 'billing_email' => 'l@example.com', 'customer_id' => 60,
            'total_amount' => 12]);
        $this->record(61, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-05-10 08:05:00', 'billing_email' => 'l@example.com', 'customer_id' => 60]);

        $default = $this->metrics->oneTimeToSubscription();
        $this->assertSame(0, $default['summary']['converted']);
        $this->assertSame(0.0, $default['summary']['conversion_rate']);

        $withSameDay = $this->metrics->oneTimeToSubscription(includeSameDay: true);
        $this->assertSame(1, $withSameDay['summary']['converted']);
        $this->assertSame(0, $withSameDay['customers'][0]['days_to_convert']);
    }

    public function test_the_day_gap_is_a_uk_calendar_day(): void
    {
        // 23:30 BST on 10 May then 00:30 BST on 11 May: an hour apart, but the
        // next UK day, so it converts with a 1-day gap.
        $this->record(70, ['record_type' => 'shop_order', 'order_relationship' => 'one_time', 'status' => 'completed',
            'date_created_gmt' => '2026-05-10 22:30:00', 'billing_email' => 'm@example.com', 'customer_id' => 70,
            'total_amount' => 12]);
        $this->record(71, ['record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-05-10 23:30:00', 'billing_email' => 'm@example.com', 'customer_id' => 70]);

        $row = $this->metrics->oneTimeToSubscription()['customers'][0];

        $this->assertTrue($row['is_conversion']);
        $this->assertSame(1, $row['days_to_convert']);
    }

    public function test_limit_caps_rows_but_not_the_total(): void
    {
        $this->seedJourneys();
        $result = $this->metrics->oneTimeToSubscription(limit: 1);

        $this->assertCount(1, $result['customers']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['summary']['converted']);
    }

    public function test_endpoint_returns_the_list(): void
    {
        $this->seedJourneys();

        $this->getJson('/api/metrics/one-time-to-subscription')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('summary.converted', 2)
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
