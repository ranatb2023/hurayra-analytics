<?php

namespace App\Services;

use App\Models\Record;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How current the data is, and therefore which instant "now" means for it.
 *
 * The dashboard is only ever as fresh as its last import (or sync). A
 * subscription's status is what it was when the export ran, so a point-in-time
 * figure for a later instant is a guess dressed as a fact. Every snapshot is
 * therefore measured at {@see asOf()}: the real current time, capped at the
 * moment the data was taken.
 */
class DataFreshness
{
    /** When the most recent import finished, or null if none ever has. */
    public function lastImportAt(): ?CarbonImmutable
    {
        $at = DB::table('imports')->where('status', 'completed')->max('updated_at');

        return $at === null ? null : Clock::toLocal((string) $at);
    }

    /** When the direct WooCommerce sync last completed, or null if it never has. */
    public function lastSyncAt(): ?CarbonImmutable
    {
        if (! DB::getSchemaBuilder()->hasTable('sync_state')) {
            return null;
        }

        $at = DB::table('sync_state')->where('key', 'last_sync_at')->value('value');

        return $at === null ? null : Clock::toLocal((string) $at);
    }

    /** The moment the data was taken: the later of the last import and last sync. */
    public function dataTimestamp(): ?CarbonImmutable
    {
        $candidates = array_filter([$this->lastImportAt(), $this->lastSyncAt()]);

        if ($candidates === []) {
            return null;
        }

        return array_reduce($candidates, fn (?CarbonImmutable $a, CarbonImmutable $b) => $a === null || $b->gt($a) ? $b : $a);
    }

    /**
     * The instant snapshot metrics are measured at: now, but never later than
     * the data. With no import on record (fixtures, a fresh install) it is
     * simply now.
     */
    public function asOf(): CarbonImmutable
    {
        $now = Clock::now();
        $data = $this->dataTimestamp();

        return $data !== null && $data->lt($now) ? $data : $now;
    }

    /** Hours without a new order before the banner turns amber, then red. */
    public const WARN_AFTER_HOURS = 12;

    public const STALE_AFTER_HOURS = 24;

    /**
     * The newest order in the data, or null when there are none.
     *
     * @return array{id:int, created_at:CarbonImmutable}|null
     */
    public function latestOrder(): ?array
    {
        $row = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
            ->whereNotNull('date_created_gmt')
            ->orderByDesc('date_created_gmt')
            ->orderByDesc('id')
            ->first(['id', 'date_created_gmt']);

        return $row === null ? null : [
            'id' => (int) $row->id,
            'created_at' => Clock::toLocal((string) $row->date_created_gmt),
        ];
    }

    /** Orders the dashboard counts: real order statuses only. */
    public function dashboardOrderCount(): int
    {
        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
            ->count();
    }

    /**
     * Orders in the source, as WooCommerce's own "All" list would count them
     * if it did not filter anything out: every order except the deleted and
     * draft ones. A gap against {@see dashboardOrderCount()} is rows with a
     * status that is not an order status (see the Data Health page).
     *
     * @return array{count:int, from:string}
     */
    public function sourceOrderCount(): array
    {
        return [
            'count' => DB::table('records')
                ->where('record_type', 'shop_order')
                ->whereNotIn('status', Record::EXCLUDED_ORDER_STATUSES)
                ->count(),
            'from' => 'last import',
        ];
    }

    /**
     * Row counts per type and status in the source, for the Data Health
     * reconciliation. Every imported row, whatever its status.
     *
     * @return array{counts: array<string, array<string, int>>, from: string}
     */
    public function sourceStatusCounts(): array
    {
        $counts = [];

        foreach (DB::table('records')
            ->selectRaw('record_type, status, COUNT(*) as n')
            ->groupBy('record_type', 'status')
            ->orderBy('record_type')
            ->orderBy('status')
            ->get() as $r) {
            $counts[(string) $r->record_type][(string) $r->status] = (int) $r->n;
        }

        return ['counts' => $counts, 'from' => 'last import'];
    }

    /**
     * Everything the freshness strip shows.
     *
     * `level` is green / amber / red from how long it has been since the last
     * order: a store that normally takes orders every few hours and has had
     * none for a day is either quiet or broken, and either way the numbers
     * below the strip are not today's.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $now = Clock::now();
        $latest = $this->latestOrder();
        $hours = $latest === null ? null : (int) max(0, floor($latest['created_at']->diffInHours($now)));

        return [
            'now' => $now,
            'last_import_at' => $this->lastImportAt(),
            'last_sync_at' => $this->lastSyncAt(),
            'data_timestamp' => $this->dataTimestamp(),
            'latest_order' => $latest,
            'hours_since_order' => $hours,
            'dashboard_orders' => $this->dashboardOrderCount(),
            'source_orders' => $this->sourceOrderCount(),
            'level' => match (true) {
                $hours === null => 'red',
                $hours > self::STALE_AFTER_HOURS => 'red',
                $hours > self::WARN_AFTER_HOURS => 'amber',
                default => 'green',
            },
        ];
    }
}
