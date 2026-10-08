<?php

namespace App\Services;

use App\Support\Clock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Incremental, READ-ONLY sync from the live WooCommerce database.
 *
 * Runs the very queries the CSV path is built on (database/export/
 * 04-export-with-net-revenue.sql and 05-export-status-history.sql), so a
 * synced row and an uploaded row are the same row. Each run reads only what
 * changed since the last one: orders and subscriptions by `date_updated_gmt`,
 * status notes by `comment_date_gmt`.
 *
 * Nothing is ever written to WooCommerce. The connection should use a
 * SELECT-only MySQL user, and every session is additionally set READ ONLY
 * before the first query, so even a misconfigured user cannot write through
 * this class.
 */
class WooSyncService
{
    public const ORDERS_SQL = 'database/export/04-export-with-net-revenue.sql';

    public const NOTES_SQL = 'database/export/05-export-status-history.sql';

    public function __construct(
        private readonly CsvImportService $records,
        private readonly StatusHistoryImportService $history,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) config('woocommerce.enabled');
    }

    /**
     * Pull everything changed since the last sync (or everything, on the
     * first run or with $full) and upsert it.
     *
     * @return array{records:int, changes:int, since:?string, synced_at:string}
     */
    public function sync(bool $full = false): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WooCommerce sync is not configured: set WC_DB_HOST, WC_DB_NAME, WC_DB_USER and WC_DB_PASS.');
        }

        // Taken before reading, so anything updated during the run is picked
        // up next time rather than lost.
        $startedAt = Clock::gmt(Clock::now());
        $since = $full ? null : $this->since();

        try {
            $records = $this->syncRecords($since);
            $changes = $this->syncStatusNotes($since);
        } catch (Throwable $e) {
            $this->putState('last_sync_status', 'failed');
            $this->putState('last_sync_error', $e->getMessage());

            throw $e;
        }

        $this->putState('last_sync_at', $startedAt);
        $this->putState('last_sync_status', 'ok');
        $this->putState('last_sync_error', null);
        $this->putState('last_sync_rows', (string) ($records + $changes));

        return ['records' => $records, 'changes' => $changes, 'since' => $since, 'synced_at' => $startedAt];
    }

    /**
     * Orders and subscriptions changed since $since, as rows shaped exactly
     * like the CSV export (one associative array per row).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function fetchOrders(?string $since): iterable
    {
        return $this->select($this->ordersSql($since !== null), $since !== null ? [$since] : []);
    }

    /**
     * Subscription status-change notes written since $since.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function fetchStatusNotes(?string $since): iterable
    {
        return $this->select($this->notesSql($since !== null), $since !== null ? [$since] : []);
    }

    /** The orders export query, with this store's prefix and an optional "changed since" bound. */
    public function ordersSql(bool $incremental): string
    {
        $sql = $this->load(self::ORDERS_SQL);
        $where = "WHERE o.type IN ('shop_order', 'shop_subscription')";

        if (! str_contains($sql, $where)) {
            throw new RuntimeException('The orders export query no longer has the WHERE clause the sync extends.');
        }

        return $incremental ? str_replace($where, $where."\n  AND o.date_updated_gmt > ?", $sql) : $sql;
    }

    /** The status-history export query, prefixed, with an optional "written since" bound. */
    public function notesSql(bool $incremental): string
    {
        $sql = $this->load(self::NOTES_SQL);
        $where = "WHERE c.comment_type = 'order_note'";

        if (! str_contains($sql, $where)) {
            throw new RuntimeException('The status history query no longer has the WHERE clause the sync extends.');
        }

        return $incremental ? str_replace($where, $where."\n  AND c.comment_date_gmt > ?", $sql) : $sql;
    }

    /**
     * Orders in WooCommerce right now, as WP Admin's "All" counts them before
     * it hides anything: every order that is not deleted or a draft. Null
     * when the sync is not configured or the database cannot be reached.
     */
    public function sourceOrderCount(): ?int
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $p = $this->prefix();
            $row = $this->select(
                "SELECT COUNT(*) AS n FROM {$p}wc_orders WHERE type = 'shop_order'"
                ." AND status NOT IN ('trash', 'draft', 'checkout-draft', 'auto-draft')",
            );

            return (int) ($row[0]['n'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Live row counts per type and status, for the Data Health reconciliation.
     *
     * @return array<string, array<string, int>>|null
     */
    public function sourceStatusCounts(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $p = $this->prefix();
            $counts = [];

            foreach ($this->select(
                "SELECT type, status, COUNT(*) AS n FROM {$p}wc_orders"
                ." WHERE type IN ('shop_order', 'shop_subscription') GROUP BY type, status ORDER BY type, status",
            ) as $r) {
                $status = (string) $r['status'];
                $counts[(string) $r['type']][str_starts_with($status, 'wc-') ? substr($status, 3) : $status] = (int) $r['n'];
            }

            return $counts;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return int records upserted */
    private function syncRecords(?string $since): int
    {
        $buffer = [];
        $count = 0;
        $index = null;

        foreach ($this->fetchOrders($since) as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $index ??= array_flip(array_keys($row));

            $record = $this->records->normaliseRow(array_values($row), $index, null);

            if ($record === null) {
                continue;
            }

            $buffer[] = $record;

            if (count($buffer) >= 500) {
                $this->records->upsert($buffer);
                $count += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->records->upsert($buffer);
            $count += count($buffer);
        }

        return $count;
    }

    /** @return int status changes upserted */
    private function syncStatusNotes(?string $since): int
    {
        $changes = [];
        $index = null;

        foreach ($this->fetchStatusNotes($since) as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $index ??= array_flip(array_keys($row));

            $change = $this->history->normaliseRow(array_values($row), $index, null);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes === [] ? 0 : $this->history->upsert($changes);
    }

    /** When to read from: the last sync, less a small overlap. Null = read everything. */
    private function since(): ?string
    {
        $last = DB::table('sync_state')->where('key', 'last_sync_at')->value('value');

        if ($last === null) {
            return null;
        }

        return Clock::gmt(Clock::toLocal((string) $last)->subSeconds((int) config('woocommerce.overlap_seconds', 300)));
    }

    /**
     * Run a SELECT on the WooCommerce connection, in a READ ONLY session.
     *
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $bindings = []): array
    {
        $db = $this->connection();

        return array_map(fn ($r) => (array) $r, $db->select($sql, $bindings));
    }

    private function connection(): Connection
    {
        $db = DB::connection('woocommerce');

        if ($db->getDriverName() === 'mysql') {
            // Belt and braces on top of a SELECT-only user.
            $db->unprepared('SET SESSION TRANSACTION READ ONLY');
        }

        return $db;
    }

    /** A query file, comments stripped, with this store's table prefix. */
    private function load(string $path): string
    {
        $lines = array_filter(
            explode("\n", (string) file_get_contents(base_path($path))),
            fn (string $l) => ! str_starts_with(ltrim($l), '--'),
        );

        $sql = rtrim(trim(implode("\n", $lines)), ';');

        return preg_replace('/\bwp_(?=[a-z])/', $this->prefix(), $sql);
    }

    private function prefix(): string
    {
        $prefix = (string) config('woocommerce.table_prefix', 'wp_');

        // Interpolated into SQL, so only what a table prefix can contain.
        if (! preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new RuntimeException('WC_TABLE_PREFIX may only contain letters, digits and underscores.');
        }

        return $prefix;
    }

    private function putState(string $key, ?string $value): void
    {
        $now = Clock::gmt(Clock::now());

        DB::table('sync_state')->upsert(
            [['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at'],
        );
    }
}
