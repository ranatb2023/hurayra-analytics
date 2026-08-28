<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\CsvImportService;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketing attribution: import, segment performance, cycle-aware dormancy and
 * the renewal pipeline.
 *
 * These columns are what let the dashboard connect retention to the spend that
 * bought it. They are all optional, so the older export must keep importing
 * unchanged — that is asserted here too.
 */
class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private MetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new MetricsService;
    }

    private function sub(int $id, string $status, string $created, array $attrs = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => 0, 'subscription_id' => null, 'customer_id' => 0,
            'order_relationship' => 'subscription', 'billing_email' => null,
        ], $attrs));
    }

    private function order(int $id, int $sub, string $created, float $amount, string $status = 'completed'): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order',
            'status' => $status, 'date_created_gmt' => $created, 'ended_at' => null,
            'total_amount' => $amount, 'subscription_id' => $sub, 'customer_id' => 0,
            'order_relationship' => 'renewal', 'billing_email' => null,
        ]);
    }

    private function csv(string $body): string
    {
        $path = sys_get_temp_dir().'/attr-'.uniqid().'.csv';
        file_put_contents($path, $body);

        return $path;
    }

    private function runImport(string $path): Import
    {
        $import = Import::create(['original_filename' => 'x.csv', 'stored_path' => $path, 'status' => 'pending']);

        return app(CsvImportService::class)->import($import, $path);
    }

    // ------------------------------------------------------------------ import

    public function test_attribution_columns_are_imported_when_present(): void
    {
        $path = $this->csv(
            'id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email,'.
            "utm_source,utm_medium,utm_campaign,device_type,attribution_type,coupon_code,discount_amount,primary_product\n".
            '1,shop_order,wc-completed,2026-06-01 00:00:00,20,,one_time,a@b.com,'.
            "google,cpc,spring_sale,Mobile,utm,SAVE10,5.50,Dry Chicken Cat Food\n"
        );

        $this->runImport($path);
        $r = Record::find(1);

        $this->assertSame('google', $r->utm_source);
        $this->assertSame('cpc', $r->utm_medium);
        $this->assertSame('spring_sale', $r->utm_campaign);
        $this->assertSame('Mobile', $r->device_type);
        $this->assertSame('SAVE10', $r->coupon_code);
        $this->assertSame('5.50', $r->discount_amount);
        $this->assertSame('Dry Chicken Cat Food', $r->primary_product);
    }

    public function test_a_file_without_the_new_columns_still_imports(): void
    {
        $path = $this->csv(
            "id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email\n".
            "1,shop_order,wc-completed,2026-06-01 00:00:00,20,,one_time,a@b.com\n"
        );

        $import = $this->runImport($path);

        $this->assertSame('completed', $import->status);
        $this->assertNull(Record::find(1)->utm_source);
    }

    public function test_a_reimport_updates_the_new_columns_on_existing_rows(): void
    {
        $head = "id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email,utm_source\n";
        $this->runImport($this->csv($head."1,shop_order,wc-completed,2026-06-01 00:00:00,20,,one_time,a@b.com,\n"));
        $this->assertNull(Record::find(1)->utm_source);

        // The upsert used to list its update columns by hand, so a column added
        // later stayed stale on every row that already existed.
        $this->runImport($this->csv($head."1,shop_order,wc-completed,2026-06-01 00:00:00,20,,one_time,a@b.com,google\n"));

        $this->assertSame('google', Record::find(1)->fresh()->utm_source);
    }

    public function test_billing_cycle_and_next_payment_are_subscription_only(): void
    {
        $path = $this->csv(
            'id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email,'.
            "billing_period,billing_interval,next_payment_at\n".
            "1,shop_subscription,wc-active,2026-06-01 00:00:00,0,,subscription,a@b.com,month,2,2026-08-01 00:00:00\n".
            "2,shop_order,wc-completed,2026-06-01 00:00:00,20,1,renewal,a@b.com,month,2,2026-08-01 00:00:00\n".
            "3,shop_subscription,wc-cancelled,2026-06-01 00:00:00,0,,subscription,c@d.com,week,6,2026-08-01 00:00:00\n"
        );

        $this->runImport($path);

        $this->assertSame('month', Record::find(1)->billing_period);
        $this->assertSame(2, Record::find(1)->billing_interval);
        $this->assertNotNull(Record::find(1)->next_payment_at);

        // A billing cycle on an order is meaningless.
        $this->assertNull(Record::find(2)->billing_period);
        // A scheduled payment on a dead subscription is stale scheduling.
        $this->assertNull(Record::find(3)->next_payment_at);
    }

    // ------------------------------------------------------- cycle-aware dormancy

    public function test_a_two_monthly_subscriber_is_not_dormant_at_fifty_days(): void
    {
        // The old fixed 45-day rule called this dead. It is one cycle in.
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00', ['billing_period' => 'month', 'billing_interval' => 2]);
        $this->order(100, 1, '2026-05-12 00:00:00', 20.0);

        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);
        $m = $this->metrics->compute($start, $start->addMonth()); // measured at 1 July, 50 days on

        $this->assertSame(0, $m['on_hold_dormant']);
    }

    public function test_a_monthly_subscriber_is_dormant_at_the_same_gap(): void
    {
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00', ['billing_period' => 'month', 'billing_interval' => 1]);
        $this->order(100, 1, '2026-05-12 00:00:00', 20.0);

        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);
        $m = $this->metrics->compute($start, $start->addMonth());

        // 50 days against a 45-day allowance (one month plus half).
        $this->assertSame(1, $m['on_hold_dormant']);
    }

    public function test_a_subscription_that_never_paid_is_measured_from_signup(): void
    {
        $this->sub(1, 'on-hold', '2026-01-01 00:00:00', ['billing_period' => 'month', 'billing_interval' => 1]);

        $start = CarbonImmutable::create(2026, 6, 1, 0, 0, 0);

        $this->assertSame(1, $this->metrics->compute($start, $start->addMonth())['on_hold_dormant']);
    }

    // --------------------------------------------------------- segment performance

    public function test_segment_performance_counts_the_parent_order_as_a_payment(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['utm_source' => 'google']);
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0); // one payment only
        $this->sub(2, 'active', '2026-01-01 00:00:00', ['utm_source' => 'google']);
        $this->order(101, 2, '2026-02-01 00:00:00', 20.0);
        $this->order(102, 2, '2026-03-01 00:00:00', 20.0); // reached a second

        $row = collect($this->metrics->segmentPerformance('utm_source', 1)['rows'])->firstWhere('segment', 'google');

        $this->assertSame(2, $row['subs']);
        $this->assertSame(0, $row['never_paid']);
        $this->assertSame(50.0, $row['repeat_pct']);
        $this->assertSame(30.0, $row['ltv']);
    }

    public function test_subscriptions_with_no_source_are_labelled_not_dropped(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00');

        $rows = $this->metrics->segmentPerformance('utm_source', 1)['rows'];

        $this->assertSame('(unattributed)', $rows[0]['segment']);
        $this->assertSame(1, $rows[0]['subs']);
    }

    public function test_an_unknown_dimension_falls_back_rather_than_reaching_the_sql(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['utm_source' => 'google']);

        // The column name is interpolated, so anything off the whitelist must
        // never reach the query.
        $result = $this->metrics->segmentPerformance('id; DROP TABLE records', 1);

        $this->assertSame('utm_source', $result['dimension']);
        $this->assertSame('google', $result['rows'][0]['segment']);
    }

    public function test_small_segments_are_held_back_as_noise(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['utm_source' => 'tiny']);

        $this->assertCount(0, $this->metrics->segmentPerformance('utm_source', 5)['rows']);
    }

    // ------------------------------------------------------------- renewal pipeline

    public function test_upcoming_renewals_flags_the_first_renewal(): void
    {
        // Anchors "now" at 1 June.
        Record::create([
            'id' => 900, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => '2026-06-01 00:00:00', 'total_amount' => 5, 'subscription_id' => null,
            'customer_id' => 0, 'order_relationship' => 'one_time', 'billing_email' => null, 'ended_at' => null,
        ]);

        $this->sub(1, 'active', '2026-05-01 00:00:00', ['next_payment_at' => '2026-06-05 00:00:00', 'billing_email' => 'first@example.com']);
        $this->order(100, 1, '2026-05-01 00:00:00', 20.0); // one payment so far

        $this->sub(2, 'active', '2026-01-01 00:00:00', ['next_payment_at' => '2026-06-06 00:00:00']);
        $this->order(101, 2, '2026-04-01 00:00:00', 20.0);
        $this->order(102, 2, '2026-05-01 00:00:00', 20.0); // established

        $r = $this->metrics->upcomingRenewals(14);

        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $r['at_first_renewal']);
        $this->assertTrue(collect($r['rows'])->firstWhere('id', 1)['first_renewal']);
        $this->assertFalse(collect($r['rows'])->firstWhere('id', 2)['first_renewal']);
    }

    public function test_cancelled_subscriptions_never_appear_in_the_pipeline(): void
    {
        Record::create([
            'id' => 900, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => '2026-06-01 00:00:00', 'total_amount' => 5, 'subscription_id' => null,
            'customer_id' => 0, 'order_relationship' => 'one_time', 'billing_email' => null, 'ended_at' => null,
        ]);

        // A stale schedule left on a dead subscription must not be dunned.
        $this->sub(1, 'cancelled', '2026-01-01 00:00:00', [
            'ended_at' => '2026-05-01 00:00:00', 'next_payment_at' => '2026-06-05 00:00:00',
        ]);

        $this->assertSame(0, $this->metrics->upcomingRenewals(14)['total']);
    }

    public function test_endpoints_return_segments_and_renewals(): void
    {
        $this->sub(1, 'active', '2026-01-01 00:00:00', ['utm_source' => 'google']);
        $this->order(100, 1, '2026-02-01 00:00:00', 20.0);

        $this->getJson('/api/metrics/segments?dimension=utm_source&min=1')
            ->assertOk()
            ->assertJsonPath('dimension', 'utm_source')
            ->assertJsonPath('rows.0.segment', 'google');

        $this->getJson('/api/metrics/renewals?days=14')->assertOk()->assertJsonStructure(['total', 'rows']);
    }
}
