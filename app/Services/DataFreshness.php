<?php

namespace App\Services;

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
}
