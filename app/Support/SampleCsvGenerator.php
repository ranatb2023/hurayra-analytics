<?php

namespace App\Support;

use App\Services\CsvImportService;

/**
 * Produces a deterministic, representative sample of the wp_wc_orders CSV so
 * every metric has non-trivial data locally. Statuses are written WITH the
 * `wc-` prefix and a few rows are intentionally messy (blank totals, a bad
 * date, a missing id) to exercise normalisation and the skip path.
 *
 * It also emits `customer_id` and `ended_at` columns (both "extra" columns
 * beyond the required set) so customer-level analytics and the point-in-time
 * subscriber history have data, and the superset-header path is exercised.
 * Customers are shared between subscriptions and one-time orders to create
 * realistic repeat / returning behaviour.
 */
class SampleCsvGenerator
{
    /** The header written: required columns + the optional extras. */
    private function header(): array
    {
        return array_merge(CsvImportService::EXPECTED_COLUMNS, ['customer_id', 'ended_at']);
    }

    /** Write the CSV to $path and return the number of data rows written. */
    public function write(string $path): int
    {
        $rows = $this->rows();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($path, 'w');
        fputcsv($fh, $this->header());
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return count($rows);
    }

    /**
     * @return array<int, array<int, string>> rows aligned to {@see header()}
     */
    public function rows(): array
    {
        mt_srand(42); // reproducible

        $rows = [];
        $nextId = 1;
        $subStatuses = ['active', 'active', 'active', 'on-hold', 'cancelled', 'cancelled', 'pending-cancel', 'expired', 'pending'];
        $months = $this->months('2025-01', '2026-06');

        // A pool of ~50 customers so some buy more than once (repeat/returning).
        $customer = fn (int $seed) => 2000 + ($seed % 50);

        // ---- 80 subscriptions spread across the timeline ----
        for ($i = 0; $i < 80; $i++) {
            $subId = $nextId++;
            $cust = $customer($subId);
            $status = $subStatuses[array_rand($subStatuses)];
            $date = $this->randomDateIn($months[array_rand($months)]);

            // Ended subscriptions get an end date 1–10 months after sign-up, so
            // the point-in-time history has something real to work with. A few
            // are deliberately left blank to exercise the last-order fallback.
            $endedAt = in_array($status, ['cancelled', 'expired'], true) && mt_rand(1, 10) <= 8
                ? $this->monthsAfter($date, mt_rand(1, 10))
                : '';

            $rows[] = [$subId, 'shop_subscription', 'wc-'.$status, $date,
                number_format(mt_rand(900, 4900) / 100, 2, '.', ''), '', 'subscription', "user{$cust}@example.com", $cust, $endedAt];

            // Parent order (most are completed).
            $parentStatus = mt_rand(1, 10) <= 8 ? 'completed' : 'failed';
            $rows[] = [$nextId++, 'shop_order', 'wc-'.$parentStatus, $date,
                number_format(mt_rand(900, 4900) / 100, 2, '.', ''), $subId, 'parent', "user{$cust}@example.com", $cust, ''];

            // 0–6 renewals over later months.
            $renewals = mt_rand(0, 6);
            for ($r = 0; $r < $renewals; $r++) {
                $renewalStatus = match (true) {
                    mt_rand(1, 10) <= 7 => 'completed',
                    mt_rand(1, 2) === 1 => 'pending',
                    default => 'failed',
                };
                $rows[] = [$nextId++, 'shop_order', 'wc-'.$renewalStatus, $this->randomDateIn($months[array_rand($months)]),
                    number_format(mt_rand(900, 4900) / 100, 2, '.', ''), $subId, 'renewal', "user{$cust}@example.com", $cust, ''];
            }
        }

        // ---- 120 one-time orders (no subscription) ----
        $oneTimeStatuses = ['completed', 'completed', 'completed', 'cancelled', 'refunded', 'processing', 'pending'];
        for ($i = 0; $i < 120; $i++) {
            $status = $oneTimeStatuses[array_rand($oneTimeStatuses)];
            $cust = $customer($i * 7 + 3); // overlaps the subscriber pool -> returning customers
            $rows[] = [$nextId++, 'shop_order', 'wc-'.$status, $this->randomDateIn($months[array_rand($months)]),
                number_format(mt_rand(500, 9900) / 100, 2, '.', ''), '', 'one_time', "user{$cust}@example.com", $cust, ''];
        }

        // ---- A few intentionally messy rows ----
        $rows[] = [$nextId++, 'shop_order', 'wc-completed', '2026-03-15 10:00:00', '', '', 'one_time', '  MixedCase@Example.com ', 2007, '']; // blank total -> 0
        $rows[] = [$nextId++, 'shop_order', 'wc-completed', 'not-a-date', '49.00', '', 'one_time', 'guest@example.com', 2011, ''];               // bad date -> null
        $rows[] = ['', 'shop_order', 'wc-completed', '2026-03-15 10:00:00', '49.00', '', 'one_time', 'guest@example.com', 2011, ''];             // missing id -> skipped

        return $rows;
    }

    /** @return string[] list of Y-m month keys, inclusive. */
    private function months(string $from, string $to): array
    {
        $out = [];
        $cursor = strtotime($from.'-01');
        $end = strtotime($to.'-01');
        while ($cursor <= $end) {
            $out[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }

        return $out;
    }

    /** Shift a "Y-m-d H:i:s" stamp forward by whole months, day/time preserved. */
    private function monthsAfter(string $date, int $months): string
    {
        return date('Y-m-d H:i:s', strtotime("+{$months} months", strtotime($date)));
    }

    private function randomDateIn(string $ym): string
    {
        $day = str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
        $h = str_pad((string) mt_rand(0, 23), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT);

        return "{$ym}-{$day} {$h}:{$m}:00";
    }
}
