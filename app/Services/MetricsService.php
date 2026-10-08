<?php

namespace App\Services;

use App\Models\Record;
use App\Support\Clock;
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
 * `on-hold` carries no date either, but reading it as-is pulls everyone who
 * pauses this month out of every earlier month. Its start is estimated instead
 * ({@see holdStarts()}): until then the subscription counts as active, after
 * it as on hold.
 *
 * `pending` and `pending-cancel` are live states with no history in the
 * source data, so they are still read as-is and keep their own cards. A
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

    private DataFreshness $freshness;

    /** @var array{0:string, 1:string}|null [now, asOf] memo; see asOfGmt() */
    private ?array $asOfMemo = null;

    public function __construct(?DataFreshness $freshness = null)
    {
        $this->freshness = $freshness ?? new DataFreshness;
    }

    /**
     * {@see DataFreshness::asOf()} as a GMT string: the latest instant any
     * churn can have happened by. An end date after it is a schedule, not a
     * departure. Memoised for as long as the clock does not move.
     */
    private function asOfGmt(): string
    {
        $now = Clock::now()->toDateTimeString();

        if ($this->asOfMemo === null || $this->asOfMemo[0] !== $now) {
            $this->asOfMemo = [$now, Clock::gmt($this->freshness->asOf())];
        }

        return $this->asOfMemo[1];
    }

    /** The earlier of a GMT bound and {@see asOfGmt()}. */
    private function capAtAsOf(string $gmt): string
    {
        return min($gmt, $this->asOfGmt());
    }

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
        // A period that runs past the data is measured up to the data, not
        // beyond it: a subscription's status after the last import is unknown,
        // and treating the export as still true on the 31st labels the book
        // with a date that has not happened.
        $asOf = $this->freshness->asOf();
        $capped = $end->gt($asOf);

        if ($capped) {
            $end = $asOf;
        }

        if ($start->gt($end)) {
            $start = $end;
        }

        $startS = Clock::gmt($start);
        $endS = Clock::gmt($end);

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

        // Monthly churn: who left DURING the window, over everyone who could
        // have left -- the subscribers active when it opened PLUS the ones who
        // joined during it. Dividing by the opening base alone counted
        // same-month joiners who cancelled in the numerator but never in the
        // denominator, which is how October 2025 reported 165.5%.
        $activeAtStart = $this->activeAsOf($startS);
        $newInPeriod = $this->subscriptions()
            ->where('date_created_gmt', '>=', $startS)
            ->where('date_created_gmt', '<', $endS)
            ->count();
        $churnBase = $activeAtStart + $newInPeriod;
        $churnedInPeriod = $this->churnedBetween($startS, $endS);
        $pendingCancelStatus = $this->snapshotCount('pending-cancel', $endS);
        $scheduledCancellations = $this->scheduledCancellations($endS);

        // Subscriptions that ended having never completed an order are a broken
        // checkout, not a lost customer: nothing was ever earned to lose. They
        // are reported separately and netted out of the adjusted rate below.
        $failedSignups = $this->failedSignupsBetween($startS, $endS);
        $recurring = $this->recurringRevenue($endS);
        $realChurn = max(0, $churnedInPeriod - $failedSignups);
        $revenue = $this->revenueRetention($startS, $endS);

        $renewalTotal = $orderStats->relationship('renewal');
        $renewalCompleted = $orderStats->count('completed', 'renewal');

        // On hold with no successful payment for 1.5 cycles: stopped paying,
        // but churn only counts cancelled/expired, so they never show up in
        // it. Each one is counted in the period it crossed the line -- once,
        // not again every month it stays dormant.
        $dormant = $this->dormantOnHoldSubscriptions($endS);
        $dormantCrossed = count(array_filter(
            $dormant,
            fn (array $d) => $d['dormant_from'] >= $startS && $d['dormant_from'] < $endS,
        ));
        $adjustedChurn = $churnedInPeriod + $dormantCrossed;

        // What is at risk: every dormant subscription's monthly value, plus
        // the renewal orders in the period that failed or are still unpaid.
        $failedRenewalValue = $orderStats->amount('failed', 'renewal') + $orderStats->amount('pending', 'renewal');
        $dormantValue = array_sum(array_column($dormant, 'monthly_value'));
        $revenueAtRisk = $dormantValue + $failedRenewalValue;

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
            // The instant every snapshot figure below is measured at, for the
            // "as of" label. Capped at the data, so it is never in the future.
            'snapshot_at' => Clock::toLocal($endS)->toDateTimeString(),
            'snapshot_capped' => $capped,

            // ----- Subscriptions -----
            'new_subscribers' => $newInPeriod,
            'subscribers_active' => $active,
            // Pending cancellation = WooCommerce's pending-cancel status, plus
            // cancelled/expired rows whose end date has not happened yet.
            'pending_cancellation' => $pendingCancelStatus + $scheduledCancellations,
            'pending_cancellation_status' => $pendingCancelStatus,
            'scheduled_cancellations' => $scheduledCancellations,
            'on_hold' => $this->snapshotCount('on-hold', $endS),
            // WooCommerce's `pending` subscription status: created, first
            // payment not taken yet. Not the same thing as pending cancellation.
            'awaiting_payment' => $statusBreakdownSub['pending'] ?? 0,
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
            // churned ÷ (active at start + new in period)
            'monthly_churn_rate' => $this->churnRate($churnedInPeriod, $churnBase),
            'churned_in_period' => $churnedInPeriod,
            'failed_signups' => $failedSignups,
            'churned_net_of_failed' => $realChurn,
            // The rate with never-activated subscriptions taken out. Shown
            // alongside the gross rate rather than replacing it, so a month's
            // published figure never silently changes meaning.
            'monthly_churn_rate_net' => $this->churnRate($realChurn, $churnBase),
            'churn_base' => $churnBase,
            'on_hold_dormant' => count($dormant),
            'on_hold_dormant_in_period' => $dormantCrossed,
            // (cancelled + expired + newly dormant on-hold) ÷ churn base
            'churned_adjusted' => $adjustedChurn,
            'monthly_churn_rate_adjusted' => $this->churnRate($adjustedChurn, $churnBase),
            'mrr' => $recurring['mrr'],
            'arr' => $recurring['arr'],
            'arpu' => $recurring['arpu'],
            'paying_subscribers' => $recurring['paying'],
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
            'revenue_at_risk_dormant' => round($dormantValue, 2),
            'revenue_at_risk_failed_renewals' => round($failedRenewalValue, 2),

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
            ->whereIn('status', Record::ORDER_STATUSES)
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
            $query->where('date_created_gmt', '>=', Clock::gmt($window->start))
                ->where('date_created_gmt', '<', Clock::gmt($window->end));
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
        // Only real order statuses: trashed, draft and wrong-type rows (an
        // order carrying a subscription status) are not business data
        // anywhere else on the dashboard, so a trend and the card above it
        // count the same orders.
        $orders = fn (Builder $q) => $q->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES);
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
     * @return array{rows: array<int, array{month:string, active_start:int, new:int, churned:int, paused:int, active_end:int, churn_rate:?float, partial:bool}>, end_date_coverage: ?float}
     */
    public function churnSeries(int $months = self::CHURN_SERIES_MONTHS): array
    {
        $months = max(1, min(60, $months));

        $subs = $this->lifecycleSubs();

        if ($subs === []) {
            return ['rows' => [], 'end_date_coverage' => null];
        }

        $latest = $this->latestDataInstant();
        $lastMonth = $latest->startOfMonth();
        $firstMonth = $lastMonth->subMonths($months - 1);

        $rows = [];

        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->addMonth()) {
            $monthEnd = $cursor->addMonth();
            $startS = Clock::gmt($cursor);
            $endS = Clock::gmt($monthEnd);

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
                if ($this->endedBetween($sub, $startS, $endS)) {
                    $churned++;
                }
            }

            // Whatever moved the active count besides sign-ups and churn: going
            // on hold or pending cancellation, net of the ones that came back.
            // Not churn -- none of them has ended.
            $paused = $activeStart + $joined - $churned - $activeEnd;

            $rows[] = [
                'month' => $cursor->format('Y-m'),
                'active_start' => $activeStart,
                'new' => $joined,
                'churned' => $churned,
                'paused' => $paused,
                'active_end' => $activeEnd,
                'churn_rate' => $this->churnRate($churned, $activeStart + $joined),
                // The month the data stops inside is only counted up to the
                // last row imported. Charting it undashed draws a cliff that is
                // missing rows, not lost subscribers.
                'partial' => $latest->lt($monthEnd),
            ];
        }

        return [
            'rows' => $rows,
            'end_date_coverage' => $this->endDateCoverage(Clock::gmt($lastMonth->addMonth())),
        ];
    }

    /**
     * The PHP twin of {@see activeAsOf()}'s SQL predicate, for the in-memory
     * month walk. Kept next to it so the two definitions stay in step.
     *
     * @param  array{created:string, ended:?string, terminal:bool, active:bool, held:?string, timeline:array}  $sub
     */
    private function wasActiveAt(array $sub, string $instant): bool
    {
        if ($sub['created'] >= $instant) {
            return false;
        }

        $known = $this->statusFromTimeline($sub['timeline'], $instant);

        if ($known !== null) {
            return $known === 'active';
        }

        if ($sub['active']) {
            return true;
        }

        if ($sub['held'] !== null) {
            return $sub['held'] >= $instant;
        }

        // Ended later than $instant: still a subscriber then -- but only for
        // instants inside the data. Its status is terminal now, so at or past
        // the data timestamp it is not active, whatever date it is scheduled
        // to end on.
        return $sub['terminal'] && $sub['ended'] !== null && $sub['ended'] >= $instant
            && $instant < $this->asOfGmt();
    }

    /**
     * THE definition of "still active", for every page: the subscription's
     * status at $asOf was `active`.
     *
     * Overview, Acquisition and Cohorts each used to count it their own way
     * (a point-in-time snapshot, the live `status` column, and a 12-cohort
     * window), and showed 243, 239 and 241 for the same book. They all call
     * this now. Its SQL twin is {@see whereLiveAt()}; both read the imported
     * status history first and fall back to the export the same way.
     *
     * @param  array{created:string, ended:?string, terminal:bool, active:bool, held:?string, timeline:array}  $sub  a row from {@see lifecycleSubs()}
     */
    public function isActive(array $sub, string $asOf): bool
    {
        return $this->wasActiveAt($sub, $asOf);
    }

    /**
     * The whole subscription book, shaped for the in-memory walks: sign-up
     * and end dates, the estimated hold start and the imported status history.
     *
     * @param  array<int, string>  $extra  further `records` columns to carry along
     * @return array<int, array<string, mixed>> keyed by subscription id
     */
    private function lifecycleSubs(array $extra = []): array
    {
        $holds = $this->holdStarts();
        $timelines = $this->statusTimelines();

        $query = $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->selectRaw('s.id, s.status, s.date_created_gmt as created, '.$this->effectiveEndExpr().' as ended');

        foreach ($extra as $column) {
            $query->addSelect('s.'.$column);
        }

        $subs = [];

        foreach ($query->get() as $r) {
            $id = (int) $r->id;
            $sub = [
                'id' => $id,
                'status' => (string) $r->status,
                'created' => (string) $r->created,
                'ended' => $r->ended === null ? null : (string) $r->ended,
                'terminal' => in_array((string) $r->status, self::TERMINAL, true),
                'active' => (string) $r->status === 'active',
                'held' => $holds[$id]['held'] ?? null,
                'timeline' => $timelines[$id] ?? [],
            ];

            foreach ($extra as $column) {
                $sub[$column] = $r->{$column};
            }

            $subs[$id] = $sub;
        }

        return $subs;
    }

    /**
     * Whether a subscription left in [start, end): a terminal status AND an
     * end date that has actually happened. A cancellation scheduled for next
     * month is not churn yet ({@see scheduledCancellations()}).
     *
     * @param  array{terminal:bool, ended:?string}  $sub
     */
    private function endedBetween(array $sub, string $start, string $end): bool
    {
        return $sub['terminal'] && $sub['ended'] !== null
            && $sub['ended'] >= $start && $sub['ended'] < $end
            && $sub['ended'] <= $this->asOfGmt();
    }

    /**
     * Whether a subscription was still billing at $instant: for revenue
     * retention, a hold stops the revenue even though it is not churn.
     *
     * @param  array{ended:?string, terminal:bool, held:?string, timeline:array}  $sub
     */
    private function stillBillingAt(array $sub, string $instant): bool
    {
        $known = $this->statusFromTimeline($sub['timeline'], $instant);

        if ($known !== null) {
            // Pending cancellation still runs to the end of its paid term.
            return in_array($known, ['active', 'pending-cancel'], true);
        }

        if ($sub['held'] !== null) {
            return $sub['held'] >= $instant;
        }

        return ! $sub['terminal'] || $sub['ended'] === null || $sub['ended'] >= $instant;
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

        $subs = $this->lifecycleSubs();

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
            $startS = Clock::gmt($cursor);
            $endS = Clock::gmt($monthEnd);

            $activeStart = 0;
            $joined = 0;
            $churned = 0;
            $failed = 0;
            $base = 0.0;
            $retained = 0.0;

            foreach ($subs as $sub) {
                $open = $this->wasActiveAt($sub, $startS);

                if ($open) {
                    $activeStart++;
                }

                if ($sub['created'] >= $startS && $sub['created'] < $endS) {
                    $joined++;
                }

                $leftNow = $this->endedBetween($sub, $startS, $endS);

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

                $stillRunning = $this->stillBillingAt($sub, $endS);

                $base += $was;
                $retained += $stillRunning ? $this->paymentBefore($payments[$sub['id']] ?? [], $endS) : 0.0;
            }

            $real = max(0, $churned - $failed);

            $rows[] = [
                'month' => $cursor->format('Y-m'),
                'churn_net' => $this->churnRate($real, $activeStart + $joined),
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
     * falls inside is the one that is still filling up. Returned in UK time,
     * so "the month it falls inside" is a UK month.
     */
    private function latestDataInstant(): CarbonImmutable
    {
        // The import / sync time when there is one: a fortnight with no orders
        // is still a fortnight of data -- cancellations and holds keep
        // happening -- so the walk must not stop at the last order.
        if ($this->freshness->dataTimestamp() !== null) {
            return $this->freshness->asOf();
        }

        $latest = (string) DB::table('records')->max('date_created_gmt');

        return $latest !== '' ? Clock::toLocal($latest) : Clock::now();
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

        $subs = array_map(fn (array $sub) => $sub + [
            // What one month of this subscription is worth, as a share of
            // whatever it pays per cycle.
            'monthly_share' => 30 / $this->cycleDays(
                $sub['billing_period'] === null ? null : (string) $sub['billing_period'],
                $sub['billing_interval'] === null ? null : (int) $sub['billing_interval'],
            ),
        ], $this->lifecycleSubs(['billing_period', 'billing_interval']));

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
            $endS = Clock::gmt($monthEnd);

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
                'arpu' => $paying > 0 ? round($mrr / $paying, 4) : null,
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
     * @param  int  $min  segments smaller than this are folded into one "Other" row
     * @return array{dimension:string, rows:array<int, array<string, mixed>>}
     */
    public function segmentPerformance(string $dimension = 'utm_source', int $min = 5): array
    {
        if (! in_array($dimension, self::SEGMENT_DIMENSIONS, true)) {
            $dimension = 'utm_source';
        }

        $min = max(1, $min);
        $asOf = $this->asOfGmt();

        // Payments per subscription: the parent order plus every completed
        // renewal. Counting renewals alone would report every one-payment
        // subscriber as having never paid at all.
        $paid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as payments, SUM(total_amount) as revenue')
            ->groupBy('subscription_id');

        $book = $this->lifecycleSubs([$dimension]);

        $perSub = DB::table('records as s')
            ->leftJoinSub($paid, 'p', 'p.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->selectRaw('s.id, s.'.$dimension.' as segment, COALESCE(p.payments, 0) as payments, COALESCE(p.revenue, 0) as revenue')
            ->selectRaw('s.date_created_gmt as created, s.billing_period, s.billing_interval, s.next_payment_at')
            ->get();

        // Group in PHP: "still active" is the shared isActive() rule, which no
        // single SQL aggregate can express.
        $groups = [];

        foreach ($perSub as $r) {
            $label = $this->cleanAttribute($r->segment) ?? '(unattributed)';
            $sub = $book[(int) $r->id] ?? null;

            $groups[$label] ??= self::EMPTY_SEGMENT;
            $g = &$groups[$label];
            $g['subs']++;
            $g['never_paid'] += (int) $r->payments === 0 ? 1 : 0;

            // Right-censoring: a subscription whose second payment is not due
            // yet has not failed to reach it. Counting it as "did not" dragged
            // down every channel that is mostly recent sign-ups.
            if ($this->secondPaymentDueBy($r, $asOf)) {
                $g['eligible']++;
                $g['repeat_payers'] += (int) $r->payments >= 2 ? 1 : 0;
            }
            $g['still_active'] += $sub !== null && $this->isActive($sub, $asOf) ? 1 : 0;
            $g['revenue'] += (float) $r->revenue;
            unset($g);
        }

        // Segments too small to read are folded into one "Other" row rather
        // than dropped: dropped, they made the "still active" column add up to
        // less than the active subscriber count on the Overview.
        $other = self::EMPTY_SEGMENT;
        $folded = 0;

        foreach ($groups as $label => $g) {
            if ($g['subs'] < $min) {
                foreach ($other as $k => $_) {
                    $other[$k] += $g[$k];
                }
                $folded++;
                unset($groups[$label]);
            }
        }

        uasort($groups, fn ($a, $z) => $z['subs'] <=> $a['subs']);

        $out = [];

        foreach ($groups as $label => $g) {
            $out[] = $this->segmentRow((string) $label, $g);
        }

        if ($folded > 0) {
            $out[] = $this->segmentRow('Other', $other) + ['rollup' => $folded];
        }

        return [
            'dimension' => $dimension,
            'rows' => $out,
            'totals' => [
                'subs' => array_sum(array_column($out, 'subs')),
                'still_active' => array_sum(array_column($out, 'still_active')),
            ],
        ];
    }

    /** Accumulator for one segment of {@see segmentPerformance()}. */
    private const EMPTY_SEGMENT = [
        'subs' => 0, 'never_paid' => 0, 'eligible' => 0, 'repeat_payers' => 0, 'still_active' => 0, 'revenue' => 0.0,
    ];

    /**
     * Whether a subscription's SECOND payment had fallen due by $asOf, so
     * whether it can be judged on reaching it.
     *
     * While it is still on its first cycle WooCommerce schedules that payment
     * as `next_payment_at`; otherwise it is one billing cycle after sign-up.
     */
    private function secondPaymentDueBy(object $sub, string $asOf): bool
    {
        if ((int) $sub->payments <= 1 && $sub->next_payment_at !== null) {
            return (string) $sub->next_payment_at <= $asOf;
        }

        $cycle = $this->cycleDays(
            $sub->billing_period === null ? null : (string) $sub->billing_period,
            $sub->billing_interval === null ? null : (int) $sub->billing_interval,
        );

        return Clock::gmt(CarbonImmutable::parse((string) $sub->created)->addDays($cycle)) <= $asOf;
    }

    /**
     * One row of {@see segmentPerformance()}.
     *
     * The repeat rate is over the `eligible` subscriptions only -- those whose
     * second payment was due -- and the row reports that n alongside it.
     *
     * @param  array{subs:int, never_paid:int, eligible:int, repeat_payers:int, still_active:int, revenue:float}  $g
     * @return array<string, mixed>
     */
    private function segmentRow(string $segment, array $g): array
    {
        $subs = $g['subs'];
        $eligible = $g['eligible'];
        $revenue = $g['revenue'];

        $repeatPct = $eligible > 0 ? round($g['repeat_payers'] / $eligible * 100, 1) : null;
        $margin = $this->marginOfError($g['repeat_payers'], $eligible);
        $ltv = round($revenue / $subs, 2);

        return [
            'segment' => $segment,
            'subs' => $subs,
            'never_paid' => $g['never_paid'],
            'never_paid_pct' => round($g['never_paid'] / $subs * 100, 1),
            // repeat = reached a 2nd payment ÷ subscriptions whose 2nd payment was due
            'eligible' => $eligible,
            'repeat_payers' => $g['repeat_payers'],
            'repeat_pct' => $repeatPct,
            // A rate without its sample size invites acting on noise: at
            // n = 13 a 69% repeat rate spans roughly 44%-94%.
            'repeat_margin' => $margin,
            'repeat_low' => $repeatPct === null ? null : round(max(0, $repeatPct - $margin), 1),
            'repeat_high' => $repeatPct === null ? null : round(min(100, $repeatPct + $margin), 1),
            'reliable' => $eligible >= (int) config('metrics.segment_min_reliable_n', 30),
            'still_active' => $g['still_active'],
            'revenue' => round($revenue, 2),
            'ltv' => $ltv,
            // Null until a gross margin is configured — LTV is a revenue
            // number, not a profit one, and guessing the difference is worse
            // than reporting that nobody has supplied it.
            'contribution' => $this->contributionFrom($ltv),
            'payback_ratio' => $this->paybackRatio($ltv),
        ];
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
        $subRows = match ($audience) {
            'win_back' => $this->winBackAudience(),
            'never_subscribed' => $this->neverSubscribedAudience(),
            'partial_churn' => $this->partialChurnAudience(),
            default => $this->crossSellAudience(),
        };

        // One row per CUSTOMER. A household with two single-flavour plans is
        // one upgrade conversation, not two, and the header said "148
        // customers" over a list of subscriptions with repeated emails.
        $rows = $this->groupAudienceByCustomer($subRows);

        // Warmest first: what they have already spent is the best available
        // proxy for how likely the next conversation is to land.
        usort($rows, fn ($a, $z) => $z['value'] <=> $a['value']);

        return [
            'audience' => $audience,
            'total' => count($rows),
            'subscriptions' => count(array_filter($subRows, fn ($r) => $r['subscription_id'] !== null)),
            'value' => round(array_sum(array_column($rows, 'value')), 2),
            'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
        ];
    }

    /**
     * Merge audience rows that belong to the same customer (billing email,
     * falling back to customer id), keeping a count of their subscriptions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupAudienceByCustomer(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            $key = $this->customerKey($r['customer'] ?? null, (int) ($r['customer_id'] ?? 0))
                ?? 'sub:'.($r['subscription_id'] ?? count($out));

            if (! isset($out[$key])) {
                $out[$key] = $r + ['subscription_ids' => []];
                $out[$key]['details'] = [];
                $out[$key]['cycles'] = [];
            } else {
                $out[$key]['payments'] += $r['payments'];
                $out[$key]['value'] = round($out[$key]['value'] + $r['value'], 2);
                // The soonest date is the one to act on.
                if ($r['next_at'] !== null && ($out[$key]['next_at'] === null || $r['next_at'] < $out[$key]['next_at'])) {
                    $out[$key]['next_at'] = $r['next_at'];
                }
            }

            if ($r['subscription_id'] !== null) {
                $out[$key]['subscription_ids'][] = $r['subscription_id'];
            }
            if ($r['detail'] !== null) {
                $out[$key]['details'][$r['detail']] = true;
            }
            if ($r['cycle'] !== null) {
                $out[$key]['cycles'][$r['cycle']] = true;
            }
        }

        return array_values(array_map(function (array $r) {
            $r['subscriptions'] = count($r['subscription_ids']);
            $r['detail'] = $r['details'] === [] ? null : implode(' · ', array_keys($r['details']));
            $r['cycle'] = $r['cycles'] === [] ? null : implode(' · ', array_keys($r['cycles']));
            unset($r['details'], $r['cycles']);

            return $r;
        }, $out));
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
            ->whereIn('status', Record::ORDER_STATUSES)
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
                'customer_id' => (int) ($r->customer_id ?? 0),
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
     * Subscriptions active right now ({@see isActive()}) whose next payment is
     * due inside the window ahead.
     *
     * `next_payment_at` is WooCommerce's `_schedule_next_payment`, so this is
     * the actual renewal pipeline rather than an inference. Subscribers who
     * have paid only once are flagged: half of all payers never reach a second
     * payment, which makes that renewal the one worth intervening on. Ones
     * whose first renewal already failed are on hold, not in the pipeline,
     * and are returned separately as `failed_first_renewal`.
     *
     * @param  int  $days  how far ahead to look
     * @return array{total:int, at_first_renewal:int, value:float, rows:array<int, array<string, mixed>>, failed_first_renewal:array{total:int, value:float, rows:array<int, array<string, mixed>>}}
     */
    public function upcomingRenewals(int $days = 14, ?int $limit = 200): array
    {
        // The real date, not the newest row: measured from the data, a week-old
        // export put every renewal a week further out than WooCommerce does.
        $now = Clock::now();
        $today = $now->startOfDay();
        $asOf = $this->asOfGmt();
        $book = $this->lifecycleSubs();

        $paid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as payments, MAX(total_amount) as amount')
            ->groupBy('subscription_id');

        $all = DB::table('records as s')
            ->leftJoinSub($paid, 'p', 'p.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->whereNotIn('s.status', self::TERMINAL)
            ->whereNotNull('s.next_payment_at')
            ->where('s.next_payment_at', '>=', Clock::gmt($now))
            ->where('s.next_payment_at', '<', Clock::gmt($now->addDays(max(1, $days))))
            ->selectRaw('s.id, s.billing_email, s.customer_id, s.status, s.next_payment_at')
            ->selectRaw('s.utm_source, s.billing_period, s.billing_interval')
            ->selectRaw('COALESCE(p.payments, 0) as payments, COALESCE(p.amount, 0) as amount')
            ->orderBy('s.next_payment_at')
            ->get()
            // Live status only. A subscription whose first renewal already
            // failed is on hold: WooCommerce still carries its next-payment
            // date, but it is not going to "renew today" -- it is listed in
            // failedFirstRenewals() instead.
            ->filter(fn ($r) => isset($book[(int) $r->id]) && $this->isActive($book[(int) $r->id], $asOf));

        $rows = [];
        foreach ($all as $r) {
            $payments = (int) $r->payments;

            $rows[] = [
                'id' => (int) $r->id,
                'customer' => $r->billing_email ?: ($r->customer_id > 0 ? 'Customer #'.$r->customer_id : null),
                'status' => 'active',
                'due_at' => (string) $r->next_payment_at,
                // Calendar days in UK time, the way WooCommerce's Next Payment
                // column reads: due at 09:00 tomorrow is "in 1d", not "today".
                'days_away' => (int) max(0, $today->diffInDays(Clock::toLocal((string) $r->next_payment_at)->startOfDay())),
                'payments_so_far' => $payments,
                'amount' => round((float) $r->amount, 2),
                'source' => $r->utm_source,
                'cycle_days' => $this->cycleDays($r->billing_period, $r->billing_interval === null ? null : (int) $r->billing_interval),
                // The renewal that decides whether they become a real customer.
                'first_renewal' => $payments === 1,
            ];
        }

        $firsts = array_filter($rows, fn ($r) => $r['first_renewal']);
        $failed = $this->failedFirstRenewals($book, $asOf);

        return [
            'total' => count($rows),
            'at_first_renewal' => count($firsts),
            'value' => round(array_sum(array_column($rows, 'amount')), 2),
            'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
            'failed_first_renewal' => [
                'total' => count($failed),
                'value' => round(array_sum(array_column($failed, 'amount')), 2),
                'rows' => $limit === null ? $failed : array_slice($failed, 0, $limit),
            ],
        ];
    }

    /**
     * Subscriptions on hold at $asOf that have paid exactly once: their first
     * renewal failed. The save list for the renewal that decides whether a
     * sign-up becomes a customer. Most recently held first.
     *
     * @param  array<int, array<string, mixed>>  $book  from {@see lifecycleSubs()}
     * @return array<int, array<string, mixed>>
     */
    private function failedFirstRenewals(array $book, string $asOf): array
    {
        $onHold = array_filter($book, fn (array $sub) => ($this->statusFromTimeline($sub['timeline'], $asOf) ?? $sub['status']) === 'on-hold');

        if ($onHold === []) {
            return [];
        }

        $paid = [];
        foreach (DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereIn('subscription_id', array_keys($onHold))
            ->selectRaw('subscription_id, COUNT(*) as payments, MAX(total_amount) as amount, MAX(date_created_gmt) as paid_at')
            ->groupBy('subscription_id')
            ->get() as $p) {
            $paid[(int) $p->subscription_id] = $p;
        }

        $details = DB::table('records')
            ->whereIn('id', array_keys($onHold))
            ->get(['id', 'billing_email', 'customer_id', 'utm_source', 'next_payment_at'])
            ->keyBy('id');

        $rows = [];
        foreach ($onHold as $id => $sub) {
            if ((int) ($paid[$id]->payments ?? 0) !== 1) {
                continue;
            }

            $d = $details[$id];
            $heldAt = $this->lastChangeTo($sub['timeline'], 'on-hold', $asOf) ?? $sub['held'];

            $rows[] = [
                'id' => $id,
                'customer' => $d->billing_email ?: ($d->customer_id > 0 ? 'Customer #'.$d->customer_id : null),
                'held_at' => $heldAt,
                'was_due_at' => $d->next_payment_at,
                'first_paid_at' => (string) $paid[$id]->paid_at,
                'amount' => round((float) $paid[$id]->amount, 2),
                'source' => $d->utm_source,
            ];
        }

        usort($rows, fn ($a, $z) => strcmp((string) $z['held_at'], (string) $a['held_at']));

        return $rows;
    }

    /**
     * When a timeline last moved into $status before $instant, or null.
     *
     * @param  array<int, array{at:string, from:string, to:string}>  $timeline
     */
    private function lastChangeTo(array $timeline, string $status, string $instant): ?string
    {
        $at = null;

        foreach ($timeline as $change) {
            if ($change['at'] >= $instant) {
                break;
            }
            if ($change['to'] === $status) {
                $at = $change['at'];
            }
        }

        return $at;
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
        $spend = [];

        foreach (DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->selectRaw('subscription_id, SUM(total_amount) as spend')
            ->groupBy('subscription_id')
            ->get() as $r) {
            $spend[(int) $r->subscription_id] = (float) $r->spend;
        }

        $asOf = $this->asOfGmt();
        $cohorts = [];

        foreach ($this->lifecycleSubs() as $id => $sub) {
            $month = Clock::monthKey($sub['created']);

            $cohorts[$month] ??= ['size' => 0, 'active' => 0, 'churned' => 0, 'spends' => [], 'tenures' => []];
            $cohorts[$month]['size']++;
            $cohorts[$month]['spends'][] = $spend[$id] ?? 0.0;

            if ($this->isActive($sub, $asOf)) {
                $cohorts[$month]['active']++;
            }

            $ended = $sub['terminal'] && $sub['ended'] !== null && $sub['ended'] <= $asOf;

            if ($ended) {
                $cohorts[$month]['churned']++;
            }

            // Tenure for EVERY subscription: to its end date if it has ended,
            // otherwise to now. Counting only the leavers gave a cohort that
            // is 57% still active a median tenure of 5 days -- the median of
            // the ones who left fast, not of the cohort.
            $cohorts[$month]['tenures'][] = (int) max(0, floor(
                CarbonImmutable::parse($sub['created'])->diffInDays(CarbonImmutable::parse($ended ? $sub['ended'] : $asOf))
            ));
        }

        krsort($cohorts);

        // Cohorts older than the window are summed into one "Earlier" row, so
        // the "still active" column adds up to the active subscriber count
        // instead of quietly losing everyone who joined over a year ago.
        $shown = array_slice($cohorts, 0, $maxCohorts, true);
        $earlier = array_slice($cohorts, $maxCohorts, null, true);

        // A cohort younger than this has not had time to show its true value.
        $cutoff = $this->latestDataInstant()->subMonths(3)->format('Y-m');

        $rows = [];

        foreach ($shown as $month => $c) {
            $rows[] = $this->cohortValueRow((string) $month, $c) + ['immature' => $month > $cutoff, 'rollup' => false];
        }

        if ($earlier !== []) {
            $merged = ['size' => 0, 'active' => 0, 'churned' => 0, 'spends' => [], 'tenures' => []];

            foreach ($earlier as $c) {
                $merged['size'] += $c['size'];
                $merged['active'] += $c['active'];
                $merged['churned'] += $c['churned'];
                array_push($merged['spends'], ...$c['spends']);
                array_push($merged['tenures'], ...$c['tenures']);
            }

            $rows[] = $this->cohortValueRow('earlier', $merged) + [
                'immature' => false,
                'rollup' => true,
                'from' => array_key_last($earlier),
                'to' => array_key_first($earlier),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * @param  array{size:int, active:int, churned:int, spends:array<int, float>, tenures:array<int, int>}  $c
     * @return array<string, mixed>
     */
    private function cohortValueRow(string $cohort, array $c): array
    {
        $total = array_sum($c['spends']);

        return [
            'cohort' => $cohort,
            'size' => $c['size'],
            'still_active' => $c['active'],
            'retained_pct' => $c['size'] > 0 ? round($c['active'] / $c['size'] * 100, 1) : 0.0,
            'churned' => $c['churned'],
            'total_spend' => round($total, 2),
            'value_per_subscriber' => $c['size'] > 0 ? round($total / $c['size'], 2) : 0.0,
            'median_spend' => $this->medianFloat($c['spends']),
            'median_tenure_days' => $this->median($c['tenures']),
        ];
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
     * Cohort retention as survival: of the subscriptions that signed up in
     * month M, the share that had not ended (cancelled / expired) by the end
     * of month M+n, for n = 0..maxOffset.
     *
     * It used to be "had a completed order in month M+n". About a quarter of
     * this book renews every six weeks or two months, so a perfectly loyal
     * subscriber skips whole months: the curve went UP over time (Feb cohort
     * M4 20.8% > M3 17.7%), which retention cannot do. Survival can only stay
     * flat or fall. The old grid is still returned as `activity` and shown as
     * the "Order activity" view.
     *
     * A month that has not finished is measured at the data timestamp and
     * flagged `partial`; a month that has not started is null.
     *
     * @return array{offsets:int[], rows:array<int,array{cohort:string,size:int,cells:array<int,?array{count:int,pct:float,partial:bool}>,activity:array<int,array{count:int,pct:float}>}>}
     */
    public function cohortRetention(int $maxOffset = 6, int $maxCohorts = 12): array
    {
        $asOf = $this->asOfGmt();
        $book = $this->lifecycleSubs();

        $members = []; // 'Y-m' => [sub, ...]
        foreach ($book as $sub) {
            $members[Clock::monthKey($sub['created'])][] = $sub;
        }

        krsort($members); // most recent first
        $cohorts = array_slice(array_keys($members), 0, $maxCohorts);
        sort($cohorts);   // display oldest -> newest

        $activity = $this->cohortOrderActivity($book, $cohorts, $maxOffset);

        $rows = [];
        foreach ($cohorts as $cohort) {
            $size = count($members[$cohort]);
            $opens = CarbonImmutable::parse($cohort.'-01 00:00:00', Clock::tz());
            $cells = [];

            for ($k = 0; $k <= $maxOffset; $k++) {
                $monthStart = Clock::gmt($opens->addMonths($k));
                $monthEnd = Clock::gmt($opens->addMonths($k + 1));

                if ($monthStart > $asOf) {
                    $cells[$k] = null; // not happened yet
                    continue;
                }

                $partial = $monthEnd > $asOf;
                $at = $partial ? $asOf : $monthEnd;

                $alive = count(array_filter($members[$cohort], fn (array $sub) => ! $this->hasEndedBy($sub, $at)));

                $cells[$k] = [
                    'count' => $alive,
                    'pct' => $size > 0 ? round($alive / $size * 100, 1) : 0.0,
                    'partial' => $partial,
                ];
            }

            $rows[] = [
                'cohort' => $cohort,
                'size' => $size,
                'cells' => $cells,
                'activity' => $activity[$cohort],
            ];
        }

        return ['offsets' => range(0, $maxOffset), 'rows' => $rows];
    }

    /**
     * Whether a subscription had left (cancelled / expired, end date on or
     * before $instant) by $instant. Pausing is not leaving. A terminal row with
     * no end date at all is treated as gone: it cannot be shown to be alive.
     *
     * @param  array{terminal:bool, ended:?string}  $sub
     */
    private function hasEndedBy(array $sub, string $instant): bool
    {
        return $sub['terminal'] && ($sub['ended'] === null || $sub['ended'] <= $instant);
    }

    /**
     * The old cohort grid, kept as the "Order activity" view: of a cohort, the
     * share with a completed order in month M+k. Useful for seeing billing
     * rhythm -- not retention, because a six-weekly plan skips months.
     *
     * @param  array<int, array<string, mixed>>  $book
     * @param  array<int, string>  $cohorts
     * @return array<string, array<int, array{count:int, pct:float}>>
     */
    private function cohortOrderActivity(array $book, array $cohorts, int $maxOffset): array
    {
        $cohortOf = [];
        $size = [];
        foreach ($book as $id => $sub) {
            $cohortOf[$id] = Clock::monthKey($sub['created']);
            $size[$cohortOf[$id]] = ($size[$cohortOf[$id]] ?? 0) + 1;
        }

        $counts = []; // 'Y-m' => [offset => distinct subs]
        $seen = [];
        foreach (DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->select('subscription_id', 'date_created_gmt')
            ->get() as $o) {
            $sid = (int) $o->subscription_id;
            if (! isset($cohortOf[$sid])) {
                continue;
            }
            $cohort = $cohortOf[$sid];
            $offset = $this->monthDiff($cohort, Clock::monthKey((string) $o->date_created_gmt));
            if ($offset < 0 || $offset > $maxOffset || isset($seen["{$cohort}|{$offset}|{$sid}"])) {
                continue;
            }
            $seen["{$cohort}|{$offset}|{$sid}"] = true;
            $counts[$cohort][$offset] = ($counts[$cohort][$offset] ?? 0) + 1;
        }

        $out = [];
        foreach ($cohorts as $cohort) {
            for ($k = 0; $k <= $maxOffset; $k++) {
                $count = $counts[$cohort][$k] ?? 0;
                $out[$cohort][$k] = [
                    'count' => $count,
                    'pct' => ($size[$cohort] ?? 0) > 0 ? round($count / $size[$cohort] * 100, 1) : 0.0,
                ];
            }
        }

        return $out;
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
     * A one-time PURCHASE is a completed one-time order worth more than £0.
     * Free orders (samples, replacements) and orders that were never paid were
     * counting as purchases, and same-day sign-ups -- a customer checking out
     * a subscription and a one-off together -- as conversions. Both inflated
     * the conversion rate. A conversion now needs the subscription to start
     * at least one UK calendar day after the first one-time purchase;
     * `$includeSameDay` restores the old reading.
     *
     * @param  bool  $conversionsOnly  keep only customers who converted
     * @param  bool  $includeSameDay  count a subscription started the same day as a conversion
     * @param  int  $limit  max rows returned (0 = all); the summary always counts everything
     * @return array{customers: array<int, array<string, mixed>>, summary: array<string, mixed>, total: int}
     */
    public function oneTimeToSubscription(bool $conversionsOnly = true, bool $includeSameDay = false, int $limit = 0): array
    {
        $key = $this->customerKeyExpr();
        $purchase = fn (Builder $q) => $q->where('status', 'completed')->where('total_amount', '>', 0);
        $minDays = $includeSameDay ? 0 : 1;

        // One-time orders, aggregated per customer. The key is computed in a
        // derived table so the GROUP BY is on a plain column — grouping by the
        // raw expression trips MySQL's ONLY_FULL_GROUP_BY.
        $oneTimeRows = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
            ->where('order_relationship', 'one_time')
            ->whereNotNull('date_created_gmt')
            ->where($purchase)
            ->selectRaw("{$key} as ckey, date_created_gmt, status, total_amount, billing_email, customer_id");

        $oneTime = DB::query()->fromSub($oneTimeRows, 'ot')
            ->whereNotNull('ckey')
            ->selectRaw('ckey, MIN(date_created_gmt) as first_at, MAX(date_created_gmt) as last_at, COUNT(*) as orders')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as spend")
            ->selectRaw('MAX(billing_email) as email, MAX(customer_id) as customer_id')
            ->groupBy('ckey')
            ->get()
            ->keyBy('ckey');

        // How the FIRST one-time order was acquired — the order whose date the
        // list shows. An aggregate cannot answer this: MAX(utm_source) is the
        // alphabetically last source the customer ever used, not the one that
        // brought them in. So the rows are read oldest-first and the first of
        // each bucket is kept.
        $firstOneTimeOrder = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
            ->where('order_relationship', 'one_time')
            ->whereNotNull('date_created_gmt')
            ->whereRaw("({$key}) IS NOT NULL")
            ->where($purchase)
            ->selectRaw("{$key} as ckey, utm_source, utm_medium, utm_campaign, attribution_type")
            ->orderBy('date_created_gmt')
            ->get()
            ->groupBy('ckey')
            ->map(fn ($orders) => $orders->first());

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
            $firstDay = Clock::toLocal($firstOneTime)->startOfDay();

            // First subscription taken out at least $minDays UK calendar days
            // after the first one-time purchase.
            $sub = null;
            $days = null;
            foreach ($customerSubs as $candidate) {
                if ((string) $candidate->date_created_gmt < $firstOneTime) {
                    continue;
                }

                $gap = (int) $firstDay->diffInDays(Clock::toLocal((string) $candidate->date_created_gmt)->startOfDay());

                if ($gap >= $minDays) {
                    $sub = $candidate;
                    $days = $gap;
                    break;
                }
            }

            $isConversion = $sub !== null;

            if (! $isConversion) {
                if ($conversionsOnly) {
                    continue; // subscribed first, or the same day
                }
                $sub = $customerSubs->first();
            }

            if ($isConversion) {
                $converted++;
                $daysTotal += $days;
                $daysCount++;
            }

            $revenue = $subRevenue[$ckey] ?? null;
            $origin = $firstOneTimeOrder[$ckey] ?? null;

            $rows[] = [
                'key' => (string) $ckey,
                'email' => $ot->email ?: ($sub->billing_email ?: null),
                'customer_id' => (int) $ot->customer_id ?: null,
                'first_one_time_at' => $firstOneTime,
                'one_time_source' => $this->attributionLabel($origin),
                'one_time_medium' => $this->cleanAttribute($origin?->utm_medium),
                'one_time_campaign' => $this->cleanAttribute($origin?->utm_campaign),
                'one_time_attribution' => $this->cleanAttribute($origin?->attribution_type),
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

    /**
     * How an order was acquired, as the one label a human would name.
     *
     * `utm_source` is the recognisable channel ("google", "(direct)");
     * `attribution_type` is WooCommerce's coarser bucket ("organic",
     * "referral", "admin") and is often the only thing present when no UTMs
     * were captured, so it stands in rather than leaving the row blank.
     *
     * Null means the order carried no attribution at all — a different fact
     * from "(direct)", which positively states there was no referrer.
     */
    private function attributionLabel(?object $order): ?string
    {
        if ($order === null) {
            return null;
        }

        return $this->cleanAttribute($order->utm_source)
            ?? $this->cleanAttribute($order->attribution_type);
    }

    /** An attribution column, with empty strings treated as absent. */
    private function cleanAttribute(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
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
     * Only real order statuses ({@see Record::ORDER_STATUSES}) are business
     * data. `trash` is a deleted order: when "not completed" was defined as
     * `status != 'completed'`, 26 deleted orders worth GBP 3,099 were reported
     * as failed business. A subscription status on an order (three rows carry
     * `wc-active`) is a data defect WP Admin hides, so the dashboard hides it
     * too and lists it on the Data Health page instead. Exclusion happens here
     * so no caller can forget it; {@see unrecognisedStatuses()} surfaces what
     * was dropped.
     */
    private function ordersInWindow(string $start, string $end): Builder
    {
        return DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
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
     * Read from the imported status history where a subscription has one
     * ({@see whereStatusAt()}); otherwise reconstructed from the orders export
     * as well as it allows.
     */
    private function snapshotCount(string $status, string $end): int
    {
        return $this->subscriptionLifecycle()
            ->whereNotNull('s.date_created_gmt')
            ->where('s.date_created_gmt', '<', $end)
            ->where(fn (Builder $q) => $this->whereStatusAt($q, $status, $end))
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
            ->whereIn('status', Record::ORDER_STATUSES)
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
        return $this->snapshotCount('active', $end);
    }

    /**
     * The "was an active subscriber at $instant" predicate, shared by every SQL
     * query that asks it. {@see wasActiveAt()} is its PHP twin.
     */
    private function whereLiveAt(Builder $q, string $instant): void
    {
        $this->whereStatusAt($q, 'active', $instant);
    }

    /**
     * "Was in $status at $instant", for every status.
     *
     * A subscription with imported status history ({@see statusAtExpr()}) is
     * judged on it alone: that is the real status on the day. One without
     * history falls back to what the orders export can prove, in
     * {@see whereStatusFromExport()}.
     */
    private function whereStatusAt(Builder $q, string $status, string $instant): void
    {
        $known = $this->statusAtExpr();

        $q->whereRaw("{$known} = ?", [$instant, $instant, $status])
            ->orWhere(function (Builder $none) use ($known, $status, $instant) {
                $none->whereRaw("{$known} IS NULL", [$instant, $instant])
                    ->where(fn (Builder $f) => $this->whereStatusFromExport($f, $status, $instant));
            });
    }

    /**
     * A subscription's status at an instant, from the imported status history;
     * NULL when the history says nothing about it. Binds the instant twice.
     *
     * The status after the last change before the instant -- or, when every
     * change came later, the status the first of them changed *from*.
     */
    private function statusAtExpr(): string
    {
        return '(COALESCE('
            .'(SELECT h.to_status FROM subscription_status_changes h'
            .' WHERE h.subscription_id = s.id AND h.changed_at < ?'
            .' ORDER BY h.changed_at DESC, h.id DESC LIMIT 1), '
            ."(SELECT NULLIF(h.from_status, '') FROM subscription_status_changes h"
            .' WHERE h.subscription_id = s.id AND h.changed_at >= ?'
            .' ORDER BY h.changed_at ASC, h.id ASC LIMIT 1)'
            .'))';
    }

    /**
     * Status at $instant from the orders export alone, which only knows each
     * subscription's status today.
     *
     *  - active: running today, or ended (per its end date) after $instant, or
     *    on hold today but not yet at $instant ({@see holdStarts()}).
     *  - cancelled / expired: in that status today and ended before $instant.
     *    With no end date it is counted, so lifetime totals still add up.
     *  - on-hold: on hold today, and the estimated hold began before $instant.
     *  - anything else: read as-is -- the export has nothing better.
     */
    private function whereStatusFromExport(Builder $q, string $status, string $instant): void
    {
        if ($status === 'active') {
            $q->where('s.status', 'active')
                ->orWhereIn('s.id', $this->heldAfter($instant));

            // Ended after $instant: still active then. Only inside the data --
            // at the data timestamp a terminal status means not active, even
            // when the end date is scheduled for later.
            if ($instant < $this->asOfGmt()) {
                $q->orWhere(function (Builder $terminal) use ($instant) {
                    // NULL end date => we cannot prove it was still live, so
                    // the comparison is NULL and the row drops out.
                    $terminal->whereIn('s.status', self::TERMINAL)
                        ->whereRaw($this->effectiveEndExpr().' >= ?', [$instant]);
                });
            }

            return;
        }

        if (in_array($status, self::TERMINAL, true)) {
            $q->where('s.status', $status)
                ->where(function (Builder $ended) use ($instant) {
                    $ended->whereRaw($this->effectiveEndExpr().' < ?', [$instant])
                        ->orWhereRaw($this->effectiveEndExpr().' IS NULL');
                });

            return;
        }

        if ($status === 'on-hold') {
            $q->whereIn('s.id', array_keys(array_filter(
                $this->holdStarts(),
                fn (array $h) => $h['held'] < $instant,
            )));

            return;
        }

        $q->where('s.status', $status);
    }

    /**
     * Every subscription's imported status changes, oldest first, keyed by id.
     *
     * @return array<int, array<int, array{at:string, from:string, to:string}>>
     */
    public function statusTimelines(): array
    {
        $out = [];

        $rows = DB::table('subscription_status_changes')
            ->orderBy('changed_at')
            ->orderBy('id')
            ->select('subscription_id', 'changed_at', 'from_status', 'to_status')
            ->cursor();

        foreach ($rows as $r) {
            $out[(int) $r->subscription_id][] = [
                'at' => (string) $r->changed_at,
                'from' => (string) $r->from_status,
                'to' => (string) $r->to_status,
            ];
        }

        return $out;
    }

    /**
     * The PHP twin of {@see statusAtExpr()}: the status a timeline puts a
     * subscription in at $instant, or null when it does not say.
     *
     * @param  array<int, array{at:string, from:string, to:string}>  $timeline
     */
    public function statusFromTimeline(array $timeline, string $instant): ?string
    {
        $status = null;

        foreach ($timeline as $change) {
            if ($change['at'] >= $instant) {
                return $status ?? ($change['from'] !== '' ? $change['from'] : null);
            }

            $status = $change['to'];
        }

        return $status;
    }

    /**
     * When each `on-hold` subscription with no imported status history is
     * estimated to have gone on hold, keyed by id.
     *
     * The orders export carries no hold date, only the live status, so reading
     * `on-hold` as-is pulls every subscriber who pauses this month out of every
     * earlier month too. WooCommerce puts a subscription on hold when a renewal
     * is due and not paid, so the hold is dated one billing cycle after its
     * last completed payment -- or its sign-up, if it never paid, in which case
     * it was never active. Capped at the newest data, so a subscription on hold
     * now reads as on hold in the current month even when it was suspended
     * mid-cycle. Subscriptions with real history do not need guessing.
     *
     * @return array<int, array{created:string, held:string}>
     */
    public function holdStarts(): array
    {
        $lastPaid = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->selectRaw('subscription_id, MAX(date_created_gmt) as last_paid_at')
            ->groupBy('subscription_id');

        $rows = DB::table('records as s')
            ->leftJoinSub($lastPaid, 'lp', 'lp.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->where('s.status', 'on-hold')
            ->whereNotNull('s.date_created_gmt')
            ->whereNotExists(fn (Builder $h) => $h->from('subscription_status_changes as h')
                ->whereColumn('h.subscription_id', 's.id')
                ->selectRaw('1'))
            ->selectRaw('s.id, s.date_created_gmt, s.billing_period, s.billing_interval, lp.last_paid_at')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $latest = $this->latestDataInstant();
        $out = [];

        foreach ($rows as $row) {
            $created = CarbonImmutable::parse((string) $row->date_created_gmt);

            $held = $row->last_paid_at === null
                ? $created
                : CarbonImmutable::parse((string) $row->last_paid_at)->addDays($this->cycleDays(
                    $row->billing_period === null ? null : (string) $row->billing_period,
                    $row->billing_interval === null ? null : (int) $row->billing_interval,
                ));

            if ($held->gt($latest)) {
                $held = $latest;
            }

            if ($held->lt($created)) {
                $held = $created;
            }

            $out[(int) $row->id] = [
                'created' => Clock::gmt($created),
                'held' => Clock::gmt($held),
            ];
        }

        return $out;
    }

    /**
     * Ids of estimated `on-hold` subscriptions that had not yet gone on hold at
     * $instant.
     *
     * @return array<int, int>
     */
    private function heldAfter(string $instant): array
    {
        return array_keys(array_filter(
            $this->holdStarts(),
            fn (array $h) => $h['held'] >= $instant,
        ));
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
        $end = $this->capAtAsOf($end);

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
     * The dormant on-hold subscriptions as of $end, each with the instant it
     * became dormant and what it was worth per month.
     *
     * These have stopped paying but will never appear in churn, because churn
     * only counts terminal statuses and nothing promotes a stalled on-hold to
     * one. Surfaced -- and counted in Churn · Adjusted -- so the gap is
     * visible instead of silently understating attrition.
     *
     * Dormant = on hold with no successful payment for
     * {@see ON_HOLD_DORMANT_CYCLES} billing cycles (45 days on a monthly plan).
     * Never billed: measured from sign-up, since that is when the first
     * payment was due.
     *
     * @return array<int, array{dormant_from:string, monthly_value:float}>
     */
    private function dormantOnHoldSubscriptions(string $end): array
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
            ->selectRaw('s.id, s.billing_period, s.billing_interval, s.date_created_gmt, lp.last_paid_at')
            ->get();

        $dormant = [];

        foreach ($rows as $row) {
            $since = CarbonImmutable::parse((string) ($row->last_paid_at ?? $row->date_created_gmt));
            $cycle = $this->cycleDays($row->billing_period, $row->billing_interval === null ? null : (int) $row->billing_interval);
            $allowed = $cycle * self::ON_HOLD_DORMANT_CYCLES;

            if ($since->diffInDays($at) > $allowed) {
                $dormant[(int) $row->id] = [
                    'dormant_from' => Clock::gmt($since->addSeconds((int) round($allowed * 86400))),
                    'cycle_days' => $cycle,
                ];
            }
        }

        // Priced at the last payment, normalised to a month by its own cycle --
        // the same way MRR prices a live subscription.
        $price = $this->lastPaymentBefore(array_keys($dormant), $end);

        foreach ($dormant as $id => $d) {
            $dormant[$id] = [
                'dormant_from' => $d['dormant_from'],
                'monthly_value' => round((float) ($price[$id] ?? 0.0) * 30 / $d['cycle_days'], 2),
            ];
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
            ->where(fn (Builder $q) => $this->whereLiveAt($q, $instant))
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
     * @return array{mrr:float, arr:float, arpu:?float, paying:int}
     */
    private function recurringRevenue(string $end): array
    {
        // The same subscribers the Active card counts ({@see isActive()}),
        // not the live status column -- otherwise MRR and the active count
        // describe two different books.
        $subs = array_filter(
            $this->lifecycleSubs(['billing_period', 'billing_interval']),
            fn (array $sub) => $this->isActive($sub, $end),
        );

        if ($subs === []) {
            return ['mrr' => 0.0, 'arr' => 0.0, 'arpu' => null, 'paying' => 0];
        }

        $price = $this->lastPaymentBefore(array_keys($subs), $end);

        $mrr = 0.0;
        $paying = 0;

        foreach ($subs as $id => $sub) {
            $amount = (float) ($price[$id] ?? 0.0);

            if ($amount <= 0.0) {
                continue; // never billed, so nothing recurring yet
            }

            $paying++;
            $mrr += $amount * (30 / $this->cycleDays(
                $sub['billing_period'] === null ? null : (string) $sub['billing_period'],
                $sub['billing_interval'] === null ? null : (int) $sub['billing_interval'],
            ));
        }

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            // Per PAYING subscriber: active subscriptions that have never been
            // billed have no price, and dividing by them understated ARPU
            // without saying so. Carried at 4 dp (the UI shows pennies) so
            // ARPU × paying = MRR to the penny, not to within 68p.
            'arpu' => $paying > 0 ? round($mrr / $paying, 4) : null,
            'paying' => $paying,
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
            ->where(fn (Builder $q) => $this->whereLiveAt($q, $start))
            ->selectRaw('s.id, s.status, '.$this->effectiveEndExpr().' as ended')
            ->get();

        if ($openers->isEmpty()) {
            return ['nrr' => null, 'grr' => null, 'start' => 0.0, 'retained' => 0.0];
        }

        $holds = $this->holdStarts();
        $timelines = $this->statusTimelines();

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
            // A subscription that went on hold inside the window stopped
            // billing, so its revenue was not retained.
            $stillRunning = $this->stillBillingAt([
                'ended' => $o->ended === null ? null : (string) $o->ended,
                'terminal' => in_array((string) $o->status, self::TERMINAL, true),
                'held' => $holds[(int) $o->id]['held'] ?? null,
                'timeline' => $timelines[(int) $o->id] ?? [],
            ], $end);
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

    /**
     * Cancelled or expired subscriptions whose end date has not happened yet,
     * as of the data timestamp.
     *
     * WooCommerce can hold a future `_schedule_end` on a cancelled row. Read
     * as an end date it put churn into a month before the month happened;
     * these are pending cancellations instead. Only meaningful at the data
     * timestamp: at any earlier instant such a subscription was simply still
     * active ({@see wasActiveAt()}).
     */
    private function scheduledCancellations(string $instant): int
    {
        if ($instant < $this->asOfGmt()) {
            return 0;
        }

        return $this->subscriptionLifecycle()
            ->whereIn('s.status', self::TERMINAL)
            ->whereRaw($this->effectiveEndExpr().' > ?', [$instant])
            ->count();
    }

    /** Subscribers who left during [start, end) — the numerator of churn. */
    private function churnedBetween(string $start, string $end): int
    {
        // Only ends that have happened: a scheduled end date in the future is
        // a pending cancellation, not churn.
        $end = $this->capAtAsOf($end);

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
        $end = $this->capAtAsOf($end);

        $orders = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereIn('status', Record::ORDER_STATUSES)
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
        $end = $this->capAtAsOf($end);

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

    /**
     * Driver-aware date bucket key for grouping the trend.
     *
     * Buckets are UK calendar periods: the GMT column is shifted to UK wall
     * time first ({@see Clock::localSql()}), so a sign-up at 00:30 BST on
     * 1 October lands in October rather than in September's last hour.
     */
    private function bucketExpression(string $granularity): string
    {
        $driver = DB::connection()->getDriverName();
        $col = Clock::localSql('date_created_gmt', $driver);

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

    /**
     * The one churn-rate formula: leavers over everyone who could have left in
     * the period (active at its start plus those who joined during it). Null
     * when nobody could have left.
     */
    private function churnRate(int $churned, int $base): ?float
    {
        return $base > 0 ? round($churned / $base * 100, 1) : null;
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
