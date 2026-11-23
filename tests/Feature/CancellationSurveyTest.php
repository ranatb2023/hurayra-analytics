<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Record;
use App\Services\CancellationSurveyImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cancellation form (07-export-cancellation-form.sql): why people
 * cancelled, and what the retention discount did.
 */
class CancellationSurveyTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'survey_id,subscription_id,submitted_at,email,customer_name,reason,satisfaction,would_return,feedback,subscription_total,currency,products,retention_used,retention_percent,retention_shipping,retention_accepted_at,retention_spent_orders,retention_first_spent_at';

    private function importCsv(array $lines): Import
    {
        $path = tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($path, "\u{FEFF}".self::HEADER."\n".implode("\n", $lines)."\n");

        $import = Import::create(['original_filename' => 'cancel.csv', 'status' => 'processing']);

        return (new CancellationSurveyImportService)->import($import, $path);
    }

    private function sub(int $id, string $status): void
    {
        Record::create(['id' => $id, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => '2026-01-01 10:00:00', 'total_amount' => 0]);
    }

    private function sample(): void
    {
        $this->sub(11, 'cancelled');
        $this->sub(12, 'active');
        $this->sub(13, 'cancelled');
        $this->sub(14, 'active');

        $this->importCsv([
            // too expensive, took the discount and spent it, still active
            '101,12,2026-10-05 09:00:00,A@x.com,Ann,too-expensive,satisfied,yes,,39.60,GBP,Food,yes,37.85,4.80,1759654800,1,2026-11-05 09:00:00',
            // too expensive, declined, cancelled
            '102,11,2026-10-06 09:00:00,b@x.com,Bob,too-expensive,neutral,maybe,"Price went up, sorry",19.80,GBP,Food,no,,,,0,',
            // taking a break, survey says no but the subscription recorded the offer; cancelled anyway
            '103,13,2026-10-20 09:00:00,c@x.com,Cat,temporary,very-satisfied,yes,,19.80,GBP,Food,no,41.15,,2026-10-20 09:05:00,0,',
            // accepted with no survey on record
            ',14,,,,,,,,,,,yes,42.54,8.34,2026-10-25 12:00:00,0,',
            // outside the period
            '105,11,2026-09-01 09:00:00,d@x.com,Dan,other,neutral,no,,10,GBP,Food,no,,,,0,',
        ]);
    }

    public function test_the_file_is_recognised_even_with_a_bom(): void
    {
        $this->assertTrue((new CancellationSurveyImportService)->isSurveyHeader(explode(',', "\u{FEFF}".self::HEADER)));
        $this->assertFalse((new CancellationSurveyImportService)->isSurveyHeader(['subscription_id', 'changed_at', 'note']));
    }

    public function test_import_stores_each_submission_and_reimport_does_not_duplicate(): void
    {
        $this->sample();
        $this->assertSame(5, DB::table('cancellation_surveys')->count());

        $this->importCsv(['101,12,2026-10-05 09:00:00,a@x.com,Ann,too-expensive,satisfied,yes,,39.60,GBP,Food,yes,37.85,4.80,1759654800,1,2026-11-05 09:00:00']);
        $this->assertSame(5, DB::table('cancellation_surveys')->count());

        $row = DB::table('cancellation_surveys')->where('survey_id', 101)->first();
        $this->assertSame('a@x.com', $row->email);
        // Unix timestamp read as UTC.
        $this->assertSame('2025-10-05 09:00:00', (string) $row->retention_accepted_at);

        $noSurvey = DB::table('cancellation_surveys')->where('source_key', 'sub:14')->first();
        $this->assertNull($noSurvey->survey_id);
        $this->assertSame('2026-10-25 12:00:00', (string) $noSurvey->submitted_at);
    }

    public function test_the_period_summary_counts_reasons_and_the_retention_offer(): void
    {
        $this->sample();

        $res = $this->getJson('/api/metrics/cancellation-surveys?granularity=month&year=2026&month=10')->assertOk();

        $this->assertSame(3, $res->json('surveys'));
        $this->assertSame(1, $res->json('offers_without_survey'));
        $this->assertSame('too-expensive', $res->json('reasons.0.reason'));
        $this->assertSame('Too expensive', $res->json('reasons.0.label'));
        $this->assertSame(2, $res->json('reasons.0.count'));

        // Accepted = survey flag OR the subscription's accepted_at: 101, 103 and the no-survey 14.
        $this->assertSame(3, $res->json('retention.accepted'));
        $this->assertSame(1, $res->json('retention.declined'));
        $this->assertSame(1, $res->json('retention.spent'));
        $this->assertSame(2, $res->json('retention.stayed'));
        $this->assertSame(1, $res->json('retention.cancelled_anyway'));

        $this->assertSame(1, $res->json('satisfaction.satisfied'));
        $this->assertSame(2, $res->json('would_return.yes'));
        $this->assertCount(4, $res->json('rows'));
    }

    public function test_the_csv_export_lists_the_answers(): void
    {
        $this->sample();

        $csv = $this->get('/api/metrics/cancellation-surveys/export?granularity=month&year=2026&month=10')->assertOk()->streamedContent();

        $this->assertStringContainsString('Too expensive', $csv);
        $this->assertStringContainsString('Price went up, sorry', $csv);
        $this->assertStringContainsString('No survey', $csv);
        $this->assertStringNotContainsString('d@x.com', $csv);
    }

    public function test_deleting_the_import_removes_its_answers(): void
    {
        $this->sample();
        $import = Import::latest('id')->first();

        $this->delete(route('uploads.destroy', $import))->assertRedirect();

        $this->assertSame(0, DB::table('cancellation_surveys')->count());
    }
}
