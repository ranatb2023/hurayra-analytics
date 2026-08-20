<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private CsvImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CsvImportService;
    }

    public function test_header_validation_accepts_exact_columns_any_order(): void
    {
        $header = array_reverse(CsvImportService::EXPECTED_COLUMNS);
        $check = $this->service->validateHeader($header);

        $this->assertTrue($check['valid']);
    }

    public function test_header_validation_fails_on_missing_required_columns(): void
    {
        $check = $this->service->validateHeader(['id', 'record_type', 'status']);

        $this->assertFalse($check['valid']);
        $this->assertContains('total_amount', $check['missing']);
    }

    public function test_header_validation_allows_extra_columns(): void
    {
        // A real wp_wc_orders export carries more columns than we need.
        $header = array_merge(CsvImportService::EXPECTED_COLUMNS, ['customer_id', 'parent_order_id']);
        $check = $this->service->validateHeader($header);

        $this->assertTrue($check['valid']);
        $this->assertSame([], $check['missing']);
        $this->assertEqualsCanonicalizing(['customer_id', 'parent_order_id'], $check['extra']);
    }

    public function test_status_normalisation_strips_wc_prefix(): void
    {
        $this->assertSame('completed', $this->service->normaliseStatus('wc-completed'));
        $this->assertSame('on-hold', $this->service->normaliseStatus('WC-On-Hold'));
        $this->assertSame('active', $this->service->normaliseStatus('  active '));
    }

    public function test_defensive_value_parsing(): void
    {
        $this->assertNull($this->service->parseDate('0000-00-00 00:00:00'));
        $this->assertNull($this->service->parseDate('not-a-date'));
        $this->assertSame('2026-03-15 10:00:00', $this->service->parseDate('2026-03-15 10:00:00'));

        $this->assertSame(0.0, $this->service->numericOrZero(''));
        $this->assertSame(0.0, $this->service->numericOrZero('NaN'));
        $this->assertSame(49.5, $this->service->numericOrZero('49.50'));
    }

    public function test_import_normalises_and_is_idempotent(): void
    {
        $csv = $this->makeCsv([
            ['10', 'shop_subscription', 'wc-active', '2026-03-01 00:00:00', '29.00', '', 'subscription', '  USER@Example.com '],
            ['11', 'shop_order', 'wc-completed', '2026-03-02 00:00:00', '', '10', 'parent', 'user@example.com'], // blank total -> 0
            ['', 'shop_order', 'wc-completed', '2026-03-03 00:00:00', '5.00', '', 'one_time', 'x@example.com'],   // missing id -> skipped
        ]);

        $import = Import::create(['original_filename' => 'a.csv', 'status' => 'processing']);
        $this->service->import($import, $csv);

        $this->assertSame(3, $import->total_rows);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(1, $import->skipped_rows);

        $sub = Record::find(10);
        $this->assertSame('active', $sub->status);                 // wc- stripped
        $this->assertSame('user@example.com', $sub->billing_email); // trimmed + lowercased

        $order = Record::find(11);
        $this->assertSame('0.00', (string) $order->total_amount);   // blank -> 0

        // Re-import the same file: no duplicates (upsert on id).
        $import2 = Import::create(['original_filename' => 'a.csv', 'status' => 'processing']);
        $this->service->import($import2, $csv);

        $this->assertSame(2, Record::count());

        @unlink($csv);
    }

    public function test_import_maps_by_name_with_extra_and_reordered_columns(): void
    {
        // Header in a different order, with extra columns interleaved.
        $header = ['parent_order_id', 'id', 'billing_email', 'customer_id', 'order_relationship', 'status', 'date_created_gmt', 'total_amount', 'record_type', 'subscription_id'];
        $path = tempnam(sys_get_temp_dir(), 'csv_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, $header);
        fputcsv($fh, ['99', '500', 'Buyer@Example.com', '7', 'one_time', 'wc-completed', '2026-03-01 12:00:00', '125.50', 'shop_order', '']);
        fclose($fh);

        $import = Import::create(['original_filename' => 'wide.csv', 'status' => 'processing']);
        $this->service->import($import, $path);

        $r = Record::find(500);
        $this->assertSame('shop_order', $r->record_type);
        $this->assertSame('completed', $r->status);
        $this->assertSame('one_time', $r->order_relationship);
        $this->assertSame('buyer@example.com', $r->billing_email);
        $this->assertSame('125.50', (string) $r->total_amount);

        @unlink($path);
    }

    public function test_end_date_column_is_read_for_ended_subscriptions_only(): void
    {
        // `date_cancelled_gmt` is one of the optional end-date spellings.
        $header = array_merge(CsvImportService::EXPECTED_COLUMNS, ['date_cancelled_gmt']);
        $path = tempnam(sys_get_temp_dir(), 'csv_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, $header);
        // Cancelled subscription: the end date is kept.
        fputcsv($fh, ['601', 'shop_subscription', 'wc-cancelled', '2026-01-10 00:00:00', '20.00', '', 'subscription', 'a@example.com', '2026-06-20 09:00:00']);
        // Live subscription: a stray stamp is NOT an end date.
        fputcsv($fh, ['602', 'shop_subscription', 'wc-active', '2026-01-11 00:00:00', '20.00', '', 'subscription', 'b@example.com', '2026-06-21 09:00:00']);
        // Orders never carry one.
        fputcsv($fh, ['603', 'shop_order', 'wc-completed', '2026-01-12 00:00:00', '20.00', '601', 'renewal', 'a@example.com', '2026-06-22 09:00:00']);
        // An end date before sign-up is clamped to the sign-up date.
        fputcsv($fh, ['604', 'shop_subscription', 'wc-expired', '2026-02-01 00:00:00', '20.00', '', 'subscription', 'c@example.com', '2025-12-01 09:00:00']);
        fclose($fh);

        $import = Import::create(['original_filename' => 'ends.csv', 'status' => 'processing']);
        $this->service->import($import, $path);

        $this->assertSame('2026-06-20 09:00:00', Record::find(601)->ended_at->toDateTimeString());
        $this->assertNull(Record::find(602)->ended_at);
        $this->assertNull(Record::find(603)->ended_at);
        $this->assertSame('2026-02-01 00:00:00', Record::find(604)->ended_at->toDateTimeString());

        @unlink($path);
    }

    public function test_end_date_is_optional(): void
    {
        // The canonical header has no end-date column at all.
        $path = $this->makeCsv([
            ['700', 'shop_subscription', 'wc-cancelled', '2026-01-10 00:00:00', '20.00', '', 'subscription', 'a@example.com'],
        ]);

        $import = Import::create(['original_filename' => 'plain.csv', 'status' => 'processing']);
        $this->service->import($import, $path);

        $this->assertNull(Record::find(700)->ended_at);

        @unlink($path);
    }

    /** Write a temporary CSV with the canonical header + given rows; return its path. */
    private function makeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, CsvImportService::EXPECTED_COLUMNS);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }
}
