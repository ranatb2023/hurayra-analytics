<?php

namespace App\Services;

use App\Models\Record;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes all dashboard metrics for a given {@see Period}.
 *
 * Time semantics (confirmed with the product owner):
 *  - Cohort metrics  (orders + "New Subscribers"): date_created_gmt in [start, end).
 *  - Point-in-time metrics (subscription status counts): the state the
 *    subscription was in at the period end, NOT the state it is in today. See
 *    "Point-in-time subscriptions" below.
 *  - Cancelled with/without purchase: a linked completed order counts whenever it
 *    happened (lifetime), joined at query time on subscription_id.
 *
 * ## Point-in-time subscriptions
 *
 * `records.status` is a live value overwritten by every import, so counting
 * `status = 'active'` answers "who is active *today*", not "who was active in
 * March". Left alone, last month's subscriber count shrinks every time someone
 * cancels — history rewrites itself.
 *
 * The fix is an end date. A subscription is counted as active at instant T when
 * it was created before T and had not ended before T:
 *
 *   created < T AND (status = 'active' OR (status is terminal AND end >= T))
 *
 * where "end" is {@see effectiveEndExpr()}: the imported `ended_at` when the CSV
 * carries one, otherwise the date of the subscription's last linked order — the
 * last point we can prove it was still being billed. Under this rule a customer
 * who was active in March and cancelled in June stays in March's count forever.
 *
 * `on-hold`, `pending` and `pending-cancel` are live states with no history in
 * the source data, so they are still read as-is and keep their own cards. A
 * subscription sitting in `pending-cancel` today has not cancelled yet, so once
 * it does cancel it correctly appears as active in the months before its end.
 *
 * The service is pure: it never touches the request or session.
 */
class MetricsService
{
    private const STATUS_BREAKDOWN = ['cancelled', 'failed', 'refunded', 'pending', 'processing'];

    private const STRICT_NOT_COMPLETED = ['pending', 'processing'];

    private const SUBSCRIPTION_PURCHASE_RELS = ['parent', 'renewal'];

    /** Statuses that mean "no longer a subscriber" — the ones churn is made of. */
    private const TERMINAL = Record::TERMINAL_SUBSCRIPTION_STATUSES;

    /** How many months {@see churnSeries()} returns by default. */
    private const CHURN_SERIES_MONTHS = 12;

    /**
     * How many billing cycles an `on-hold` subscription may miss before it is
     * read as dormant.
     *
     * Was a flat 45 days, which is only right for a monthly plan. This book is
     * mixed -- roughly 73% monthly, 16% two-monthly, 8% six-weekly -- so a fixed
     * count flagged healthy two-monthly subscribers as dead. The window is now
     * one cycle plus half a cycle of retry, per subscription.
     */
    private const ON_HOLD_DORMANT_CYCLES = 1.5;

    /** Fallback cycle when a subscription carries no billing period. */
    private const DEFAULT_CYCLE_DAYS = 30;

    /**
     * Columns {@see segmentPerformance()} may group by.
     *
     * Whitelisted because the name is interpolated into the SQL: a dimension
     * arrives from the query string, and it cannot be a bound parameter.
     */
    public const SEGMENT_DIMENSIONS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'attribution_type',
        'device_type', 'coupon_code', 'billing_period', 'primary_product',
    ];

    /**
     * How many segments a split trend plots. Past half a dozen lines a chart
     * stops being readable, so the tail is reported as a count rather than
     * drawn -- and never silently dropped.
     */
    private const TREND_SEGMENTS = 6;

    /** Segment label for rows carrying nothing in the split column. */
    private const UNATTRIBUTED = 'Not recorded';

    /**
     * Tenure buckets for {@see tenureAtChurn()}, as [label, inclusive upper
     * bound in days]. A null bound closes the open-ended final bucket.
     */
    private const TENURE_BUCKETS = [
        ['0–30 days', 30],
        ['31–60 days', 60],
        ['61–90 days', 90],
        ['91–180 days', 180],
        ['181+ days', null],
    ];

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
        // One roll-up over the window, grouped by status and relationship. Every
        // order figure below is a slice of it, rather than its own round trip.
        $orderStats = $this->orderRollup($startS, $endS);

        $completed = $orderStats->status('completed');

        $statusBreakdown = [];
        foreach (self::STATUS_BREAKDOWN as $s) {
            $statusBreakdown[$s] = $orderStats->status($s);
        }

        // Only statuses this app models. Deriving it as "everything minus
        // completed" is what swept deleted orders in, and would do the same for
        // any status a plugin invents tomorrow.
        $notCompletedStandard = array_sum(array_map(
            fn (string $st) => $orderStats->status($st),
            array_diff(Record::ORDER_STATUSES, ['completed']),
        ));
        $notCompletedStrict = array_sum(array_map(
            fn (string $st) => $orderStats->status($st),
            self::STRICT_NOT_COMPLETED,
        ));

        // ---- Supporting revenue totals (completed orders only) ----
        $totalRevenue = $orderStats->revenue();
        $subscriptionRevenue = $orderStats->revenue(self::SUBSCRIPTION_PURCHASE_RELS);
        $oneTimeRevenue = $orderStats->revenue(['one_time']);

        // total_amount is GROSS. VAT and shipping are roughly a quarter of it,
        // and refunded money is still sitting inside it, so the figure the
        // business actually keeps is a separate query.
        $money = $this->revenueBreakdown($startS, $endS);

        // ---- Retention / churn ----
        $statusBreakdownSub = [];
        foreach (Record::SUBSCRIPTION_STATUSES as $s) {
            $statusBreakdownSub[$s] = $this->snapshotCount($s, $endS);
        }
        $totalSubs = $this->subscriptions()->where('date_created_gmt', '<', $endS)->count();
        $churned = ($statusBreakdownSub['cancelled'] ?? 0) + ($statusBreakdownSub['expired'] ?? 0);

        // Monthly churn: who left DURING the window, over the base that was
        // active when it opened. Unlike the lifetime rate above this is a flow,
        // so it moves month to month instead of only ever climbing.
        $activeAtStart = $this->activeAsOf($startS);
        $churnedInPeriod = $this->churnedBetween($startS, $endS);

        // Subscriptions that ended having never completed an order are a broken
        // checkout, not a lost customer: nothing was ever earned to lose. They
        // are reported separately and netted out of the adjusted rate below.
        $failedSignups = $this->failedSignupsBetween($startS, $endS);
        $recurring = $this->recurringRevenue($endS);
        $realChurn = max(0, $churnedInPeriod - $failedSignups);
        $revenue = $this->revenueRetention($startS, $endS);

        $renewalTotal = $orderStats->relationship('renewal');
        $renewalCompleted = $orderStats->count('completed', 'renewal');
        $revenueAtRisk = $orderStats->amount('failed', 'renewal') + $orderStats->amount('pending', 'renewal');

        // ---- Customers (orders in window, keyed by customer_id) ----
        $byCustomer = $this->ordersInWindow($startS, $endS)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders')
            ->groupBy('customer_id')
            ->get();
        $uniqueCustomers = $byCustomer->count();
        $repeatCustomers = $byCustomer->where('orders', '>=', 2)->count();
        $newCustomers = $this->newCustomerCount($startS, $endS);
        $customerChurn = $this->customerChurn($startS, $endS);

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
            'one_time_purchase' => $orderStats->relationship('one_time'),
            'subscription_purchases' => array_sum(array_map(
                fn (string $rel) => $orderStats->relationship($rel),
                self::SUBSCRIPTION_PURCHASE_RELS,
            )),
            'renewal_purchases' => $orderStats->relationship('renewal'),
            'completed' => $completed,
            'new_not_completed' => $strictNotCompleted ? $notCompletedStrict : $notCompletedStandard,
            'new_not_completed_standard' => $notCompletedStandard,
            'new_not_completed_strict' => $notCompletedStrict,
            'not_completed_breakdown' => $statusBreakdown,

            // ----- Supporting totals -----
            'total_revenue' => round($totalRevenue, 2),
            'gross_revenue' => round($totalRevenue, 2),
            'net_revenue' => $money['net'],
            'tax_collected' => $money['tax'],
            'shipping_collected' => $money['shipping'],
            'refunded' => $money['refunded'],
            // What the business keeps: net of VAT, shipping and refunds.
            'net_revenue_after_refunds' => $money['net_after_refunds'],
            'net_revenue_known' => $money['known'],
            'gross_margin_pct' => $this->grossMarginPct(),
            'contribution' => $this->contributionFrom($money['net_after_refunds']),
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
            'monthly_churn_rate' => $activeAtStart > 0
                ? round($churnedInPeriod / $activeAtStart * 100, 1)
                : null, // undefined with no subscribers to lose
            'churned_in_period' => $churnedInPeriod,
            'failed_signups' => $failedSignups,
            'churned_net_of_failed' => $realChurn,
            // The rate with never-activated subscriptions taken out. Shown
            // alongside the gross rate rather than replacing it, so a month's
            // published figure never silently changes meaning.
            'monthly_churn_rate_net' => $activeAtStart > 0
                ? round($realChurn / $activeAtStart * 100, 1)
                : null,
            'on_hold_dormant' => $this->dormantOnHold($endS),
            'mrr' => $recurring['mrr'],
            'arr' => $recurring['arr'],
            'arpu' => $recurring['arpu'],
            'net_revenue_retention' => $revenue['nrr'],
            'gross_revenue_retention' => $revenue['grr'],
            'recurring_revenue_start' => $revenue['start'],
            'recurring_revenue_retained' => $revenue['retained'],
            'active_at_period_start' => $activeAtStart,
            'tenure_at_churn' => $this->tenureAtChurn($startS, $endS),
            'end_date_coverage' => $this->endDateCoverage($endS),
            'renewal_success_rate' => $renewalTotal > 0 ? round($renewalCompleted / $renewalTotal * 100, 1) : null,
            'failed_renewals' => $renewalTotal - $renewalCompleted,
            'revenue_at_risk' => round($revenueAtRisk, 2),

            // ----- Customers -----
            'unique_customers' => $uniqueCustomers,
            // Deduplicated on billing email. `customer_id` and email disagree:
            // guests share id 0, and one person can hold several ids.
            'unique_customers_deduped' => $this->uniqueCustomersDeduped($startS, $endS),
            'customers_active_at_start' => $customerChurn['active_start'],
            'customers_churned' => $customerChurn['churned'],
            // A customer has churned only when EVERY subscription they hold has
            // ended. 27% of cancellations belong to people who still have a live
            // one, so the subscription-level rate overstates customer loss.
            'customer_churn_rate' => $customerChurn['rate'],
            'unrecognised_statuses' => $this->unrecognisedStatuses($startS, $endS),
            'new_customers' => $newCustomers,
            'returning_customers' => max(0, $uniqueCustomers - $newCustomers),
            'repeat_rate' => $uniqueCustomers > 0 ? round($repeatCustomers / $uniqueCustomers * 100, 1) : null,
            'revenue_per_customer' => $uniqueCustomers > 0 ? round($totalRevenue / $uniqueCustomers, 2) : 0.0,
        ];
    }

    /**
     * Every order in the window, counted and summed once, grouped by status and
     * relationship.
     *
     * {@see compute()} needs about fifteen different slices of the same set of
     * rows; asking the database for each one separately was fifteen scans of
     * identical data. The returned object answers all of them from one.
     */
    private function orderRollup(string $start, string $end): OrderRollup
    {
        $rows = $this->ordersInWindow($start, $end)
            ->selectRaw('status, order_relationship, COUNT(*) as n, SUM(total_amount) as amount')
            ->groupBy('status', 'order_relationship')
            ->get();

        return new OrderRollup($rows->all());
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
    public function trend(string $metric, string $granularity, ?Period $window = null, ?string $breakdown = null): array
    {
        $metrics = $this->trendMetrics();
        $key = isset($metrics[$metric]) ? $metric : 'new_subscribers';
        $spec = $metrics[$key];

        // Whitelisted: the column is interpolated into the SELECT below.
        $breakdown = in_array($breakdown, self::SEGMENT_DIMENSIONS, true) ? $breakdown : null;

        $bucket = $this->bucketExpression($granularity);

        $query = DB::table('records')
            ->selectRaw("{$bucket} as bucket")
            // Ranks the segments of a split. Every metric has one, including
            // the ratios, whose own value says nothing about size.
            ->selectRaw('COUNT(*) as segment_rows')
            ->whereNotNull('date_created_gmt');

        foreach ($spec['select'] as $select) {
            $query->selectRaw($select);
        }

        ($spec['where'])($query);

        if ($window) {
            $query->where('date_created_gmt', '>=', $window->start->toDateTimeString())
                ->where('date_created_gmt', '<', $window->end->toDateTimeString());
        }

        $query->groupBy('bucket');

        if ($breakdown !== null) {
            // Rows carrying nothing are a real segment -- usually the biggest
            // one -- so they are labelled rather than quietly dropped.
            $query->selectRaw("COALESCE(NULLIF({$breakdown}, ''), ?) as segment", [self::UNATTRIBUTED])
                ->groupBy('segment');
        }

        $rows = $query->orderBy('bucket')->get();

        $labels = $rows->pluck('bucket')->map(fn ($b) => (string) $b)->unique()->values()->all();

        $base = [
            'metric' => $key,
            'label' => $spec['label'],
            'unit' => $spec['unit'],
            'breakdown' => $breakdown,
            'labels' => $labels,
            // Which point, if any, is a bucket still filling up. Drawn solid it
            // reads as a fall that has not happened.
            'partial_bucket' => $this->partialBucket($granularity),
        ];

        if ($breakdown === null) {
            return $base + [
                'values' => $rows->map(fn ($row) => ($spec['reduce'])($row))->all(),
                'series' => [],
                'other_segments' => 0,
            ];
        }

        return $base + ['values' => []] + $this->splitSeries($rows, $labels, $spec);
    }

    /**
     * Pivot grouped (bucket, segment) rows into one series per segment.
     *
     * Only the largest segments are plotted; the rest are counted so a capped
     * chart cannot read as the whole picture. Small segments are not merged
     * into an "Other" line -- summing two averages or two rates would invent a
     * number that is in no row of the data.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<int, string>  $labels
     * @param  array<string, mixed>  $spec
     * @return array{series: array<int, array{label:string, values:array<int, ?float>}>, other_segments:int}
     */
    private function splitSeries($rows, array $labels, array $spec): array
    {
        $values = [];   // segment => bucket => value
        $volume = [];   // segment => rows behind it, for ranking

        foreach ($rows as $row) {
            $segment = (string) $row->segment;
            $values[$segment][(string) $row->bucket] = ($spec['reduce'])($row);
            $volume[$segment] = ($volume[$segment] ?? 0) + (int) $row->segment_rows;
        }

        arsort($volume);
        $top = array_slice(array_keys($volume), 0, self::TREND_SEGMENTS);

        $series = [];

        foreach ($top as $segment) {
            $series[] = [
                'label' => $segment,
                // A bucket the segment has no rows in is a zero for a count or
                // a sum, but a gap for an average: nothing was ordered, which
                // is not an order worth nothing.
                'values' => array_map(
                    fn (string $bucket) => $values[$segment][$bucket] ?? $spec['empty'],
                    $labels,
                ),
            ];
        }

        return [
            'series' => $series,
            'other_segments' => max(0, count($volume) - count($top)),
        ];
    }

    /**
     * Metrics offered for the trend chart.
     *
     * Every spec carries the same four things: the aggregates to select, a
     * reducer turning one grouped row into the plotted number, the unit that
     * number is in, and the filter that defines the metric. Counts, sums and
     * ratios therefore share one query path, which is what lets an average or
     * a rate be trended at all -- neither is expressible as a single aggregate.
     *
     * @return array<string, array{label:string, unit:string, select:array<int, string>, reduce:callable, empty:?float, where:callable}>
     */
    public function trendMetrics(): array
    {
        // Trashed and draft orders are not business data anywhere else on the
        // dashboard. Excluding them here too means a trend and the card above
        // it are counting the same orders.
        $orders = fn (Builder $q) => $q->where('record_type', 'shop_order')
            ->whereNotIn('status', Record::EXCLUDED_ORDER_STATUSES);
        $completed = fn (Builder $q) => $orders($q)->where('status', 'completed');
        $renewals = fn (Builder $q) => $orders($q)->where('order_relationship', 'renewal');

        return [
            'new_subscribers' => $this->countMetric(
                'New Subscribers',
                fn (Builder $q) => $q->where('record_type', 'shop_subscription'),
            ),
            'completed' => $this->countMetric('Completed Orders', $completed),

            // What the business keeps. Listed above the gross line on purpose:
            // gross carries VAT, shipping and refunded money, which is about a
            // quarter of it, and it is the number people quote by accident.
            'net_revenue' => [
                'label' => 'Net Revenue (after refunds)',
                'unit' => 'currency',
                // `net_amount` is null on files exported before the column
                // existed. Those buckets plot as a gap: falling back to gross
                // would restore the very overstatement this metric removes,
                // and a zero would claim a month earned nothing.
                'select' => [
                    'SUM(net_amount) as net',
                    'COUNT(net_amount) as with_net',
                    'COALESCE(SUM(refunded_amount), 0) as refunded',
                ],
                'reduce' => fn ($row) => (int) $row->with_net === 0
                    ? null
                    : round((float) $row->net - (float) $row->refunded, 2),
                'empty' => null,
                'where' => $completed,
            ],

            'total_revenue' => $this->sumMetric('Revenue (gross)', 'total_amount', $completed),
            'average_order_value' => $this->ratioMetric(
                'Average Order Value', 'SUM(total_amount)', 'COUNT(*)', 'currency', $completed,
            ),
            'refunded' => $this->sumMetric('Refunds', 'refunded_amount', $completed),

            // The leading indicator of involuntary churn: a card renewal that
            // fails is a subscriber lost some weeks before the cancellation
            // shows up in the churn rate.
            'renewal_success_rate' => $this->ratioMetric(
                'Renewal Success Rate',
                "SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)",
                'COUNT(*)',
                'percent',
                $renewals,
            ),

            'renewal_purchases' => $this->countMetric('Renewal Purchases', $renewals),
            'one_time_purchase' => $this->countMetric(
                'One-time Purchases',
                fn (Builder $q) => $orders($q)->where('order_relationship', 'one_time'),
            ),
            'subscription_purchases' => $this->countMetric(
                'Subscription Purchases',
                fn (Builder $q) => $orders($q)->whereIn('order_relationship', self::SUBSCRIPTION_PURCHASE_RELS),
            ),
        ];
    }

    /** A trend metric that counts the rows matching its filter. */
    private function countMetric(string $label, callable $where): array
    {
        return [
            'label' => $label,
            'unit' => 'count',
            'select' => ['COUNT(*) as v'],
            'reduce' => fn ($row) => (int) $row->v,
            'empty' => 0.0,
            'where' => $where,
        ];
    }

    /** A trend metric that sums one column over the rows matching its filter. */
    private function sumMetric(string $label, string $column, callable $where): array
    {
        return [
            'label' => $label,
            'unit' => 'currency',
            'select' => ["COALESCE(SUM({$column}), 0) as v"],
            'reduce' => fn ($row) => round((float) $row->v, 2),
            'empty' => 0.0,
            'where' => $where,
        ];
    }

    /**
     * A trend metric that divides one aggregate by another.
     *
     * An empty denominator plots as a gap rather than a zero: a month with no
     * renewals due has no renewal success rate, and drawing it at 0% would
     * report a total failure that never happened.
     */
    private function ratioMetric(
        string $label,
        string $numerator,
        string $denominator,
        string $unit,
        callable $where,
    ): array {
        $scale = $unit === 'percent' ? 100 : 1;
        $places = $unit === 'percent' ? 1 : 2;

        return [
            'label' => $label,
            'unit' => $unit,
            'select' => ["{$numerator} as num", "{$denominator} as den"],
            'reduce' => fn ($row) => (float) $row->den > 0
                ? round((float) $row->num / (float) $row->den * $scale, $places)
                : null,
            'empty' => null,
            'where' => $where,
        ];
    }

    /**
     * Month-by-month subscriber history: how many were active when each month
     * opened, how many joined, how many left, and the resulting churn rate.
     *
     * Every figure is derived from each subscription's sign-up and end dates, so
     * a month's numbers are fixed once it is over. Somebody cancelling in June
     * changes June's row and nothing before it — re-running this next year gives
     * the same answer for March that it gives today.
     *
     * @param  int  $months  how many trailing months to return (1–60)
     * @return array{rows: array<int, array{month:string, active_start:int, new:int, churned:int, active_end:int, churn_rate:?float, partial:bool}>, end_date_coverage: ?float}
     */
    public function churnSeries(int $months = self::CHURN_SERIES_MONTHS): array
    {
        $months = max(1, min(60, $months));

        $subs = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->selectRaw('s.status, s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended')
            ->get()
            ->map(fn ($r) => [
                'created' => (string) $r->created,
                'ended' => $r->ended === null ? null : (string) $r->ended,
                'terminal' => in_array((string) $r->status, self::TERMINAL, true),
                'active' => (string) $r->status === 'active',
            ])
            ->all();

        if ($subs === []) {
            return ['rows' => [], 'end_date_coverage' => null];
        }

        $latest = $this->latestDataInstant();
        $lastMonth = $latest->startOfMonth();
        $firstMonth = $lastMonth->subMonths($months - 1);

        $rows = [];

        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->addMonth()) {
            $monthEnd = $cursor->addMonth();
            $startS = $cursor->toDateTimeString();
            $endS = $monthEnd->toDateTimeString();

            $activeStart = 0;
            $activeEnd = 0;
            $joined = 0;
            $churned = 0;

            foreach ($subs as $sub) {
                if ($this->wasActiveAt($sub, $startS)) {
                    $activeStart++;
                }
                if ($this->wasActiveAt($sub, $endS)) {
                    $activeEnd++;
                }
                if ($sub['created'] >= $startS && $sub['created'] < $endS) {
                    $joined++;
                }
                if ($sub['terminal'] && $sub['ended'] !== null
                    && $sub['ended'] >= $startS && $sub['ended'] < $endS) {
                    $churned++;
                }
            }

            $rows[] = [
                'month' => $cursor->format('Y-m'),
                'active_start' => $activeStart,
                'new' => $joined,
                'churned' => $churned,
                'active_end' => $activeEnd,
                'churn_rate' => $activeStart > 0 ? round($churned / $activeStart * 100, 1) : null,
                // The month the data stops inside is only counted up to the
                // last row imported. Charting it undashed draws a cliff that is
                // missing rows, not lost subscribers.
                'partial' => $latest->lt($monthEnd),
            ];
        }

        return [
            'rows' => $rows,
            'end_date_coverage' => $this->endDateCoverage($lastMonth->addMonth()->toDateTimeString()),
        ];
    }

    /**
     * The PHP twin of {@see activeAsOf()}'s SQL predicate, for the in-memory
     * month walk. Kept next to it so the two definitions stay in step.
     *
     * @param  array{created:string, ended:?string, terminal:bool, active:bool}  $sub
     */
    private function wasActiveAt(array $sub, string $instant): bool
    {
        if ($sub['created'] >= $instant) {
            return false;
        }

        if ($sub['active']) {
            return true;
        }

        return $sub['terminal'] && $sub['ended'] !== null && $sub['ended'] >= $instant;
    }

    /**
     * Month-by-month net churn and revenue retention.
     *
     * Both are computed metrics -- {@see trend()} can only bucket raw counts and
     * sums, so neither can be expressed there. Running {@see compute()} twelve
     * times would answer it at roughly 27 queries a month; instead the whole
     * subscription book and its completed orders are read once and every month
     * is walked in memory.
     *
     * @param  int  $months  trailing months to return (1-60)
     * @return array{rows: array<int, array{month:string, churn_net:?float, nrr:?float, partial:bool}>}
     */
    public function retentionSeries(int $months = self::CHURN_SERIES_MONTHS): array
    {
        $months = max(1, min(60, $months));

        $subs = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->selectRaw('s.id, s.status, s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'created' => (string) $r->created,
                'ended' => $r->ended === null ? null : (string) $r->ended,
                'terminal' => in_array((string) $r->status, self::TERMINAL, true),
                'active' => (string) $r->status === 'active',
            ])
            ->all();

        if ($subs === []) {
            return ['rows' => []];
        }

        $payments = $this->completedPaymentsBySubscription();

        $latest = $this->latestDataInstant();
        $lastMonth = $latest->startOfMonth();
        $firstMonth = $lastMonth->subMonths($months - 1);

        $rows = [];

        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->addMonth()) {
            $monthEnd = $cursor->addMonth();
            $startS = $cursor->toDateTimeString();
            $endS = $monthEnd->toDateTimeString();

            $activeStart = 0;
            $churned = 0;
            $failed = 0;
            $base = 0.0;
            $retained = 0.0;

            foreach ($subs as $sub) {
                $open = $this->wasActiveAt($sub, $startS);

                if ($open) {
                    $activeStart++;
                }

                $leftNow = $sub['terminal'] && $sub['ended'] !== null
                    && $sub['ended'] >= $startS && $sub['ended'] < $endS;

                if ($leftNow) {
                    $churned++;

                    if (! isset($payments[$sub['id']])) {
                        $failed++; // never billed: a checkout defect, not churn
                    }
                }

                if (! $open) {
                    continue;
                }

                $was = $this->paymentBefore($payments[$sub['id']] ?? [], $startS);

                if ($was <= 0.0) {
                    continue;
                }

                // Live subscriptions have no meaningful end date, so only a
                // terminal one can have stopped before the window closed.
                $stillRunning = ! $sub['terminal'] || $sub['ended'] === null || $sub['ended'] >= $endS;

                $base += $was;
                $retained += $stillRunning ? $this->paymentBefore($payments[$sub['id']] ?? [], $endS) : 0.0;
            }

            $real = max(0, $churned - $failed);

            $rows[] = [
                'month' => $cursor->format('Y-m'),
                'churn_net' => $activeStart > 0 ? round($real / $activeStart * 100, 1) : null,
                'nrr' => $base > 0 ? round($retained / $base * 100, 1) : null,
                'partial' => $latest->lt($monthEnd),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * The monthly history the growth and retention charts are drawn from:
     * {@see churnSeries()} joined to {@see retentionSeries()} on the month.
     *
     * The two walks answer different halves of the same question -- one the
     * subscriber count, the other the rates -- and every chart wants both. One
     * endpoint returning one row per month keeps the charts and the history
     * table reading from a single series, so a figure cannot differ between
     * the picture and the table under it.
     *
     * @param  int  $months  trailing months to return (1-60)
     * @return array{rows: array<int, array<string, mixed>>, end_date_coverage: ?float}
     */
    public function history(int $months = self::CHURN_SERIES_MONTHS): array
    {
        $churn = $this->churnSeries($months);
        $rates = array_column($this->retentionSeries($months)['rows'], null, 'month');
        $money = array_column($this->mrrSeries($months)['rows'], null, 'month');

        $rows = array_map(function (array $row) use ($rates, $money) {
            $rate = $rates[$row['month']] ?? [];
            $mrr = $money[$row['month']] ?? [];

            return $row + [
                'churn_net' => $rate['churn_net'] ?? null,
                'nrr' => $rate['nrr'] ?? null,
                'mrr' => $mrr['mrr'] ?? null,
                'arpu' => $mrr['arpu'] ?? null,
                'paying' => $mrr['paying'] ?? null,
            ];
        }, $churn['rows']);

        return [
            'rows' => $rows,
            'end_date_coverage' => $churn['end_date_coverage'],
        ];
    }

    /**
     * The newest activity in the data.
     *
     * Every month walk anchors here rather than on "now", so a dataset that
     * stops in June does not trail empty months -- and the month this instant
     * falls inside is the one that is still filling up.
     *
     * Public because the dashboard's opening filter has to agree with it: a
     * filter defaulted to the wall-clock month opens on an empty period the
     * moment the export is a month behind, which reads as a broken import.
     */
    public function latestDataInstant(): CarbonImmutable
    {
        $latest = (string) DB::table('records')->max('date_created_gmt');

        return CarbonImmutable::parse($latest !== '' ? $latest : 'now');
    }

    /**
     * Month-by-month recurring revenue.
     *
     * {@see recurringRevenue()} answers "what is the book worth now" by reading
     * `status = 'active'`, which is a live value. Asking it about March would
     * price March using the subscriptions still running today, so it cannot be
     * walked backwards. This uses the same lifecycle dates the subscriber
     * counts use, which is what makes a closed month's MRR stay put.
     *
     * Each subscription contributes its last payment normalised to 30 days:
     * about a quarter of this book renews on something other than a monthly
     * cycle, and booking a six-weekly payment as a month of revenue overstates
     * it by half.
     *
     * @param  int  $months  trailing months to return (1-60)
     * @return array{rows: array<int, array{month:string, mrr:float, arr:float, arpu:?float, paying:int, partial:bool}>}
     */
    public function mrrSeries(int $months = self::CHURN_SERIES_MONTHS): array
    {
        $months = max(1, min(60, $months));

        $subs = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->selectRaw('s.id, s.status, s.date_created_gmt as created, s.billing_period, '.
                's.billing_interval, '.$this->effectiveEndExpr().' as ended')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'created' => (string) $r->created,
                'ended' => $r->ended === null ? null : (string) $r->ended,
                'terminal' => in_array((string) $r->status, self::TERMINAL, true),
                'active' => (string) $r->status === 'active',
                // What one month of this subscription is worth, as a share of
                // whatever it pays per cycle.
                'monthly_share' => 30 / $this->cycleDays(
                    $r->billing_period === null ? null : (string) $r->billing_period,
                    $r->billing_interval === null ? null : (int) $r->billing_interval,
                ),
            ])
            ->all();

        if ($subs === []) {
            return ['rows' => []];
        }

        $payments = $this->completedPaymentsBySubscription();

        $latest = $this->latestDataInstant();
        $lastMonth = $latest->startOfMonth();
        $firstMonth = $lastMonth->subMonths($months - 1);

        $rows = [];

        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->addMonth()) {
            $monthEnd = $cursor->addMonth();
            $endS = $monthEnd->toDateTimeString();

            $mrr = 0.0;
            $paying = 0;

            foreach ($subs as $sub) {
                if (! $this->wasActiveAt($sub, $endS)) {
                    continue;
                }

                $amount = $this->paymentBefore($payments[$sub['id']] ?? [], $endS);

                if ($amount <= 0.0) {
                    continue; // never billed, so nothing is recurring yet
                }

                $paying++;
                $mrr += $amount * $sub['monthly_share'];
            }

            $rows[] = [
                'month' => $cursor->format('Y-m'),
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                // MRR is paying subscribers times what each of them pays.
                // Carrying both says which of the two actually moved.
                'arpu' => $paying > 0 ? round($mrr / $paying, 2) : null,
                'paying' => $paying,
                'partial' => $latest->lt($monthEnd),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Every completed payment, keyed by subscription, oldest first.
     *
     * @return array<int, array<int, array{0:string, 1:float}>>
     */
    private function completedPaymentsBySubscription(): array
    {
        $payments = [];

        foreach (DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->orderBy('date_created_gmt')
            ->select('subscription_id', 'date_created_gmt', 'total_amount')
            ->get() as $o) {
            $payments[(int) $o->subscription_id][] = [(string) $o->date_created_gmt, (float) $o->total_amount];
        }

        return $payments;
    }

    /**
     * The amount of the last payment strictly before $instant.
     *
     * @param  array<int, array{0:string, 1:float}>  $payments  oldest first
     */
    private function paymentBefore(array $payments, string $instant): float
    {
        $amount = 0.0;

        foreach ($payments as [$date, $value]) {
            if ($date >= $instant) {
                break;
            }

            $amount = $value;
        }

        return $amount;
    }

    /**
     * How subscriptions acquired through each segment actually performed.
     *
     * The churn rate says the book is leaking; this says which taps it came out
     * of. `$dimension` is any column travelling on the subscription row --
     * `utm_source`, `utm_medium`, `device_type`, `coupon_code`, `billing_period`
     * -- so one method answers channel, device and pricing questions alike.
     *
     * Reaching a SECOND payment is the headline: for a subscription business it
     * predicts lifetime value better than anything else available this early,
     * and roughly half of all payers here never get there.
     *
     * @param  string  $dimension  a whitelisted column on the subscription row
     * @param  int  $min  drop segments smaller than this; they are noise
     * @return array{dimension:string, rows:array<int, array<string, mixed>>}
     */
    public function segmentPerformance(string $dimension = 'utm_source', int $min = 5): array
    {
        if (! in_array($dimension, self::SEGMENT_DIMENSIONS, true)) {
            $dimension = 'utm_source';
        }

        // Payments per subscription: the parent order plus every completed
        // renewal. Counting renewals alone would report every one-payment
        // subscriber as having never paid at all.
        $paid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as payments, SUM(total_amount) as revenue')
            ->groupBy('subscription_id');

        $rows = DB::table('records as s')
            ->leftJoinSub($paid, 'p', 'p.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->selectRaw("COALESCE(NULLIF(s.{$dimension}, ''), '(unattributed)') as segment")
            ->selectRaw('COUNT(*) as subs')
            ->selectRaw('SUM(CASE WHEN COALESCE(p.payments, 0) = 0 THEN 1 ELSE 0 END) as never_paid')
            ->selectRaw('SUM(CASE WHEN COALESCE(p.payments, 0) >= 2 THEN 1 ELSE 0 END) as repeat_payers')
            ->selectRaw("SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as still_active")
            ->selectRaw('SUM(COALESCE(p.revenue, 0)) as revenue')
            ->groupBy('segment')
            ->havingRaw('COUNT(*) >= ?', [max(1, $min)])
            ->orderByDesc('subs')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $subs = (int) $r->subs;
            $revenue = (float) $r->revenue;

            $repeatPct = round((int) $r->repeat_payers / $subs * 100, 1);
            $margin = $this->marginOfError((int) $r->repeat_payers, $subs);
            $ltv = round($revenue / $subs, 2);

            $out[] = [
                'segment' => (string) $r->segment,
                'subs' => $subs,
                'never_paid' => (int) $r->never_paid,
                'never_paid_pct' => round((int) $r->never_paid / $subs * 100, 1),
                'repeat_pct' => $repeatPct,
                // A rate without its sample size invites acting on noise: at
                // n = 13 a 69% repeat rate spans roughly 44%-94%.
                'repeat_margin' => $margin,
                'repeat_low' => round(max(0, $repeatPct - $margin), 1),
                'repeat_high' => round(min(100, $repeatPct + $margin), 1),
                'reliable' => $subs >= (int) config('metrics.segment_min_reliable_n', 30),
                'still_active' => (int) $r->still_active,
                'revenue' => round($revenue, 2),
                'ltv' => $ltv,
                // Null until a gross margin is configured — LTV is a revenue
                // number, not a profit one, and guessing the difference is worse
                // than reporting that nobody has supplied it.
                'contribution' => $this->contributionFrom($ltv),
                'payback_ratio' => $this->paybackRatio($ltv),
            ];
        }

        return ['dimension' => $dimension, 'rows' => $out];
    }

    /**
     * 95% margin of error on a proportion, in percentage points.
     *
     * The normal approximation is good enough for the job it does here, which
     * is to stop a six-subscription segment being read as a finding.
     */
    private function marginOfError(int $hits, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }

        $p = $hits / $n;

        // At p = 0 or p = 1 the normal approximation collapses to zero, which
        // reads as certainty from a handful of rows — the exact false
        // confidence this figure exists to prevent. Rule of three instead:
        // the 95% upper bound on an event not yet seen in n trials is ~3/n.
        if ($hits === 0 || $hits === $n) {
            return round(min(100, 300 / $n), 1);
        }

        return round(1.96 * sqrt($p * (1 - $p) / $n) * 100, 1);
    }

    /**
     * Lifetime contribution over acquisition cost.
     *
     * Above 1 the subscriber paid for itself; 3 is the usual health mark. Null
     * unless both the margin and a CAC have been configured, because a payback
     * number invented from one of them is worse than none.
     */
    private function paybackRatio(float $ltv): ?float
    {
        $cac = config('metrics.cac');
        $contribution = $this->contributionFrom($ltv);

        if ($cac === null || $contribution === null || (float) $cac <= 0) {
            return null;
        }

        return round($contribution / (float) $cac, 2);
    }

    /**
     * Campaign audiences, each one a list you could act on today.
     *
     *  cross_sell   customers on a single-flavour plan. The Combo is the best
     *               seller and multi-subscription households are worth 2.3x a
     *               single-subscription one, so this is the upgrade conversation.
     *  win_back     customers whose every subscription has ended, who paid at
     *               least `$minPayments` times. Proven habitual buyers, not
     *               tyre-kickers, so a win-back has a better prior than cold
     *               acquisition.
     *  never_subscribed  one-time buyers who never took a subscription. They
     *               have already bought and liked it; 15.8% convert unaided.
     *  partial_churn  customers who cancelled one subscription but kept another.
     *               Counted as churn today, still buying in reality — a
     *               cross-sell list the dashboard currently hides among losses.
     *
     * @return array{audience:string, total:int, value:float, rows:array<int, array<string, mixed>>}
     */
    public function audience(string $audience, ?int $limit = 500): array
    {
        $rows = match ($audience) {
            'win_back' => $this->winBackAudience(),
            'never_subscribed' => $this->neverSubscribedAudience(),
            'partial_churn' => $this->partialChurnAudience(),
            default => $this->crossSellAudience(),
        };

        // Warmest first: what they have already spent is the best available
        // proxy for how likely the next conversation is to land.
        usort($rows, fn ($a, $z) => $z['value'] <=> $a['value']);

        return [
            'audience' => $audience,
            'total' => count($rows),
            'value' => round(array_sum(array_column($rows, 'value')), 2),
            'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
        ];
    }

    /** Live single-flavour subscribers — the Combo upgrade conversation. */
    private function crossSellAudience(): array
    {
        $spend = $this->completedSpendPerSubscription();

        $rows = $this->subscriptions()
            ->whereIn('status', ['active', 'on-hold'])
            ->whereNotNull('billing_email')
            ->select('id', 'billing_email', 'customer_id', 'billing_period', 'billing_interval', 'next_payment_at')
            ->get();

        // The product sits on the orders, not the subscription row.
        $product = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('subscription_id')
            ->whereNotNull('primary_product')
            ->selectRaw('subscription_id, MAX(primary_product) as product')
            ->groupBy('subscription_id')
            ->pluck('product', 'subscription_id')
            ->all();

        $out = [];

        foreach ($rows as $r) {
            $name = $product[(int) $r->id] ?? null;

            // Already on a multi-flavour plan: nothing to upgrade them to.
            if ($name === null || stripos($name, 'combo') !== false) {
                continue;
            }

            $out[] = [
                'customer' => $r->billing_email,
                'subscription_id' => (int) $r->id,
                'detail' => $name,
                'cycle' => $this->cycleLabel($r->billing_period, $r->billing_interval),
                'next_at' => $r->next_payment_at,
                'payments' => (int) ($spend[(int) $r->id]['n'] ?? 0),
                'value' => round((float) ($spend[(int) $r->id]['v'] ?? 0), 2),
            ];
        }

        return $out;
    }

    /** Customers with nothing live left, who paid at least three times. */
    private function winBackAudience(int $minPayments = 3): array
    {
        $spend = $this->completedSpendPerSubscription();
        $live = array_flip($this->customerKeysWithLiveSubscription());
        $out = [];

        // Newest departure first, one row per customer: somebody who held three
        // subscriptions and let them all lapse is one person to win back, not
        // three.
        $rows = $this->subscriptions()
            ->whereIn('status', self::TERMINAL)
            ->whereNotNull('billing_email')
            ->select('id', 'billing_email', 'customer_id', 'ended_at')
            ->orderByDesc('ended_at')
            ->get();

        $seen = [];

        foreach ($rows as $r) {
            $key = $this->customerKey($r->billing_email, (int) ($r->customer_id ?? 0));
            $paid = (int) ($spend[(int) $r->id]['n'] ?? 0);

            if ($key === null || isset($live[$key]) || isset($seen[$key]) || $paid < $minPayments) {
                continue;
            }

            $seen[$key] = true;

            $out[] = [
                'customer' => $r->billing_email,
                'subscription_id' => (int) $r->id,
                'detail' => $paid.' payments before leaving',
                'cycle' => null,
                'next_at' => $r->ended_at,
                'payments' => $paid,
                'value' => round((float) ($spend[(int) $r->id]['v'] ?? 0), 2),
            ];
        }

        return $out;
    }

    /** One-time buyers who have never held a subscription. */
    private function neverSubscribedAudience(): array
    {
        $key = $this->customerKeyExpr();

        $subscribers = $this->subscriptions()
            ->whereNotNull('billing_email')
            ->selectRaw("{$key} as ckey");

        $rows = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->where('order_relationship', 'one_time')
            ->whereNotIn('status', Record::EXCLUDED_ORDER_STATUSES)
            ->whereNotNull('billing_email')
            ->whereNotIn(DB::raw($key), $subscribers)
            ->selectRaw("{$key} as ckey, MAX(billing_email) as email, COUNT(*) as orders, SUM(total_amount) as spend")
            ->selectRaw('MAX(date_created_gmt) as last_order')
            ->groupBy('ckey')
            ->get();

        return $rows->map(fn ($r) => [
            'customer' => $r->email,
            'subscription_id' => null,
            'detail' => $r->orders.' one-time order'.($r->orders > 1 ? 's' : ''),
            'cycle' => null,
            'next_at' => $r->last_order,
            'payments' => (int) $r->orders,
            'value' => round((float) $r->spend, 2),
        ])->all();
    }

    /** Cancelled one subscription, still holds another. Cross-sell, not save. */
    private function partialChurnAudience(): array
    {
        $spend = $this->completedSpendPerSubscription();
        $live = array_flip($this->customerKeysWithLiveSubscription());
        $seen = [];
        $out = [];

        $rows = $this->subscriptions()
            ->whereIn('status', self::TERMINAL)
            ->whereNotNull('billing_email')
            ->select('id', 'billing_email', 'customer_id', 'ended_at')
            ->orderByDesc('ended_at')
            ->get();

        foreach ($rows as $r) {
            $key = $this->customerKey($r->billing_email, (int) ($r->customer_id ?? 0));

            if ($key === null || ! isset($live[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'customer' => $r->billing_email,
                'subscription_id' => (int) $r->id,
                'detail' => 'cancelled #'.$r->id.', still subscribed',
                'cycle' => null,
                'next_at' => $r->ended_at,
                'payments' => (int) ($spend[(int) $r->id]['n'] ?? 0),
                'value' => round((float) ($spend[(int) $r->id]['v'] ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Completed order count and spend per subscription.
     *
     * @return array<int, array{n:int, v:float}>
     */
    private function completedSpendPerSubscription(): array
    {
        $out = [];

        foreach (DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as n, SUM(total_amount) as v')
            ->groupBy('subscription_id')
            ->get() as $r) {
            $out[(int) $r->subscription_id] = ['n' => (int) $r->n, 'v' => (float) $r->v];
        }

        return $out;
    }

    /** @return array<int, string> customer keys holding a non-terminal subscription */
    private function customerKeysWithLiveSubscription(): array
    {
        $keys = [];

        foreach ($this->subscriptions()
            ->whereNotIn('status', self::TERMINAL)
            ->select('billing_email', 'customer_id')
            ->get() as $r) {
            $key = $this->customerKey($r->billing_email, (int) ($r->customer_id ?? 0));

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /** "every 6 weeks", "monthly" — a cycle a person can read. */
    private function cycleLabel(?string $period, ?int $interval): ?string
    {
        if ($period === null) {
            return null;
        }

        $n = max(1, (int) $interval);

        if ($n === 1) {
            return $period === 'month' ? 'monthly' : 'every '.$period;
        }

        return 'every '.$n.' '.$period.'s';
    }

    /**
     * Live subscriptions whose next payment is due inside the window ahead.
     *
     * `next_payment_at` is scheduled by WooCommerce, so this is the actual
     * renewal pipeline rather than an inference. Subscribers who have paid only
     * once are flagged: half of all payers never reach a second payment, which
     * makes that renewal the one worth intervening on.
     *
     * @param  int  $days  how far ahead to look
     * @return array{total:int, at_first_renewal:int, value:float, rows:array<int, array<string, mixed>>}
     */
    public function upcomingRenewals(int $days = 14, ?int $limit = 200): array
    {
        $now = CarbonImmutable::parse((string) DB::table('records')->max('date_created_gmt'));

        $paid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as payments, MAX(total_amount) as amount')
            ->groupBy('subscription_id');

        $query = DB::table('records as s')
            ->leftJoinSub($paid, 'p', 'p.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->whereNotIn('s.status', self::TERMINAL)
            ->whereNotNull('s.next_payment_at')
            ->where('s.next_payment_at', '>=', $now->toDateTimeString())
            ->where('s.next_payment_at', '<', $now->addDays(max(1, $days))->toDateTimeString())
            ->selectRaw('s.id, s.billing_email, s.customer_id, s.status, s.next_payment_at')
            ->selectRaw('s.utm_source, s.billing_period, s.billing_interval')
            ->selectRaw('COALESCE(p.payments, 0) as payments, COALESCE(p.amount, 0) as amount')
            ->orderBy('s.next_payment_at');

        $all = $query->get();

        $rows = [];
        foreach ($all as $r) {
            $payments = (int) $r->payments;

            $rows[] = [
                'id' => (int) $r->id,
                'customer' => $r->billing_email ?: ($r->customer_id > 0 ? 'Customer #'.$r->customer_id : null),
                'status' => (string) $r->status,
                'due_at' => (string) $r->next_payment_at,
                'days_away' => (int) max(0, floor($now->diffInDays(CarbonImmutable::parse((string) $r->next_payment_at)))),
                'payments_so_far' => $payments,
                'amount' => round((float) $r->amount, 2),
                'source' => $r->utm_source,
                'cycle_days' => $this->cycleDays($r->billing_period, $r->billing_interval === null ? null : (int) $r->billing_interval),
                // The renewal that decides whether they become a real customer.
                'first_renewal' => $payments === 1,
            ];
        }

        $firsts = array_filter($rows, fn ($r) => $r['first_renewal']);

        return [
            'total' => count($rows),
            'at_first_renewal' => count($firsts),
            'value' => round(array_sum(array_column($rows, 'amount')), 2),
            'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
        ];
    }

    /**
     * Lifetime value by sign-up month: what each intake has actually earned.
     *
     * The churn rate says how fast subscribers leave; this says whether they
     * paid for themselves before they did. Read against acquisition cost, it is
     * the metric that decides whether a 30%-a-month churn rate is survivable.
     *
     * Value is lifetime completed spend across every order linked to the
     * subscription, so a cohort keeps earning after the month closes -- recent
     * rows are necessarily immature and are marked as such.
     *
     * @return array{rows: array<int, array<string, mixed>>}
     */
    public function cohortValue(int $maxCohorts = 12): array
    {
        $spend = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, SUM(total_amount) as spend')
            ->groupBy('subscription_id');

        $subs = $this->subscriptionLifecycle()
            ->leftJoinSub($spend, 'sp', 'sp.subscription_id', '=', 's.id')
            ->whereNotNull('s.date_created_gmt')
            ->selectRaw('s.id, s.status, s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended, sp.spend')
            ->get();

        $cohorts = [];

        foreach ($subs as $r) {
            $month = substr((string) $r->created, 0, 7);
            $terminal = in_array((string) $r->status, self::TERMINAL, true);

            $cohorts[$month] ??= ['size' => 0, 'active' => 0, 'churned' => 0, 'spends' => [], 'tenures' => []];
            $cohorts[$month]['size']++;
            $cohorts[$month]['spends'][] = (float) ($r->spend ?? 0);

            if ((string) $r->status === 'active') {
                $cohorts[$month]['active']++;
            }

            if ($terminal && $r->ended !== null) {
                $cohorts[$month]['churned']++;
                $cohorts[$month]['tenures'][] = (int) max(0, floor(
                    CarbonImmutable::parse((string) $r->created)
                        ->diffInDays(CarbonImmutable::parse((string) $r->ended))
                ));
            }
        }

        krsort($cohorts);
        $cohorts = array_slice($cohorts, 0, $maxCohorts, true);

        // A cohort younger than this has not had time to show its true value.
        $latest = (string) DB::table('records')->max('date_created_gmt');
        $cutoff = CarbonImmutable::parse($latest !== '' ? $latest : 'now')->subMonths(3)->format('Y-m');

        $rows = [];

        foreach ($cohorts as $month => $c) {
            $total = array_sum($c['spends']);

            $rows[] = [
                'cohort' => $month,
                'size' => $c['size'],
                'still_active' => $c['active'],
                'retained_pct' => $c['size'] > 0 ? round($c['active'] / $c['size'] * 100, 1) : 0.0,
                'churned' => $c['churned'],
                'total_spend' => round($total, 2),
                'value_per_subscriber' => $c['size'] > 0 ? round($total / $c['size'], 2) : 0.0,
                'median_spend' => $this->medianFloat($c['spends']),
                'median_tenure_days' => $this->median($c['tenures']),
                // Too young to judge: still accruing revenue.
                'immature' => $month > $cutoff,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Median of a float list, averaging the two middles on an even count.
     *
     * @param  array<int, float>  $values
     */
    private function medianFloat(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return round(count($values) % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2, 2);
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

    /**
     * Orders created inside the window, excluding anything that is not business
     * data.
     *
     * `trash` is a deleted order. Because "not completed" was defined as
     * `status != 'completed'`, 26 deleted orders worth GBP 3,099 were being
     * reported as failed business. Exclusion happens here so no caller can
     * forget it; {@see unrecognisedStatuses()} surfaces what was dropped.
     */
    private function ordersInWindow(string $start, string $end): Builder
    {
        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotIn('status', Record::EXCLUDED_ORDER_STATUSES)
            ->where('date_created_gmt', '>=', $start)
            ->where('date_created_gmt', '<', $end);
    }

    /**
     * Statuses in the window that this app does not model, with their counts.
     *
     * Surfaced rather than absorbed: a plugin can invent a status at any time,
     * and silently folding it into "not completed" is how deleted orders became
     * a retention problem.
     *
     * @return array<string, int>
     */
    private function unrecognisedStatuses(string $start, string $end): array
    {
        $known = array_merge(Record::ORDER_STATUSES, Record::EXCLUDED_ORDER_STATUSES);

        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotIn('status', $known)
            ->where('date_created_gmt', '>=', $start)
            ->where('date_created_gmt', '<', $end)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    /**
     * How many subscriptions were in $status at instant $end.
     *
     * Dispatches to the point-in-time counters for the statuses whose history we
     * can reconstruct ({@see activeAsOf()}, {@see endedAsOf()}); the remaining
     * live-only states fall back to reading the current status, which is the
     * best the source data supports.
     */
    private function snapshotCount(string $status, string $end): int
    {
        if ($status === 'active') {
            return $this->activeAsOf($end);
        }

        if (in_array($status, self::TERMINAL, true)) {
            return $this->endedAsOf($status, $end);
        }

        return $this->subscriptions()
            ->where('status', $status)
            ->where('date_created_gmt', '<', $end)
            ->count();
    }

    /**
     * Subscriptions + the date each one stopped being a subscriber.
     *
     * `ended_at` is only present when the export carried an end-date column, so
     * we fall back to the subscription's last linked order — the most recent
     * moment we can prove it was still live. Joined as a derived table rather
     * than a correlated subquery so the aggregate is computed once.
     */
    private function subscriptionLifecycle(): Builder
    {
        $lastOrder = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->selectRaw('subscription_id, MAX(date_created_gmt) as last_order_at')
            ->groupBy('subscription_id');

        return DB::table('records as s')
            ->leftJoinSub($lastOrder, 'lo', 'lo.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription');
    }

    /** The end-of-life instant for a subscription; NULL while it is still running. */
    private function effectiveEndExpr(): string
    {
        return 'COALESCE(s.ended_at, lo.last_order_at)';
    }

    /**
     * Subscribers who were active at instant $end — including everyone who has
     * cancelled since. This is what keeps a past month's number from shrinking.
     */
    private function activeAsOf(string $end): int
    {
        return $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->where('s.date_created_gmt', '<', $end)
            ->where(function (Builder $q) use ($end) {
                $q->where('s.status', 'active')
                    ->orWhere(function (Builder $terminal) use ($end) {
                        // NULL end date => we cannot prove it was still live, so
                        // the comparison is NULL and the row drops out.
                        $terminal->whereIn('s.status', self::TERMINAL)
                            ->whereRaw($this->effectiveEndExpr().' >= ?', [$end]);
                    });
            })
            ->count();
    }

    /** Subscriptions that had already left in $status by instant $end. */
    private function endedAsOf(string $status, string $end): int
    {
        return $this->subscriptionLifecycle()
            ->where('s.status', $status)
            ->where('s.date_created_gmt', '<', $end)
            ->where(function (Builder $q) use ($end) {
                // No end date known: fall back to counting it, so the terminal
                // statuses still add up to the same lifetime totals as before.
                $q->whereRaw($this->effectiveEndExpr().' < ?', [$end])
                    ->orWhereRaw($this->effectiveEndExpr().' IS NULL');
            })
            ->count();
    }

    /**
     * Leavers in [start, end) that never completed a single order.
     *
     * A funnel defect rather than churn: the subscription was created, never
     * billed successfully, and died. Counting these as churned customers
     * overstates the rate and hides a checkout problem as a retention one.
     */
    private function failedSignupsBetween(string $start, string $end): int
    {
        return $this->subscriptionLifecycle()
            ->whereIn('s.status', self::TERMINAL)
            ->whereRaw($this->effectiveEndExpr().' >= ?', [$start])
            ->whereRaw($this->effectiveEndExpr().' < ?', [$end])
            ->whereNotExists(function (Builder $q) {
                $q->from('records as paid')
                    ->whereColumn('paid.subscription_id', 's.id')
                    ->where('paid.record_type', 'shop_order')
                    ->where('paid.status', 'completed')
                    ->selectRaw('1');
            })
            ->count();
    }

    /**
     * `on-hold` subscriptions with no completed order for
     * {@see ON_HOLD_DORMANT_DAYS}, as of $end.
     *
     * These have stopped paying but will never appear in churn, because churn
     * only counts terminal statuses and nothing promotes a stalled on-hold to
     * one. Surfaced so the gap is visible instead of silently understating
     * attrition.
     */
    private function dormantOnHold(string $end): int
    {
        $at = CarbonImmutable::parse($end);

        $lastPaid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->where('date_created_gmt', '<', $end)
            ->selectRaw('subscription_id, MAX(date_created_gmt) as last_paid_at')
            ->groupBy('subscription_id');

        $rows = DB::table('records as s')
            ->leftJoinSub($lastPaid, 'lp', 'lp.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->where('s.status', 'on-hold')
            ->where('s.date_created_gmt', '<', $end)
            ->selectRaw('s.billing_period, s.billing_interval, s.date_created_gmt, lp.last_paid_at')
            ->get();

        $dormant = 0;

        foreach ($rows as $row) {
            // Never billed: measured from sign-up, since that is when the first
            // payment was due.
            $since = CarbonImmutable::parse((string) ($row->last_paid_at ?? $row->date_created_gmt));
            $allowed = $this->cycleDays($row->billing_period, $row->billing_interval) * self::ON_HOLD_DORMANT_CYCLES;

            if ($since->diffInDays($at) > $allowed) {
                $dormant++;
            }
        }

        return $dormant;
    }

    /** One billing cycle in days; the default when the row carries no cycle. */
    private function cycleDays(?string $period, ?int $interval): int
    {
        $days = Record::PERIOD_DAYS[strtolower((string) $period)] ?? null;

        if ($days === null) {
            return self::DEFAULT_CYCLE_DAYS;
        }

        return $days * max(1, (int) $interval);
    }

    /**
     * Gross, net, tax, shipping and refunds for completed orders in the window.
     *
     * `net_amount` only exists on files exported with 04-export-with-net-revenue
     * or later, so `known` reports whether the split is real or absent. Callers
     * must not silently present gross as net.
     *
     * @return array{net:?float, tax:?float, shipping:?float, refunded:float, net_after_refunds:?float, known:bool}
     */
    private function revenueBreakdown(string $start, string $end): array
    {
        $row = $this->ordersInWindow($start, $end)
            ->where('status', 'completed')
            ->selectRaw('SUM(net_amount) as net, SUM(tax_amount) as tax, SUM(shipping_amount) as shipping')
            ->selectRaw('SUM(refunded_amount) as refunded, COUNT(net_amount) as with_net, COUNT(*) as n')
            ->first();

        $known = (int) ($row->with_net ?? 0) > 0;
        $net = $known ? round((float) $row->net, 2) : null;
        $refunded = round((float) ($row->refunded ?? 0), 2);

        return [
            'net' => $net,
            'tax' => $known ? round((float) $row->tax, 2) : null,
            'shipping' => $known ? round((float) $row->shipping, 2) : null,
            'refunded' => $refunded,
            'net_after_refunds' => $net === null ? null : round($net - $refunded, 2),
            'known' => $known,
        ];
    }

    /** Configured product gross margin, or null while nobody has supplied it. */
    private function grossMarginPct(): ?float
    {
        $pct = config('metrics.gross_margin_pct');

        return $pct === null ? null : (float) $pct;
    }

    /** Contribution after cost of goods; null until the margin is configured. */
    private function contributionFrom(?float $net): ?float
    {
        $pct = $this->grossMarginPct();

        return $net === null || $pct === null ? null : round($net * $pct / 100, 2);
    }

    /**
     * Customers, deduplicated on billing email.
     *
     * `unique_customers` counts distinct `customer_id`, which every guest
     * shares as 0 and which one person can hold several of. The email is the
     * better key when it exists.
     */
    private function uniqueCustomersDeduped(string $start, string $end): int
    {
        $key = $this->customerKeyExpr();

        return (int) $this->ordersInWindow($start, $end)
            ->selectRaw("COUNT(DISTINCT {$key}) as n")
            ->value('n');
    }

    /**
     * Churn measured on customers rather than subscriptions.
     *
     * A customer counts as active while ANY of their subscriptions is active,
     * and has churned only once every one has ended. On this data 172 of 639
     * cancellations belong to people who still hold a live subscription — plan
     * changes and consolidations that the subscription-level rate reports as
     * losses.
     *
     * @return array{active_start:int, churned:int, rate:?float}
     */
    private function customerChurn(string $start, string $end): array
    {
        $atStart = $this->activeCustomerKeys($start);

        if ($atStart === []) {
            return ['active_start' => 0, 'churned' => 0, 'rate' => null];
        }

        $atEnd = array_flip($this->activeCustomerKeys($end));
        $churned = 0;

        foreach ($atStart as $key) {
            if (! isset($atEnd[$key])) {
                $churned++;
            }
        }

        return [
            'active_start' => count($atStart),
            'churned' => $churned,
            'rate' => round($churned / count($atStart) * 100, 1),
        ];
    }

    /**
     * Customer keys holding at least one subscription that was live at $instant.
     *
     * @return array<int, string>
     */
    private function activeCustomerKeys(string $instant): array
    {
        $key = $this->customerKeyExpr();

        return $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->where('s.date_created_gmt', '<', $instant)
            ->where(function (Builder $q) use ($instant) {
                $q->where('s.status', 'active')
                    ->orWhere(function (Builder $t) use ($instant) {
                        $t->whereIn('s.status', self::TERMINAL)
                            ->whereRaw($this->effectiveEndExpr().' >= ?', [$instant]);
                    });
            })
            ->selectRaw(str_replace('billing_email', 's.billing_email', str_replace('customer_id', 's.customer_id', $key)).' as ckey')
            ->distinct()
            ->pluck('ckey')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Monthly recurring revenue as of $end.
     *
     * Each live subscription is priced at its most recent completed payment and
     * normalised to 30 days by its own billing cycle -- a two-monthly plan at
     * GBP 40 is GBP 20 of MRR, not GBP 40. The cycle matters: this book is only
     * about 73% monthly.
     *
     * Gross, like every other money figure here: it includes VAT and shipping,
     * because that is what `total_amount` carries.
     *
     * @return array{mrr:float, arr:float, arpu:?float}
     */
    private function recurringRevenue(string $end): array
    {
        $subs = DB::table('records')
            ->where('record_type', 'shop_subscription')
            ->where('status', 'active')
            ->where('date_created_gmt', '<', $end)
            ->select('id', 'billing_period', 'billing_interval')
            ->get();

        if ($subs->isEmpty()) {
            return ['mrr' => 0.0, 'arr' => 0.0, 'arpu' => null];
        }

        $price = $this->lastPaymentBefore($subs->pluck('id')->all(), $end);

        $mrr = 0.0;
        $paying = 0;

        foreach ($subs as $sub) {
            $amount = (float) ($price[(int) $sub->id] ?? 0.0);

            if ($amount <= 0.0) {
                continue; // never billed, so nothing recurring yet
            }

            $paying++;
            $mrr += $amount * (30 / $this->cycleDays($sub->billing_period, $sub->billing_interval === null ? null : (int) $sub->billing_interval));
        }

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'arpu' => $paying > 0 ? round($mrr / $paying, 2) : null,
        ];
    }

    /**
     * Revenue retention across the window, measured on the subscribers who were
     * already there when it opened.
     *
     * Each one is priced at its most recent completed order before the instant
     * in question -- what it was billing per cycle. NRR compares the closing
     * total to the opening one, so upgrades can carry it above 100%; GRR caps
     * every subscriber at its starting price, so it only ever measures loss.
     * New sign-ups are excluded from both: this asks whether the existing book
     * held its value, not whether acquisition replaced it.
     *
     * @return array{nrr:?float, grr:?float, start:float, retained:float}
     */
    private function revenueRetention(string $start, string $end): array
    {
        $openers = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->where('s.date_created_gmt', '<', $start)
            ->where(function (Builder $q) use ($start) {
                $q->where('s.status', 'active')
                    ->orWhere(function (Builder $t) use ($start) {
                        $t->whereIn('s.status', self::TERMINAL)
                            ->whereRaw($this->effectiveEndExpr().' >= ?', [$start]);
                    });
            })
            ->selectRaw('s.id, s.status, '.$this->effectiveEndExpr().' as ended')
            ->get();

        if ($openers->isEmpty()) {
            return ['nrr' => null, 'grr' => null, 'start' => 0.0, 'retained' => 0.0];
        }

        $ids = $openers->pluck('id')->all();
        $priceAtStart = $this->lastPaymentBefore($ids, $start);
        $priceAtEnd = $this->lastPaymentBefore($ids, $end);

        $base = 0.0;
        $retained = 0.0;
        $capped = 0.0;

        foreach ($openers as $o) {
            $was = (float) ($priceAtStart[(int) $o->id] ?? 0.0);

            if ($was <= 0.0) {
                continue; // nothing to retain
            }

            // An end date only means anything for a terminal subscription --
            // for a live one effectiveEndExpr() returns its last ORDER date,
            // which would read as though every active subscriber had left.
            $isTerminal = in_array((string) $o->status, self::TERMINAL, true);
            $stillRunning = ! $isTerminal || $o->ended === null || (string) $o->ended >= $end;
            $now = $stillRunning ? (float) ($priceAtEnd[(int) $o->id] ?? 0.0) : 0.0;

            $base += $was;
            $retained += $now;
            $capped += min($now, $was);
        }

        return [
            'nrr' => $base > 0 ? round($retained / $base * 100, 1) : null,
            'grr' => $base > 0 ? round($capped / $base * 100, 1) : null,
            'start' => round($base, 2),
            'retained' => round($retained, 2),
        ];
    }

    /**
     * Each subscription's most recent completed order amount strictly before
     * $instant, keyed by subscription id.
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function lastPaymentBefore(array $ids, string $instant): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $orders = DB::table('records')
                ->where('record_type', 'shop_order')
                ->where('status', 'completed')
                ->whereIn('subscription_id', $chunk)
                ->whereNotNull('date_created_gmt')
                ->where('date_created_gmt', '<', $instant)
                ->orderBy('date_created_gmt')
                ->select('subscription_id', 'total_amount')
                ->get();

            // Ascending, so the last write per subscription wins.
            foreach ($orders as $o) {
                $out[(int) $o->subscription_id] = (float) $o->total_amount;
            }
        }

        return $out;
    }

    /** Subscribers who left during [start, end) — the numerator of churn. */
    private function churnedBetween(string $start, string $end): int
    {
        return $this->subscriptionLifecycle()
            ->whereIn('s.status', self::TERMINAL)
            ->whereRaw($this->effectiveEndExpr().' >= ?', [$start])
            ->whereRaw($this->effectiveEndExpr().' < ?', [$end])
            ->count();
    }

    /**
     * The subscriptions behind {@see churnedBetween()} — the actual rows the
     * churn number counts, so it can be checked against WooCommerce.
     *
     * Same predicate as the count, so the list and the headline can never
     * disagree. `joined_in_period` flags the ones that signed up and left
     * inside the same window: they are part of the churn numerator but were
     * never in the opening base it is divided by, which is the single most
     * confusing thing about the rate.
     *
     * @param  int|null  $limit  row cap for the UI; null returns everything (export)
     * @return array{total:int, returned:int, rows:array<int, array<string, mixed>>}
     */
    public function churnedSubscriptions(string $start, string $end, ?int $limit = null): array
    {
        $orders = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as orders')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as spend")
            ->groupBy('subscription_id');

        $base = $this->subscriptionLifecycle()
            ->leftJoinSub($orders, 'agg', 'agg.subscription_id', '=', 's.id')
            ->whereNotNull('s.date_created_gmt')
            ->whereIn('s.status', self::TERMINAL)
            ->whereRaw($this->effectiveEndExpr().' >= ?', [$start])
            ->whereRaw($this->effectiveEndExpr().' < ?', [$end]);

        // Annotated over the WHOLE set, then sliced: a win-back rate computed
        // from a truncated page would describe the page, not the period.
        $all = (clone $base)
            ->selectRaw('s.id, s.status, s.billing_email, s.customer_id')
            ->selectRaw('s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended')
            ->selectRaw('agg.orders, agg.completed_orders, agg.spend')
            ->orderByRaw($this->effectiveEndExpr().' asc')
            ->get();

        $index = $this->subscriptionsByCustomer();
        $lastPayment = $this->lastPaymentPerSubscription($all->pluck('id')->all());

        $rows = [];
        foreach ($all as $r) {
            $created = CarbonImmutable::parse((string) $r->created);
            $ended = CarbonImmutable::parse((string) $r->ended);
            $completed = (int) ($r->completed_orders ?? 0);

            $rows[] = [
                'id' => (int) $r->id,
                'status' => (string) $r->status,
                'customer' => $r->billing_email ?: ($r->customer_id > 0 ? 'Customer #'.$r->customer_id : null),
                'created' => $created->toDateTimeString(),
                'ended' => $ended->toDateTimeString(),
                'tenure_days' => (int) max(0, floor($created->diffInDays($ended))),
                'orders' => (int) ($r->orders ?? 0),
                'completed_orders' => $completed,
                'spend' => round((float) ($r->spend ?? 0), 2),
                // What they were paying per cycle when they left - the closest
                // this data gets to the recurring revenue the churn costs.
                'last_payment' => round((float) ($lastPayment[(int) $r->id] ?? 0), 2),
                // Signed up inside the same window: counted as churn, but never
                // part of the base the rate divides by.
                'joined_in_period' => $r->created >= $start,
                'had_purchase' => $completed > 0,
            ] + $this->returnAfter($r, $index);
        }

        return [
            'total' => count($rows),
            'returned' => $limit === null ? count($rows) : min($limit, count($rows)),
            'summary' => $this->winBackSummary($rows),
            'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
        ];
    }

    /**
     * Did this customer come back after this subscription ended?
     *
     * "Came back" is their next subscription starting on or after the end date.
     * One that started while this was still running is a second concurrent plan,
     * not a return, so the cutoff is the end date rather than the sign-up date.
     * A same-day restart is almost always a plan switch and is flagged
     * separately -- reading those as churn-then-win-back overstates both.
     *
     * @param  array<string, array<int, array{id:int, created:string, status:string}>>  $index
     * @return array<string, mixed>
     */
    private function returnAfter(object $row, array $index): array
    {
        $none = [
            'returned' => false,
            'returned_subscription_id' => null,
            'returned_at' => null,
            'days_to_return' => null,
            'returned_status' => null,
            'same_day_switch' => false,
        ];

        $key = $this->customerKey($row->billing_email ?? null, (int) ($row->customer_id ?? 0));

        // No email and no customer id: we cannot tell whether they came back,
        // which is not the same as knowing they did not.
        if ($key === null) {
            return ['returned' => null] + $none;
        }

        $ended = (string) $row->ended;

        foreach ($index[$key] ?? [] as $candidate) {
            if ($candidate['id'] === (int) $row->id || $candidate['created'] < $ended) {
                continue;
            }

            $endedAt = CarbonImmutable::parse($ended);
            $back = CarbonImmutable::parse($candidate['created']);

            return [
                'returned' => true,
                'returned_subscription_id' => $candidate['id'],
                'returned_at' => $candidate['created'],
                'days_to_return' => (int) max(0, floor($endedAt->diffInDays($back))),
                'returned_status' => $candidate['status'],
                'same_day_switch' => $back->isSameDay($endedAt),
            ];
        }

        return $none;
    }

    /**
     * Win-back totals for a churned set. Customers we cannot identify are held
     * out of the rate rather than counted as "did not return".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function winBackSummary(array $rows): array
    {
        $known = array_filter($rows, fn ($r) => $r['returned'] !== null);
        $back = array_filter($known, fn ($r) => $r['returned'] === true);
        $gaps = array_values(array_filter(
            array_column($back, 'days_to_return'),
            fn ($d) => $d !== null,
        ));

        // Only a win-back that is still running has actually been recovered; one
        // that came back and cancelled again is revenue lost twice over.
        $stillActive = array_filter($back, fn ($r) => $r['returned_status'] === 'active');

        return [
            'identifiable' => count($known),
            'unidentifiable' => count($rows) - count($known),
            'resubscribed' => count($back),
            'same_day_switches' => count(array_filter($back, fn ($r) => $r['same_day_switch'])),
            'still_active' => count($stillActive),
            'rate' => count($known) > 0 ? round(count($back) / count($known) * 100, 1) : null,
            'median_days_to_return' => $this->median($gaps),
            'recurring_lost' => round(array_sum(array_column($rows, 'last_payment')), 2),
            'recurring_recovered' => round(array_sum(array_column($stillActive, 'last_payment')), 2),
            'lifetime_spend_lost' => round(array_sum(array_column($rows, 'spend')), 2),
        ];
    }

    /**
     * The amount of each subscription's most recent completed order.
     *
     * Read as "what this customer was paying per cycle": the renewal price at
     * the point they left. Not a true MRR - the data carries no billing period -
     * but it prices a lost subscriber far better than a headcount does.
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function lastPaymentPerSubscription(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        // Ascending, so the last write per subscription is the newest order.
        $orders = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereIn('subscription_id', $ids)
            ->whereNotNull('date_created_gmt')
            ->orderBy('date_created_gmt')
            ->select('subscription_id', 'total_amount')
            ->get();

        foreach ($orders as $o) {
            $out[(int) $o->subscription_id] = (float) $o->total_amount;
        }

        return $out;
    }

    /**
     * Every subscription grouped by customer, oldest first, for win-back lookups.
     *
     * @return array<string, array<int, array{id:int, created:string, status:string}>>
     */
    private function subscriptionsByCustomer(): array
    {
        $index = [];

        $rows = $this->subscriptions()
            ->whereNotNull('date_created_gmt')
            ->select('id', 'billing_email', 'customer_id', 'date_created_gmt', 'status')
            ->orderBy('date_created_gmt')
            ->get();

        foreach ($rows as $r) {
            $key = $this->customerKey($r->billing_email, (int) ($r->customer_id ?? 0));

            if ($key === null) {
                continue;
            }

            $index[$key][] = [
                'id' => (int) $r->id,
                'created' => (string) $r->date_created_gmt,
                'status' => (string) $r->status,
            ];
        }

        return $index;
    }

    /** PHP twin of {@see customerKeyExpr()}, for in-memory grouping. */
    private function customerKey(?string $email, int $customerId): ?string
    {
        $email = trim((string) $email);

        if ($email !== '') {
            return strtolower($email);
        }

        return $customerId > 0 ? 'cid:'.$customerId : null;
    }

    /**
     * How long the subscribers who left during [start, end) had been subscribed
     * — the same population {@see churnedBetween()} counts, split by tenure.
     *
     * The churn rate says how many left; this says whether they were customers
     * who never settled in or long-standing ones drifting away, which are
     * different problems. Tenure is sign-up to {@see effectiveEndExpr()}, so it
     * inherits that method's accuracy: exact with a real `ended_at`, and up to
     * one billing cycle short when falling back to the last linked order.
     *
     * Bucketed in PHP rather than SQL because the date-difference function is
     * driver-specific, and the row count here is one month of churn.
     *
     * @return array{total:int, median_days:?int, buckets:array<int, array{label:string, count:int, pct:float}>}
     */
    public function tenureAtChurn(string $start, string $end): array
    {
        $rows = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->whereIn('s.status', self::TERMINAL)
            ->whereRaw($this->effectiveEndExpr().' >= ?', [$start])
            ->whereRaw($this->effectiveEndExpr().' < ?', [$end])
            ->selectRaw('s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended')
            ->get();

        $days = [];
        foreach ($rows as $row) {
            // diffInDays() is fractional, so floor to whole days elapsed: a
            // subscription in its 31st day belongs in the 31-60 bucket, not at
            // the top of 0-30. Clamped at 0 because a fallback end date can
            // predate the sign-up when a subscription has no orders of its own.
            $days[] = (int) max(0, floor(CarbonImmutable::parse((string) $row->created)
                ->diffInDays(CarbonImmutable::parse((string) $row->ended))));
        }

        $total = count($days);

        $counts = array_fill(0, count(self::TENURE_BUCKETS), 0);
        foreach ($days as $d) {
            foreach (self::TENURE_BUCKETS as $i => [$label, $max]) {
                if ($max === null || $d <= $max) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        $buckets = [];
        foreach (self::TENURE_BUCKETS as $i => [$label, $max]) {
            $buckets[] = [
                'label' => $label,
                'count' => $counts[$i],
                'pct' => $total > 0 ? round($counts[$i] / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'total' => $total,
            'median_days' => $this->median($days),
            'buckets' => $buckets,
        ];
    }

    /**
     * Middle value of a list, averaging the two middles on an even count.
     *
     * @param  array<int, int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * Share of ended subscriptions (as of $end) carrying a real imported
     * `ended_at` rather than the last-order approximation. Surfaced so the
     * dashboard can say how trustworthy the churn timing is.
     */
    private function endDateCoverage(string $end): ?float
    {
        $ended = $this->subscriptions()
            ->whereIn('status', self::TERMINAL)
            ->where('date_created_gmt', '<', $end);

        $total = (clone $ended)->count();

        if ($total === 0) {
            return null;
        }

        return round((clone $ended)->whereNotNull('ended_at')->count() / $total * 100, 1);
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

        // Step back three days, then forward to the next Thursday: that lands
        // on the Thursday of the row's own ISO week, whatever weekday it is.
        $thursday = "date({$col}, '-3 days', 'weekday 4')";

        return match ($granularity) {
            'year' => $driver === 'sqlite'
                ? "strftime('%Y', {$col})"
                : "DATE_FORMAT({$col}, '%Y')",
            // ISO-8601, to match MySQL's %x-%v: a week's Thursday decides both
            // its number and the year it belongs to, which is what puts an
            // early 1 January in the last week of the year before.
            'week' => $driver === 'sqlite'
                ? "strftime('%Y', {$thursday}) || '-W' || printf('%02d', (strftime('%j', {$thursday}) - 1) / 7 + 1)"
                : "DATE_FORMAT({$col}, '%x-W%v')",
            default => $driver === 'sqlite' // month (and custom fallback)
                ? "strftime('%Y-%m', {$col})"
                : "DATE_FORMAT({$col}, '%Y-%m')",
        };
    }

    /**
     * The label of the bucket the data stops inside, for a given granularity.
     *
     * Every chart needs to know which of its points is not finished yet, and
     * the answer has to be derived the way {@see bucketExpression()} derives
     * its labels -- ISO weeks included -- or the comparison silently never
     * matches and the marking quietly does nothing.
     */
    public function partialBucket(string $granularity): string
    {
        $latest = $this->latestDataInstant();

        return match ($granularity) {
            'year' => $latest->format('Y'),
            'week' => $latest->format('o-\WW'),
            default => $latest->format('Y-m'),
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
