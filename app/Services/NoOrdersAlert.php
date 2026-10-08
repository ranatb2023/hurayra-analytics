<?php

namespace App\Services;

use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * "The store has stopped taking orders" — checked hourly.
 *
 * An incident is keyed on the last order before the silence. While no new
 * order arrives the key does not change, so the alert fires once and every
 * later hourly check finds it already logged. The first new order closes the
 * incident; the next silence after it has a new key and alerts again.
 */
class NoOrdersAlert
{
    public const TYPE = 'no_orders';

    public function __construct(
        private readonly DataFreshness $freshness,
        private readonly AlertNotifier $notifier,
    ) {}

    /**
     * Run the check once.
     *
     * @return string outside_hours | ok | fired | suppressed
     */
    public function check(): string
    {
        $now = Clock::now();
        $from = (int) config('alerts.no_orders.from_hour', 8);
        $until = (int) config('alerts.no_orders.until_hour', 23);

        if ($now->hour < $from || $now->hour >= $until) {
            return 'outside_hours';
        }

        $threshold = max(1, (int) config('alerts.no_orders.hours', 12));
        $latest = $this->freshness->latestOrder();
        $hours = $latest === null ? null : $latest['created_at']->diffInHours($now);

        if ($hours !== null && $hours < $threshold) {
            $this->resolveOpen();

            return 'ok';
        }

        $key = $latest === null ? 'none' : 'order:'.$latest['id'];

        $exists = DB::table('alert_incidents')
            ->where('type', self::TYPE)
            ->where('incident_key', $key)
            ->exists();

        if ($exists) {
            return 'suppressed';
        }

        $subject = $latest === null
            ? 'Hurayra: no orders in the data'
            : "Hurayra: no new order for {$threshold}+ hours";

        $message = $latest === null
            ? 'There are no orders in the dashboard data at all. Check the import / sync.'
            : sprintf(
                "No new order since #%d at %s UK time (%d hours ago).\n"
                ."Either the store is quiet or checkout, payments or the data sync are broken.\n"
                .'This alert will not repeat until a new order arrives.',
                $latest['id'],
                $latest['created_at']->format('j M Y H:i'),
                (int) floor($hours),
            );

        // Logged before sending, so a slow or failing channel cannot cause a
        // second alert on the next run.
        $id = DB::table('alert_incidents')->insertGetId([
            'type' => self::TYPE,
            'incident_key' => $key,
            'message' => $message,
            'fired_at' => Clock::gmt($now),
            'created_at' => Clock::gmt($now),
            'updated_at' => Clock::gmt($now),
        ]);

        $channels = $this->notifier->send($subject, $message);

        DB::table('alert_incidents')->where('id', $id)->update(['channels' => json_encode($channels)]);

        return 'fired';
    }

    /** A new order arrived: close whatever incident was open. */
    private function resolveOpen(): void
    {
        DB::table('alert_incidents')
            ->where('type', self::TYPE)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Clock::gmt(Clock::now()), 'updated_at' => Clock::gmt(Clock::now())]);
    }
}
