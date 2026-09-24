<?php

namespace App\Services;

use App\Models\Import;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Throwable;

/**
 * Imports the subscription status history file (05-export-status-history.sql)
 * into `subscription_status_changes`.
 *
 * Each row is one WooCommerce "Status changed from X to Y" order note. The
 * statuses are parsed out of the note text; a file that already carries them
 * split out as `from_status` / `to_status` columns is accepted too.
 */
class StatusHistoryImportService
{
    /** Always required. One of `note` or `to_status` is required as well. */
    public const REQUIRED_COLUMNS = ['subscription_id', 'changed_at'];

    /**
     * WooCommerce's display names for subscription statuses, mapped to the
     * slugs `records.status` uses. Slugs themselves map to themselves.
     */
    private const STATUS_NAMES = [
        'active' => 'active',
        'on hold' => 'on-hold',
        'on-hold' => 'on-hold',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'expired' => 'expired',
        'pending' => 'pending',
        'pending payment' => 'pending',
        'pending cancellation' => 'pending-cancel',
        'pending-cancel' => 'pending-cancel',
        'switched' => 'switched',
    ];

    private const CHUNK_SIZE = 500;

    private const MAX_ERROR_SAMPLES = 50;

    /** Whether a header row is a status history file rather than an orders export. */
    public function isHistoryHeader(array $header): bool
    {
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        return array_diff(self::REQUIRED_COLUMNS, $header) === []
            && (in_array('note', $header, true) || in_array('to_status', $header, true));
    }

    public function import(Import $import, string $path): Import
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) ($file->current() ?: []));

        if (! $this->isHistoryHeader($header)) {
            $import->update(['status' => 'failed', 'error_log' => ['header' => $header]]);

            throw new \RuntimeException('Not a subscription status history file.');
        }

        $index = array_flip($header);
        $total = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $buffer = [];

        foreach ($file as $line => $row) {
            if ($line === 0 || $row === [null] || $row === false || $row === null) {
                continue;
            }

            $total++;

            try {
                $change = $this->normaliseRow($row, $index, $import->id);
            } catch (Throwable $e) {
                $change = null;
            }

            if ($change === null) {
                $skipped++;
                if (count($errors) < self::MAX_ERROR_SAMPLES) {
                    $errors[] = ['line' => $line + 1, 'reason' => 'no subscription id, date or recognisable status change', 'raw' => $row];
                }

                continue;
            }

            // Keyed on the unique columns so a file repeating a note does not
            // put the same row in one upsert statement twice.
            $buffer[implode('|', [$change['subscription_id'], $change['changed_at'], $change['from_status'], $change['to_status']])] = $change;

            if (count($buffer) >= self::CHUNK_SIZE) {
                $imported += $this->flush($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $imported += $this->flush($buffer);
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
     * @return array{import_id:?int, subscription_id:int, changed_at:string, from_status:string, to_status:string, created_at:Carbon, updated_at:Carbon}|null
     */
    public function normaliseRow(array $row, array $index, ?int $importId): ?array
    {
        $get = fn (string $col) => isset($index[$col]) && array_key_exists($index[$col], $row)
            ? trim((string) $row[$index[$col]])
            : '';

        $id = $get('subscription_id');
        $date = $get('changed_at');

        if (! ctype_digit($id) || $date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        if ($get('to_status') !== '') {
            [$from, $to] = [$this->status($get('from_status')), $this->status($get('to_status'))];
        } else {
            [$from, $to] = $this->parseNote($get('note')) ?? [null, null];
        }

        if ($to === null) {
            return null;
        }

        $now = Carbon::now();

        return [
            'import_id' => $importId,
            'subscription_id' => (int) $id,
            'changed_at' => Carbon::parse($date)->toDateTimeString(),
            'from_status' => $from ?? '',
            'to_status' => $to,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The two statuses in a "Status changed from X to Y." note, as slugs.
     *
     * The last occurrence wins: a reason can precede the sentence, never follow it.
     *
     * @return array{0: ?string, 1: string}|null
     */
    public function parseNote(string $note): ?array
    {
        $note = trim(strip_tags(html_entity_decode($note, ENT_QUOTES)));

        if (! preg_match_all('/status changed from (.+?) to (.+?)(?:\.|$)/im', $note, $m, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($m);
        $to = $this->status($last[2]);

        return $to === null ? null : [$this->status($last[1]), $to];
    }

    /** A status name or slug as the slug `records.status` uses; null if unknown. */
    public function status(string $name): ?string
    {
        $name = strtolower(trim($name));
        $name = str_starts_with($name, 'wc-') ? substr($name, 3) : $name;

        return self::STATUS_NAMES[$name] ?? null;
    }

    /** @return int rows written */
    private function flush(array $buffer): int
    {
        $rows = array_values($buffer);

        DB::table('subscription_status_changes')->upsert(
            $rows,
            ['subscription_id', 'changed_at', 'from_status', 'to_status'],
            ['import_id', 'updated_at'],
        );

        return count($rows);
    }
}
