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
 * P2: labels and UX consistency.
 */
class LabelsTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $status, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
        ], $extra));
    }

    private function october(): array
    {
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    // ---- P2-1 ---------------------------------------------------------------

    public function test_awaiting_payment_and_pending_cancellation_are_separate_figures(): void
    {
        $this->sub(1, 'pending');
        $this->sub(2, 'pending');
        $this->sub(3, 'pending-cancel');

        $m = $this->october();

        $this->assertSame(2, $m['awaiting_payment']);
        $this->assertSame(1, $m['pending_cancellation']);
    }

    public function test_both_labels_are_on_the_dashboard(): void
    {
        $this->sub(1, 'active');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Awaiting payment', $html);
        $this->assertStringContainsString('Pending cancellation', $html);
        // Overview (status mix chips) and Subscribers (state chips) both carry them.
        $this->assertGreaterThanOrEqual(2, substr_count($html, "value('awaiting_payment')"));
        $this->assertGreaterThanOrEqual(2, substr_count($html, "value('pending_cancellation')"));
    }

    // ---- P2-2 ---------------------------------------------------------------

    public function test_the_overview_renewals_card_leads_with_the_total_and_value(): void
    {
        $this->sub(1, 'active');

        $html = $this->get('/')->assertOk()->getContent();
        $headline = substr($html, strpos($html, 'data-renewals-headline'), 300);

        $this->assertStringContainsString('renewals.total', $headline);
        $this->assertStringContainsString('money(renewals.value)', $headline);
        $this->assertStringContainsString('at first renewal', $html);
    }

    // ---- P2-3 ---------------------------------------------------------------

    public function test_campaign_lists_have_one_row_per_customer(): void
    {
        // One household, two single-flavour plans; another with one.
        foreach ([[1, 'home@example.test'], [2, 'home@example.test'], [3, 'solo@example.test']] as [$id, $email]) {
            $this->sub($id, 'active', ['billing_email' => $email, 'customer_id' => 40 + $id]);
            Record::create([
                'id' => 100 + $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
                'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 20, 'subscription_id' => $id,
                'order_relationship' => 'parent', 'billing_email' => $email, 'primary_product' => 'Chicken (HPF001)',
            ]);
        }

        $a = (new MetricsService)->audience('cross_sell');

        $this->assertSame(2, $a['total']);          // customers, for the header
        $this->assertSame(3, $a['subscriptions']);
        $home = collect($a['rows'])->firstWhere('customer', 'home@example.test');
        $this->assertSame(2, $home['subscriptions']);
        $this->assertSame([1, 2], $home['subscription_ids']);
        $this->assertSame(40.0, $home['value']);

        $csv = $this->get('/api/metrics/audience/export?audience=cross_sell')->streamedContent();
        $this->assertSame(1, substr_count($csv, 'home@example.test'));
    }

    // ---- P2-4 ---------------------------------------------------------------

    public function test_overview_acquisition_card_greys_out_small_samples(): void
    {
        $this->sub(1, 'active', ['utm_source' => 'fb', 'date_created_gmt' => '2026-01-01 10:00:00']);

        $row = (new MetricsService)->segmentPerformance('utm_source', 1)['rows'][0];
        $this->assertFalse($row['reliable']); // n = 1 < 30

        $html = $this->get('/')->assertOk()->getContent();
        $card = substr($html, strpos($html, 'data-segment-row'), 900);

        $this->assertStringContainsString("r.reliable ? '' : 'opacity-50'", $card);
        $this->assertStringContainsString('n too small', $card);
    }

    // ---- P2-5 ---------------------------------------------------------------

    public function test_a_literal_null_from_the_export_is_imported_as_null(): void
    {
        $import = Import::create(['original_filename' => 'o.csv', 'status' => 'processing']);
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, implode("\n", [
            'id,record_type,status,date_created_gmt,total_amount,subscription_id,order_relationship,billing_email,utm_source,utm_medium,attribution_type',
            '1,shop_subscription,wc-active,2026-09-01 10:00:00,0,NULL,subscription,NULL,(direct),NULL,typein',
        ]));

        (new CsvImportService)->import($import, $path);
        unlink($path);

        $row = Record::find(1);
        $this->assertNull($row->utm_medium);
        $this->assertNull($row->billing_email);
        $this->assertSame('(direct)', $row->utm_source);
    }

    public function test_a_stored_null_string_renders_as_none(): void
    {
        // Rows imported before the fix still carry the text "NULL".
        $this->sub(1, 'active', ['utm_medium' => 'NULL']);
        $this->sub(2, 'active', ['utm_medium' => null]);

        $rows = (new MetricsService)->segmentPerformance('utm_medium', 1)['rows'];

        $this->assertSame(['(none)'], array_column($rows, 'segment'));
        $this->assertSame(2, $rows[0]['subs']);

        $trend = (new MetricsService)->trend('new_subscribers', 'month', null, 'utm_medium');
        $this->assertSame(['(none)'], array_column($trend['series'], 'label'));
    }

    public function test_the_upsell_list_never_carries_null_text(): void
    {
        Record::create([
            'id' => 10, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => '2026-05-01 10:00:00', 'total_amount' => 12, 'subscription_id' => null,
            'order_relationship' => 'one_time', 'billing_email' => 'n@example.test',
            'utm_source' => 'NULL', 'utm_medium' => 'NULL', 'attribution_type' => 'typein',
        ]);
        $this->sub(11, 'active', ['billing_email' => 'n@example.test', 'date_created_gmt' => '2026-06-01 10:00:00']);

        $row = (new MetricsService)->oneTimeToSubscription()['customers'][0];

        $this->assertSame('typein', $row['one_time_source']);
        $this->assertNull($row['one_time_medium']);
    }
}
