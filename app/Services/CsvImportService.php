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

    /**
     * Optional marketing columns, mapped straight through when present.
     *
     * Every one is optional: a file exported before these existed still imports
     * unchanged, the columns simply stay null. csv column => records column.
     */
    public const ATTRIBUTION_COLUMNS = [
        'attribution_type' => 'attribution_type',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'device_type' => 'device_type',
        'coupon_code' => 'coupon_code',
        'primary_product' => 'primary_product',
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
            'discount_amount' => $this->numericOrZero($get('discount_amount')),
            // Null, not zero: a file without these columns means "gross only",
            // which is a different claim from "this order earned nothing net".
            'net_amount' => $this->decimalOrNull($index, $get, 'net_amount'),
            'tax_amount' => $this->decimalOrNull($index, $get, 'tax_amount'),
            'shipping_amount' => $this->decimalOrNull($index, $get, 'shipping_amount'),
            'refunded_amount' => $this->numericOrZero($get('refunded_amount')),
            // Subscription-only: a billing cycle on an order means nothing, and
            // a next-payment date on a dead subscription is stale scheduling.
            'billing_period' => $recordType === 'shop_subscription'
                ? $this->normaliseBillingPeriod($get('billing_period'))
                : null,
            'billing_interval' => $recordType === 'shop_subscription'
                ? $this->positiveIntOrNull($get('billing_interval'))
                : null,
            'next_payment_at' => $recordType === 'shop_subscription'
                && ! in_array($status, Record::TERMINAL_SUBSCRIPTION_STATUSES, true)
                ? $this->parseDate($get('next_payment_at'))
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $this->attributionFrom($index, $get);
    }

    /**
     * The optional marketing columns, blank-normalised.
     *
     * WooCommerce writes the literal string `(direct)` for unattributed
     * traffic; it is kept verbatim rather than nulled, because "we know it was
     * direct" and "we have no attribution" are different facts.
     *
     * @param  callable(string): mixed  $get
     * @return array<string, ?string>
     */
    private function attributionFrom(array $index, callable $get): array
    {
        $out = [];

        foreach (self::ATTRIBUTION_COLUMNS as $csv => $column) {
            $value = isset($index[$csv]) ? trim((string) $get($csv)) : '';
            $out[$column] = $value === '' ? null : mb_substr($value, 0, 191);
        }

        return $out;
    }

    /**
     * A money column that may legitimately be absent.
     *
     * @param  callable(string): mixed  $get
     */
    private function decimalOrNull(array $index, callable $get, string $column): ?float
    {
        if (! isset($index[$column])) {
            return null;
        }

        $raw = trim((string) $get($column));

        return $raw === '' ? null : $this->numericOrZero($raw);
    }

    /** WooCommerce billing periods; anything else is not a cycle we can use. */
    private function normaliseBillingPeriod(mixed $value): ?string
    {
        $period = strtolower(trim((string) $value));

        return in_array($period, ['day', 'week', 'month', 'year'], true) ? $period : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $n = $this->intOrNull($value);

        return $n !== null && $n > 0 ? $n : null;
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
        // Derived from the payload rather than listed by hand: a hardcoded list
        // silently stops updating any column added later, so a re-import would
        // leave the new fields stale on every row that already existed.
        $update = array_values(array_diff(
            array_keys($buffer[0]),
            ['id', 'created_at'], // conflict key, and the original insert time
        ));

        DB::table('records')->upsert($buffer, ['id'], $update);
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
