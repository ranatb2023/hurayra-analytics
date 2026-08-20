<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Record;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Throwable;

/**
 * Streams the WooCommerce HPOS (wp_wc_orders) CSV export into the `records`
 * table. The file is read line-by-line (never fully loaded into memory) and
 * upserted in chunks keyed on the WooCommerce `id`, which makes re-uploading
 * the same file idempotent.
 */
class CsvImportService
{
    /** The one and only accepted CSV format. Order-independent; matched by name. */
    public const EXPECTED_COLUMNS = [
        'id',
        'record_type',
        'status',
        'date_created_gmt',
        'total_amount',
        'subscription_id',
        'order_relationship',
        'billing_email',
    ];

    /**
     * Optional end-date columns, in priority order. The first one present in the
     * header wins. WooCommerce names this differently depending on where the
     * export came from (HPOS `wp_wc_orders`, the Subscriptions schedule meta, or
     * a hand-rolled query), so we accept every spelling we have seen.
     *
     * Only read for `shop_subscription` rows in a terminal status — for a live
     * subscription a "last modified" stamp is not an end date.
     */
    public const ENDED_AT_COLUMNS = [
        // When the subscription actually finished.
        'ended_at',
        'date_ended_gmt',
        'end_date',
        'schedule_end',
        // When cancellation was REQUESTED. Usually earlier than the end — a
        // subscription cancelled on the 1st still runs to the end of its paid
        // term — so these only apply when no real end date is present.
        'date_cancelled_gmt',
        'cancelled_date',
        'schedule_cancelled',
        // Last touch on the row. Only a rough proxy, and only ever consulted
        // for a subscription already in a terminal status.
        'date_modified_gmt',
        'date_updated_gmt',
    ];

    private const CHUNK_SIZE = 500;

    /** How many bad rows to keep as examples in the import's error_log. */
    private const MAX_ERROR_SAMPLES = 50;

    /**
     * Validate a header row against {@see EXPECTED_COLUMNS}.
     *
     * The CSV must *contain* every required column, but may carry extra columns
     * (real wp_wc_orders exports include customer_id, parent_order_id, etc.) —
     * those are simply ignored. Only missing required columns are fatal.
     *
     * @return array{valid: bool, missing: string[], extra: string[]}
     */
    public function validateHeader(array $header): array
    {
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $missing = array_values(array_diff(self::EXPECTED_COLUMNS, $header));
        $extra = array_values(array_diff($header, self::EXPECTED_COLUMNS));

        return [
            'valid' => $missing === [],
            'missing' => $missing,
            'extra' => $extra, // informational only — ignored on import
        ];
    }

    /**
     * Read only the header of a file (cheap; used for pre-upload validation).
     */
    public function readHeader(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $header = $file->current() ?: [];

        return array_map(fn ($h) => strtolower(trim((string) $h)), (array) $header);
    }

    /**
     * Stream the file into `records`, updating the given Import with counts and
     * a sample of any skipped rows. Throws on header mismatch.
     */
    public function import(Import $import, string $path): Import
    {
        $header = $this->readHeader($path);
        $check = $this->validateHeader($header);

        if (! $check['valid']) {
            $import->update([
                'status' => 'failed',
                'error_log' => ['header' => $check],
            ]);

            throw new \RuntimeException('CSV header does not match the expected format.');
        }

        $index = array_flip($header); // column name => position
        $total = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $buffer = [];

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        foreach ($file as $line => $row) {
            if ($line === 0) {
                continue; // header
            }
            if ($row === [null] || $row === false || $row === null) {
                continue; // blank line artefact
            }

            $total++;

            try {
                $record = $this->normaliseRow($row, $index, $import->id);

                if ($record === null) {
                    $skipped++;
                    if (count($errors) < self::MAX_ERROR_SAMPLES) {
                        $errors[] = ['line' => $line + 1, 'reason' => 'missing/invalid id or record_type', 'raw' => $row];
                    }

                    continue;
                }

                $buffer[] = $record;

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $this->flush($buffer);
                    $imported += count($buffer);
                    $buffer = [];
                }
            } catch (Throwable $e) {
                $skipped++;
                if (count($errors) < self::MAX_ERROR_SAMPLES) {
                    $errors[] = ['line' => $line + 1, 'reason' => $e->getMessage(), 'raw' => $row];
                }
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer);
            $imported += count($buffer);
        }

        $import->update([
            'status' => 'completed',
            'total_rows' => $total,
            'imported_rows' => $imported,
            'skipped_rows' => $skipped,
            'error_log' => $errors === [] ? null : ['skipped' => $errors],
        ]);

        return $import->refresh();
    }

    /**
     * Map a raw CSV row to a normalised `records` row, or null if it can't be
     * stored (no id or no record_type).
     */
    public function normaliseRow(array $row, array $index, ?int $importId): ?array
    {
        $get = fn (string $col) => array_key_exists($index[$col] ?? -1, $row)
            ? $row[$index[$col]]
            : null;

        $id = $this->intOrNull($get('id'));
        $recordType = strtolower(trim((string) $get('record_type')));

        if ($id === null || $recordType === '') {
            return null;
        }

        $now = Carbon::now();
        $status = $this->normaliseStatus($get('status'));
        $createdAt = $this->parseDate($get('date_created_gmt'));

        return [
            'id' => $id,
            'import_id' => $importId,
            'record_type' => $recordType,
            'status' => $status,
            'date_created_gmt' => $createdAt,
            'ended_at' => $this->resolveEndedAt($recordType, $status, $createdAt, $index, $get),
            'total_amount' => $this->numericOrZero($get('total_amount')),
            'subscription_id' => $this->intOrNull($get('subscription_id')),
            'customer_id' => $this->intOrNull($get('customer_id')), // optional extra column
            'order_relationship' => $this->normaliseRelationship($get('order_relationship')),
            'billing_email' => $this->normaliseEmail($get('billing_email')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The date a subscription left the lifecycle, from whichever optional column
     * the file provides. Null unless this is a subscription in a terminal
     * status, and never earlier than the sign-up date (a bad stamp would make
     * the subscription look like it ended before it began).
     *
     * @param  callable(string): mixed  $get
     */
    private function resolveEndedAt(string $recordType, string $status, ?string $createdAt, array $index, callable $get): ?string
    {
        if ($recordType !== 'shop_subscription') {
            return null;
        }

        if (! in_array($status, Record::TERMINAL_SUBSCRIPTION_STATUSES, true)) {
            return null;
        }

        foreach (self::ENDED_AT_COLUMNS as $column) {
            if (! isset($index[$column])) {
                continue;
            }

            $parsed = $this->parseDate($get($column));

            if ($parsed === null) {
                continue; // blank in this row; try the next-best column
            }

            return $createdAt !== null && $parsed < $createdAt ? $createdAt : $parsed;
        }

        return null;
    }

    private function flush(array $buffer): void
    {
        DB::table('records')->upsert(
            $buffer,
            ['id'],
            [
                'import_id', 'record_type', 'status', 'date_created_gmt', 'ended_at',
                'total_amount', 'subscription_id', 'customer_id', 'order_relationship',
                'billing_email', 'updated_at',
            ],
        );
    }

    /** Strip the wc- prefix, lowercase, trim. Empty stays empty string. */
    public function normaliseStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return str_starts_with($value, 'wc-') ? substr($value, 3) : $value;
    }

    private function normaliseRelationship(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function normaliseEmail(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /** Parse a datetime defensively; MySQL zero-dates and junk become null. */
    public function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Blank / NaN / non-numeric becomes 0. */
    public function numericOrZero(mixed $value): float
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
