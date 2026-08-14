<?php

namespace App\Services;

use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Computes all dashboard metrics for a given {@see Period}.
 *
 * Time semantics (confirmed with the product owner):
 *  - Cohort metrics  (orders + "New Subscribers"): date_created_gmt in [start, end).
 *  - Snapshot metrics (subscription status counts): current status, existing as
 *    of the period end, i.e. date_created_gmt < end. Sign-up date lower bound is
 *    ignored — these answer "how many are in state X right now".
 *  - Cancelled with/without purchase: a linked completed order counts whenever it
 *    happened (lifetime), joined at query time on subscription_id.
 *
 * The service is pure: it never touches the request or session.
 */
class MetricsService
{
    private const STATUS_BREAKDOWN = ['cancelled', 'failed', 'refunded', 'pending', 'processing'];

    private const STRICT_NOT_COMPLETED = ['pending', 'processing'];

    private const SUBSCRIPTION_PURCHASE_RELS = ['parent', 'renewal'];

    /**
     * Full dashboard payload: every metric, supporting totals, the not-completed
     * breakdown, and (optionally) the compare-to-previous deltas.
     */
    public function summary(Period $period, bool $strictNotCompleted = false, bool $compare = false): array
    {
        $current = $this->compute($period->start, $period->end, $strictNotCompleted);

        if (! $compare) {
            return [
                'period' => $period->toArray(),
                'metrics' => $current,
                'comparison' => null,
            ];
        }

        $prev = $period->previous();
        $previous = $this->compute($prev->start, $prev->end, $strictNotCompleted);

        return [
            'period' => $period->toArray(),
            'previous_period' => $prev->toArray(),
            'metrics' => $current,
            'comparison' => $this->diff($current, $previous),
        ];
    }

    /**
     * Compute every numeric metric for a half-open [start, end) window.
     */
    public function compute(CarbonImmutable $start, CarbonImmutable $end, bool $strictNotCompleted = false): array
    {
        $startS = $start->toDateTimeString();
        $endS = $end->toDateTimeString();

        // ---- Subscription snapshot counts (status as of period end) ----
        $active = $this->snapshotCount('active', $endS);
        $cancelledSnapshot = $this->snapshotCount('cancelled', $endS);

        // Cohort: subscribers who SIGNED UP in the period and are now cancelled,
        // split by whether they ever had a completed order (linked any time).
        $cancelledWith = $this->subscriptions()
            ->where('status', 'cancelled')
            ->where('date_created_gmt', '>=', $startS)
            ->where('date_created_gmt', '<', $endS)
            ->whereExists($this->completedLinkedOrder())
            ->count();

        $cancelledWithout = $this->subscriptions()
            ->where('status', 'cancelled')
            ->where('date_created_gmt', '>=', $startS)
            ->where('date_created_gmt', '<', $endS)
            ->whereNotExists($this->completedLinkedOrder())
            ->count();

        // ---- Order cohort counts (events inside the window) ----
        $completed = $this->ordersInWindow($startS, $endS)->where('status', 'completed')->count();

        $statusBreakdown = [];
        foreach (self::STATUS_BREAKDOWN as $s) {
            $statusBreakdown[$s] = $this->ordersInWindow($startS, $endS)->where('status', $s)->count();
        }

        $notCompletedStandard = $this->ordersInWindow($startS, $endS)->where('status', '!=', 'completed')->count();
        $notCompletedStrict = $this->ordersInWindow($startS, $endS)
            ->whereIn('status', self::STRICT_NOT_COMPLETED)->count();

        // ---- Supporting revenue totals (completed orders only) ----
        $totalRevenue = (float) $this->ordersInWindow($startS, $endS)
            ->where('status', 'completed')->sum('total_amount');
        $subscriptionRevenue = (float) $this->ordersInWindow($startS, $endS)
            ->where('status', 'completed')
            ->whereIn('order_relationship', self::SUBSCRIPTION_PURCHASE_RELS)
            ->sum('total_amount');
        $oneTimeRevenue = (float) $this->ordersInWindow($startS, $endS)
            ->where('status', 'completed')
            ->where('order_relationship', 'one_time')
            ->sum('total_amount');

        // ---- Retention / churn ----
        $statusBreakdownSub = [];
        foreach (\App\Models\Record::SUBSCRIPTION_STATUSES as $s) {
            $statusBreakdownSub[$s] = $this->snapshotCount($s, $endS);
        }
        $totalSubs = $this->subscriptions()->where('date_created_gmt', '<', $endS)->count();
        $churned = ($statusBreakdownSub['cancelled'] ?? 0) + ($statusBreakdownSub['expired'] ?? 0);

        $renewalsInWindow = $this->ordersInWindow($startS, $endS)->where('order_relationship', 'renewal');
        $renewalTotal = (clone $renewalsInWindow)->count();
        $renewalCompleted = (clone $renewalsInWindow)->where('status', 'completed')->count();
        $revenueAtRisk = (float) $this->ordersInWindow($startS, $endS)
            ->where('order_relationship', 'renewal')
            ->whereIn('status', ['failed', 'pending'])
            ->sum('total_amount');

        // ---- Customers (orders in window, keyed by customer_id) ----
        $byCustomer = $this->ordersInWindow($startS, $endS)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders')
            ->groupBy('customer_id')
            ->get();
        $uniqueCustomers = $byCustomer->count();
        $repeatCustomers = $byCustomer->where('orders', '>=', 2)->count();
        $newCustomers = $this->newCustomerCount($startS, $endS);

        return [
            // ----- Subscriptions -----
            'new_subscribers' => $this->subscriptions()
                ->where('date_created_gmt', '>=', $startS)
                ->where('date_created_gmt', '<', $endS)
                ->count(),
            'subscribers_active' => $active,
            'pending_cancellation' => $this->snapshotCount('pending-cancel', $endS),
            'on_hold' => $this->snapshotCount('on-hold', $endS),
            'cancelled_without_purchase' => $cancelledWithout,
            'cancelled_with_purchase' => $cancelledWith,

            // ----- Orders -----
            'one_time_purchase' => $this->ordersInWindow($startS, $endS)
                ->where('order_relationship', 'one_time')->count(),
            'subscription_purchases' => $this->ordersInWindow($startS, $endS)
                ->whereIn('order_relationship', self::SUBSCRIPTION_PURCHASE_RELS)->count(),
            'renewal_purchases' => $this->ordersInWindow($startS, $endS)
                ->where('order_relationship', 'renewal')->count(),
            'completed' => $completed,
            'new_not_completed' => $strictNotCompleted ? $notCompletedStrict : $notCompletedStandard,
            'new_not_completed_standard' => $notCompletedStandard,
            'new_not_completed_strict' => $notCompletedStrict,
            'not_completed_breakdown' => $statusBreakdown,

            // ----- Supporting totals -----
            'total_revenue' => round($totalRevenue, 2),
            'average_order_value' => $completed > 0 ? round($totalRevenue / $completed, 2) : 0.0,
            'subscription_revenue' => round($subscriptionRevenue, 2),
            'one_time_revenue' => round($oneTimeRevenue, 2),
            'cancelled_snapshot' => $cancelledSnapshot,
            'active_cancelled_ratio' => $cancelledSnapshot > 0
                ? round($active / $cancelledSnapshot, 2)
                : null, // undefined when no cancellations

            // ----- Retention / churn -----
            'subscription_status_breakdown' => $statusBreakdownSub,
            'total_subscriptions' => $totalSubs,
            'churn_rate' => $totalSubs > 0 ? round($churned / $totalSubs * 100, 1) : 0.0,
            'renewal_success_rate' => $renewalTotal > 0 ? round($renewalCompleted / $renewalTotal * 100, 1) : null,
            'failed_renewals' => $renewalTotal - $renewalCompleted,
            'revenue_at_risk' => round($revenueAtRisk, 2),

            // ----- Customers -----
            'unique_customers' => $uniqueCustomers,
            'new_customers' => $newCustomers,
            'returning_customers' => max(0, $uniqueCustomers - $newCustomers),
            'repeat_rate' => $uniqueCustomers > 0 ? round($repeatCustomers / $uniqueCustomers * 100, 1) : null,
            'revenue_per_customer' => $uniqueCustomers > 0 ? round($totalRevenue / $uniqueCustomers, 2) : 0.0,
        ];
    }

    /** Customers whose first-ever order falls inside the window (new acquisitions). */
    private function newCustomerCount(string $start, string $end): int
    {
        $firsts = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('customer_id')
            ->whereNotNull('date_created_gmt')
            ->selectRaw('customer_id, MIN(date_created_gmt) as first_order')
            ->groupBy('customer_id');

        return DB::query()->fromSub($firsts, 'f')
            ->where('first_order', '>=', $start)
            ->where('first_order', '<', $end)
            ->count();
    }

    /**
     * Lifetime top customers by completed spend.
     *
     * @return array<int, array{customer_id:int, orders:int, spend:float, email:?string}>
     */
    public function topCustomers(int $limit = 10): array
    {
        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders, SUM(total_amount) as spend, MAX(billing_email) as email')
            ->groupBy('customer_id')
            ->orderByDesc('spend')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'customer_id' => (int) $r->customer_id,
                'orders' => (int) $r->orders,
                'spend' => round((float) $r->spend, 2),
                'email' => $r->email,
            ])
            ->all();
    }

    /**
     * Trend data for one metric, bucketed by granularity.
     *
     * @param  string  $metric  one of the keys in {@see trendMetrics()}
     * @return array{labels: string[], values: array<int|float>, metric: string}
     */
    public function trend(string $metric, string $granularity, ?Period $window = null): array
    {
        $metrics = $this->trendMetrics();
        $spec = $metrics[$metric] ?? $metrics['new_subscribers'];

        $bucket = $this->bucketExpression($granularity);

        $query = DB::table('records')
            ->selectRaw("{$bucket} as bucket")
            ->whereNotNull('date_created_gmt');

        if ($spec['type'] === 'sum') {
            $query->selectRaw('COALESCE(SUM(total_amount), 0) as value');
        } else {
            $query->selectRaw('COUNT(*) as value');
        }

        ($spec['where'])($query);

        if ($window) {
            $query->where('date_created_gmt', '>=', $window->start->toDateTimeString())
                ->where('date_created_gmt', '<', $window->end->toDateTimeString());
        }

        $rows = $query->groupBy('bucket')->orderBy('bucket')->get();

        return [
            'metric' => $metric,
            'labels' => $rows->pluck('bucket')->map(fn ($b) => (string) $b)->all(),
            'values' => $rows->pluck('value')->map(fn ($v) => $spec['type'] === 'sum' ? (float) $v : (int) $v)->all(),
        ];
    }

    /**
     * Metrics offered for the trend chart, with their aggregate type and the
     * WHERE clause that defines them.
     *
     * @return array<string, array{label: string, type: string, where: callable}>
     */
    public function trendMetrics(): array
    {
        return [
            'new_subscribers' => [
                'label' => 'New Subscribers',
                'type' => 'count',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_subscription'),
            ],
            'completed' => [
                'label' => 'Completed Orders',
                'type' => 'count',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_order')->where('status', 'completed'),
            ],
            'total_revenue' => [
                'label' => 'Revenue (completed)',
                'type' => 'sum',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_order')->where('status', 'completed'),
            ],
            'renewal_purchases' => [
                'label' => 'Renewal Purchases',
                'type' => 'count',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_order')->where('order_relationship', 'renewal'),
            ],
            'one_time_purchase' => [
                'label' => 'One-time Purchases',
                'type' => 'count',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_order')->where('order_relationship', 'one_time'),
            ],
            'subscription_purchases' => [
                'label' => 'Subscription Purchases',
                'type' => 'count',
                'where' => fn (Builder $q) => $q->where('record_type', 'shop_order')
                    ->whereIn('order_relationship', self::SUBSCRIPTION_PURCHASE_RELS),
            ],
        ];
    }

    /**
     * Cohort retention: of the subscriptions that signed up in month M, how many
     * had a completed order (parent or renewal) in month M+k, for k = 0..maxOffset.
     *
     * @return array{offsets:int[], rows:array<int,array{cohort:string,size:int,cells:array<int,array{count:int,pct:float}>}>}
     */
    public function cohortRetention(int $maxOffset = 6, int $maxCohorts = 12): array
    {
        $subs = $this->subscriptions()
            ->whereNotNull('date_created_gmt')
            ->select('id', 'date_created_gmt')
            ->get();

        $cohortOf = [];   // sub id => 'Y-m'
        $cohortSize = []; // 'Y-m' => count
        foreach ($subs as $s) {
            $month = substr((string) $s->date_created_gmt, 0, 7);
            $cohortOf[$s->id] = $month;
            $cohortSize[$month] = ($cohortSize[$month] ?? 0) + 1;
        }

        $orders = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->select('subscription_id', 'date_created_gmt')
            ->get();

        $counts = []; // 'Y-m' => [offset => count of distinct subs]
        $seen = [];   // dedupe one sub per (cohort, offset)
        foreach ($orders as $o) {
            $sid = $o->subscription_id;
            if (! isset($cohortOf[$sid])) {
                continue;
            }
            $cohort = $cohortOf[$sid];
            $offset = $this->monthDiff($cohort, substr((string) $o->date_created_gmt, 0, 7));
            if ($offset < 0 || $offset > $maxOffset) {
                continue;
            }
            $key = "{$cohort}|{$offset}|{$sid}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $counts[$cohort][$offset] = ($counts[$cohort][$offset] ?? 0) + 1;
        }

        krsort($cohortSize); // most recent first
        $cohorts = array_slice(array_keys($cohortSize), 0, $maxCohorts);
        sort($cohorts);      // display oldest -> newest

        $rows = [];
        foreach ($cohorts as $cohort) {
            $size = $cohortSize[$cohort];
            $cells = [];
            for ($k = 0; $k <= $maxOffset; $k++) {
                $count = $counts[$cohort][$k] ?? 0;
                $cells[$k] = ['count' => $count, 'pct' => $size > 0 ? round($count / $size * 100, 1) : 0.0];
            }
            $rows[] = ['cohort' => $cohort, 'size' => $size, 'cells' => $cells];
        }

        return ['offsets' => range(0, $maxOffset), 'rows' => $rows];
    }

    /**
     * Customers who bought a one-off product first and later took out a
     * subscription — the upsell journey, with both dates.
     *
     * Identity is the **billing email** (present on both orders and
     * subscriptions, and unique per guest), falling back to `customer_id` when
     * an email is missing. `customer_id = 0` is WooCommerce's guest marker and
     * is never used as an identity — every guest shares it.
     *
     * Spend columns always sum **completed** orders only, whatever the
     * `$completedOnly` switch does to the counting.
     *
     * @param  bool  $conversionsOnly  keep only customers whose subscription started on/after their first one-time order
     * @param  bool  $completedOnly  treat only completed one-time orders as a purchase
     * @param  int  $limit  max rows returned (0 = all); the summary always counts everything
     * @return array{customers: array<int, array<string, mixed>>, summary: array<string, mixed>, total: int}
     */
    public function oneTimeToSubscription(bool $conversionsOnly = true, bool $completedOnly = false, int $limit = 0): array
    {
        $key = $this->customerKeyExpr();

        // One-time orders, aggregated per customer. The key is computed in a
        // derived table so the GROUP BY is on a plain column — grouping by the
        // raw expression trips MySQL's ONLY_FULL_GROUP_BY.
        $oneTimeRows = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('order_relationship', 'one_time')
            ->whereNotNull('date_created_gmt')
            ->when($completedOnly, fn (Builder $q) => $q->where('status', 'completed'))
            ->selectRaw("{$key} as ckey, date_created_gmt, status, total_amount, billing_email, customer_id");

        $oneTime = DB::query()->fromSub($oneTimeRows, 'ot')
            ->whereNotNull('ckey')
            ->selectRaw('ckey, MIN(date_created_gmt) as first_at, MAX(date_created_gmt) as last_at, COUNT(*) as orders')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as spend")
            ->selectRaw('MAX(billing_email) as email, MAX(customer_id) as customer_id')
            ->groupBy('ckey')
            ->get()
            ->keyBy('ckey');

        // Every subscription row, oldest first, bucketed per customer — we need
        // the individual sign-up dates, not an aggregate, to pick the first
        // subscription that follows the one-time order.
        $subs = DB::table('records')
            ->where('record_type', 'shop_subscription')
            ->whereNotNull('date_created_gmt')
            ->whereRaw("({$key}) IS NOT NULL")
            ->selectRaw("{$key} as ckey, id, status, date_created_gmt, billing_email")
            ->orderBy('date_created_gmt')
            ->get()
            ->groupBy('ckey');

        // Lifetime subscription revenue (completed parent + renewal orders).
        $subOrderRows = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('order_relationship', self::SUBSCRIPTION_PURCHASE_RELS)
            ->where('status', 'completed')
            ->selectRaw("{$key} as ckey, total_amount");

        $subRevenue = DB::query()->fromSub($subOrderRows, 'so')
            ->whereNotNull('ckey')
            ->selectRaw('ckey, COUNT(*) as orders, SUM(total_amount) as spend')
            ->groupBy('ckey')
            ->get()
            ->keyBy('ckey');

        $rows = [];
        $converted = 0;
        $daysTotal = 0;
        $daysCount = 0;

        foreach ($oneTime as $ckey => $ot) {
            $customerSubs = $subs[$ckey] ?? null;
            if ($customerSubs === null || $customerSubs->isEmpty()) {
                continue; // never subscribed
            }

            $firstOneTime = (string) $ot->first_at;

            // First subscription taken out on/after the first one-time order.
            $sub = null;
            foreach ($customerSubs as $candidate) {
                if ((string) $candidate->date_created_gmt >= $firstOneTime) {
                    $sub = $candidate;
                    break;
                }
            }

            $isConversion = $sub !== null;

            if (! $isConversion) {
                if ($conversionsOnly) {
                    continue; // subscribed before ever buying one-off
                }
                $sub = $customerSubs->first();
            }

            $days = $isConversion
                ? (int) floor((strtotime((string) $sub->date_created_gmt) - strtotime($firstOneTime)) / 86400)
                : null;

            if ($isConversion) {
                $converted++;
                $daysTotal += $days;
                $daysCount++;
            }

            $revenue = $subRevenue[$ckey] ?? null;

            $rows[] = [
                'key' => (string) $ckey,
                'email' => $ot->email ?: ($sub->billing_email ?: null),
                'customer_id' => (int) $ot->customer_id ?: null,
                'first_one_time_at' => $firstOneTime,
                'last_one_time_at' => (string) $ot->last_at,
                'one_time_orders' => (int) $ot->orders,
                'one_time_spend' => round((float) $ot->spend, 2),
                'subscription_id' => (int) $sub->id,
                'subscribed_at' => (string) $sub->date_created_gmt,
                'subscription_status' => (string) $sub->status,
                'subscriptions' => $customerSubs->count(),
                'subscription_orders' => (int) ($revenue->orders ?? 0),
                'subscription_spend' => round((float) ($revenue->spend ?? 0), 2),
                'days_to_convert' => $days,
                'is_conversion' => $isConversion,
            ];
        }

        // Most recent subscription first.
        usort($rows, fn ($a, $b) => strcmp($b['subscribed_at'], $a['subscribed_at']));

        $oneTimeCustomers = $oneTime->count();

        $summary = [
            'one_time_customers' => $oneTimeCustomers,
            'converted' => $converted,
            'listed' => count($rows),
            'conversion_rate' => $oneTimeCustomers > 0 ? round($converted / $oneTimeCustomers * 100, 1) : null,
            'avg_days_to_convert' => $daysCount > 0 ? (int) round($daysTotal / $daysCount) : null,
            'one_time_revenue' => round(array_sum(array_column($rows, 'one_time_spend')), 2),
            'subscription_revenue' => round(array_sum(array_column($rows, 'subscription_spend')), 2),
        ];

        return [
            'customers' => $limit > 0 ? array_slice($rows, 0, $limit) : $rows,
            'summary' => $summary,
            'total' => count($rows),
        ];
    }

    private function monthDiff(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));

        return ($ty - $fy) * 12 + ($tm - $fm);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function subscriptions(): Builder
    {
        return DB::table('records')->where('record_type', 'shop_subscription');
    }

    private function ordersInWindow(string $start, string $end): Builder
    {
        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('date_created_gmt', '>=', $start)
            ->where('date_created_gmt', '<', $end);
    }

    private function snapshotCount(string $status, string $end): int
    {
        return $this->subscriptions()
            ->where('status', $status)
            ->where('date_created_gmt', '<', $end)
            ->count();
    }

    /**
     * Driver-aware "who is this customer" key, shared by orders and
     * subscriptions: the billing email, or `cid:<customer_id>` when there is no
     * email. Guests (customer_id 0) and rows with neither yield NULL.
     */
    private function customerKeyExpr(): string
    {
        $concat = DB::connection()->getDriverName() === 'sqlite'
            ? "('cid:' || customer_id)"
            : "CONCAT('cid:', customer_id)";

        return "COALESCE(NULLIF(billing_email, ''), CASE WHEN customer_id > 0 THEN {$concat} END)";
    }

    /** Correlated subquery: a completed order linked to the outer subscription. */
    private function completedLinkedOrder(): \Closure
    {
        return function (Builder $q) {
            $q->from('records as linked_order')
                ->whereColumn('linked_order.subscription_id', 'records.id')
                ->where('linked_order.record_type', 'shop_order')
                ->where('linked_order.status', 'completed')
                ->selectRaw('1');
        };
    }

    /** Driver-aware date bucket key for grouping the trend. */
    private function bucketExpression(string $granularity): string
    {
        $driver = DB::connection()->getDriverName();
        $col = 'date_created_gmt';

        return match ($granularity) {
            'year' => $driver === 'sqlite'
                ? "strftime('%Y', {$col})"
                : "DATE_FORMAT({$col}, '%Y')",
            'week' => $driver === 'sqlite'
                ? "strftime('%Y-W%W', {$col})"
                : "DATE_FORMAT({$col}, '%x-W%v')",
            default => $driver === 'sqlite' // month (and custom fallback)
                ? "strftime('%Y-%m', {$col})"
                : "DATE_FORMAT({$col}, '%Y-%m')",
        };
    }

    /** Build per-metric current/previous/change deltas. */
    private function diff(array $current, array $previous): array
    {
        $out = [];

        foreach ($current as $key => $value) {
            if (! is_numeric($value) || ! isset($previous[$key]) || ! is_numeric($previous[$key])) {
                continue;
            }

            $prev = (float) $previous[$key];
            $curr = (float) $value;

            $out[$key] = [
                'previous' => $previous[$key],
                'change' => $prev == 0.0
                    ? ($curr == 0.0 ? 0.0 : null) // null = "new" (no baseline)
                    : round((($curr - $prev) / $prev) * 100, 1),
            ];
        }

        return $out;
    }
}
