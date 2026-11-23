<?php

namespace App\Services;

use App\Models\Import;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Throwable;

/**
 * Imports the cancellation form file (07-export-cancellation-form.sql) into
 * `cancellation_surveys`. Re-importing the same file updates rows in place.
 */
class CancellationSurveyImportService
{
    /** The columns that mark a file as the cancellation form export. */
    public const REQUIRED_COLUMNS = ['survey_id', 'subscription_id', 'reason', 'retention_used'];

    /** The form's reason slugs, as people read them. Unknown slugs are title-cased. */
    public const REASONS = [
        'temporary' => 'Taking a break',
        'no-longer-need' => 'No longer needed',
        'too-expensive' => 'Too expensive',
        'too-much' => 'Too much food',
        'delivery-issues' => 'Delivery issues',
        'unhappy' => 'Unhappy with the product',
        'pet-dislikes' => 'Pet doesn\'t like it',
        'trying-another' => 'Trying another brand',
        'other' => 'Other',
    ];

    private const MAX_ERROR_SAMPLES = 50;

    public static function reasonLabel(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return 'No survey';
        }

        return self::REASONS[$slug] ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
    }

    public function isSurveyHeader(array $header): bool
    {
        // A UTF-8 BOM would otherwise glue itself to the first column name.
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), $header);

        return array_diff(self::REQUIRED_COLUMNS, $header) === [];
    }

    public function import(Import $import, string $path): Import
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), (array) ($file->current() ?: []));

        if (! $this->isSurveyHeader($header)) {
            $import->update(['status' => 'failed', 'error_log' => ['header' => $header]]);

            throw new \RuntimeException('Not a cancellation form file.');
        }

        $index = array_flip($header);
        $rows = [];
        $total = 0;
        $skipped = 0;
        $errors = [];

        foreach ($file as $line => $raw) {
            if ($line === 0 || $raw === [null] || $raw === false || $raw === null) {
                continue;
            }

            $total++;

            try {
                $row = $this->normaliseRow($raw, $index, $import->id);
            } catch (Throwable) {
                $row = null;
            }

            if ($row === null) {
                $skipped++;
                if (count($errors) < self::MAX_ERROR_SAMPLES) {
                    $errors[] = ['line' => $line + 1, 'reason' => 'no survey id or subscription id', 'raw' => $raw];
                }

                continue;
            }

            $rows[$row['source_key']] = $row;
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('cancellation_surveys')->upsert(
                $chunk,
                ['source_key'],
                array_values(array_diff(array_keys($chunk[0]), ['source_key', 'created_at'])),
            );
        }

        $import->update([
            'status' => 'completed',
            'total_rows' => $total,
            'imported_rows' => count($rows),
            'skipped_rows' => $skipped,
            'error_log' => $errors === [] ? null : ['skipped' => $errors],
        ]);

        return $import->refresh();
    }

    /** @return array<string, mixed>|null */
    public function normaliseRow(array $raw, array $index, ?int $importId): ?array
    {
        $get = function (string $col) use ($raw, $index): ?string {
            if (! isset($index[$col]) || ! array_key_exists($index[$col], $raw)) {
                return null;
            }
            $v = trim((string) $raw[$index[$col]]);

            return $v === '' || strtoupper($v) === 'NULL' ? null : $v;
        };

        $surveyId = $get('survey_id');
        $subId = $get('subscription_id');

        if (($surveyId === null || ! ctype_digit($surveyId)) && ($subId === null || ! ctype_digit($subId))) {
            return null;
        }

        $accepted = $this->date($get('retention_accepted_at'));
        $now = Carbon::now();

        return [
            'import_id' => $importId,
            'source_key' => $surveyId !== null && ctype_digit($surveyId) ? 'survey:'.$surveyId : 'sub:'.$subId,
            'survey_id' => $surveyId !== null && ctype_digit($surveyId) ? (int) $surveyId : null,
            'subscription_id' => $subId !== null && ctype_digit($subId) ? (int) $subId : null,
            // A row with no survey is dated by the offer it accepted.
            'submitted_at' => $this->date($get('submitted_at')) ?? $accepted,
            'email' => $get('email') === null ? null : strtolower($get('email')),
            'customer_name' => $get('customer_name'),
            'reason' => $get('reason') === null ? null : strtolower($get('reason')),
            'satisfaction' => $get('satisfaction') === null ? null : strtolower($get('satisfaction')),
            'would_return' => $get('would_return') === null ? null : strtolower($get('would_return')),
            'feedback' => $get('feedback'),
            'subscription_total' => $this->number($get('subscription_total')),
            'currency' => $get('currency'),
            'products' => $get('products'),
            'retention_used' => in_array(strtolower((string) $get('retention_used')), ['yes', '1', 'true', 'y'], true),
            'retention_accepted_at' => $accepted,
            'retention_percent' => $this->number($get('retention_percent')),
            'retention_shipping' => $this->number($get('retention_shipping')),
            'retention_spent_orders' => (int) ($this->number($get('retention_spent_orders')) ?? 0),
            'retention_first_spent_at' => $this->date($get('retention_first_spent_at')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** A UTC datetime from a unix timestamp or a date string; null when blank. */
    private function date(?string $v): ?string
    {
        if ($v === null || str_starts_with($v, '0000-00-00')) {
            return null;
        }

        return ctype_digit($v)
            ? Carbon::createFromTimestampUTC((int) $v)->toDateTimeString()
            : Carbon::parse($v, 'UTC')->toDateTimeString();
    }

    private function number(?string $v): ?float
    {
        return $v === null || ! is_numeric($v) ? null : (float) $v;
    }
}
