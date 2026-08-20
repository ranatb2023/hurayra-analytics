<?php

namespace App\Console\Commands;

use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Explains the active-subscriber count for one month: which subscriptions were
 * counted, which were left out, and on what grounds.
 *
 * Written for reconciling the dashboard against an external report. The
 * headline number is a single integer with no way to see inside it; this puts
 * every subscription in exactly one labelled bucket so a discrepancy can be
 * traced to the rule that caused it rather than guessed at.
 */
class ExplainSubscribers extends Command
{
    protected $signature = 'subs:explain
        {month : The month to explain, as YYYY-MM (e.g. 2026-04)}
        {--at=end : Measure at the month "end" (how the dashboard cards read), its "start", or a specific YYYY-MM-DD}
        {--daily : Also print the count for every day of the month}
        {--target= : A figure to reconcile against; every count that matches it is flagged}';

    protected $description = 'Break down the active-subscriber count for a month into included/excluded buckets';

    public function handle(): int
    {
        $month = (string) $this->argument('month');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Month must look like 2026-04.');

            return self::FAILURE;
        }

        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $at = (string) $this->option('at');

        $instant = match (true) {
            $at === 'start' => $start,
            $at === 'end' => $start->addMonth(),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) => CarbonImmutable::parse($at)->startOfDay(),
            default => null,
        };

        if ($instant === null) {
            $this->error('--at must be "start", "end", or a date like 2026-04-15.');

            return self::FAILURE;
        }

        $target = $this->option('target') === null ? null : (int) $this->option('target');
        $t = $instant->toDateTimeString();

        $this->newLine();
        $this->line("Active subscribers as of <options=bold>{$t}</> ({$month}, measured at the {$at})");
        $this->newLine();

        $subs = $this->subscriptions();

        $buckets = [
            'included' => [],
            'excluded' => [],
        ];

        foreach ($subs as $s) {
            [$group, $label] = $this->classify($s, $t);
            $buckets[$group][$label][] = $s;
        }

        $this->renderGroup('COUNTED', $buckets['included'], 'green');
        $this->renderGroup('NOT COUNTED', $buckets['excluded'], 'yellow');

        $counted = array_sum(array_map('count', $buckets['included']));
        $this->newLine();
        $this->line("Dashboard figure: <options=bold;fg=green>{$counted}</>");

        $this->renderAlternatives($subs, $t, $counted, $target);
        $this->renderMoments($subs, $start, $target);

        if ($this->option('daily')) {
            $this->renderDaily($subs, $start, $target);
        }

        $this->renderDataQuality($subs, $t);

        return self::SUCCESS;
    }

    /** Every subscription plus the date of its most recent linked order. */
    private function subscriptions(): array
    {
        $lastOrder = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('subscription_id')
            ->whereNotNull('date_created_gmt')
            ->selectRaw('subscription_id, MAX(date_created_gmt) as last_order_at, COUNT(*) as orders')
            ->groupBy('subscription_id');

        return DB::table('records as s')
            ->leftJoinSub($lastOrder, 'lo', 'lo.subscription_id', '=', 's.id')
            ->where('s.record_type', 'shop_subscription')
            ->selectRaw('s.id, s.status, s.customer_id, s.billing_email, s.date_created_gmt as created, s.ended_at, lo.last_order_at, lo.orders')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Put one subscription in exactly one bucket. Order matters: the first
     * matching rule wins, mirroring how MetricsService evaluates them.
     *
     * @return array{0: string, 1: string}
     */
    private function classify(array $s, string $t): array
    {
        $terminal = in_array((string) $s['status'], Record::TERMINAL_SUBSCRIPTION_STATUSES, true);
        $end = $s['ended_at'] ?? $s['last_order_at'];

        if ($s['created'] === null) {
            return ['excluded', 'no sign-up date on the row'];
        }

        if ((string) $s['created'] >= $t) {
            return ['excluded', 'signed up on/after this date'];
        }

        if ((string) $s['status'] === 'active') {
            return ['included', 'status "active" (still running today)'];
        }

        if (! $terminal) {
            return ['excluded', 'status "'.$s['status'].'" — a live state with no history in the CSV'];
        }

        if ($s['ended_at'] !== null) {
            return (string) $s['ended_at'] >= $t
                ? ['included', 'ended later, per the imported end date']
                : ['excluded', 'ended before this date, per the imported end date'];
        }

        if ($s['last_order_at'] === null) {
            return ['excluded', 'ended, no end date AND no linked orders — invisible to the fallback'];
        }

        return (string) $s['last_order_at'] >= $t
            ? ['included', 'ended, no end date — last linked order is later (fallback)']
            : ['excluded', 'ended, no end date — last linked order is earlier (fallback may be too early)'];
    }

    private function renderGroup(string $title, array $buckets, string $colour): void
    {
        $total = array_sum(array_map('count', $buckets));
        $this->line("<fg={$colour};options=bold>{$title}</> ({$total})");

        if ($buckets === []) {
            $this->line('  <fg=gray>none</>');
            $this->newLine();

            return;
        }

        uasort($buckets, fn ($a, $b) => count($b) <=> count($a));

        foreach ($buckets as $label => $rows) {
            $this->line(sprintf('  %6d  %s', count($rows), $label));
        }
        $this->newLine();
    }

    /**
     * What the number would be under the other plausible definitions of
     * "active", so an external report can be matched to the rule behind it.
     */
    private function renderAlternatives(array $subs, string $t, int $counted, ?int $target): void
    {
        $started = $this->started($subs, $t);

        $withStatus = fn (array $statuses) => count(array_filter(
            $started,
            fn ($s) => in_array((string) $s['status'], $statuses, true),
        ));

        $this->newLine();
        $this->line('<options=bold>If your report counts something else, one of these should match it:</>');
        $this->newLine();

        $this->table(
            ['Definition of "active"', 'Count'],
            [
                ['active only (what the dashboard shows)', $this->mark($counted, $target)],
                ['+ pending-cancel (cancelling, but still a subscriber)', $this->mark($counted + $withStatus(['pending-cancel']), $target)],
                ['+ pending-cancel + on-hold', $this->mark($counted + $withStatus(['pending-cancel', 'on-hold']), $target)],
                ['every subscription that had started and not yet ended', $this->mark($this->startedNotEndedAt($subs, $t), $target)],
                ['every subscription that had started, whatever its state', $this->mark(count($started), $target)],
            ],
        );

        // A customer with two subscriptions is two rows but one subscriber.
        $keys = [];
        foreach ($started as $s) {
            $key = $s['billing_email'] ?: ((int) $s['customer_id'] > 0 ? 'cid:'.$s['customer_id'] : null);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }
        $this->line(sprintf(
            '  Distinct customers behind those %d subscriptions: <options=bold>%d</>',
            count($started),
            count($keys),
        ));
    }

    /**
     * The same status rule read at three different moments. "April's
     * subscribers" is ambiguous: a customer who cancelled on the 15th was a
     * subscriber during April but not at the end of it, and a report built on
     * either reading is defensible.
     */
    private function renderMoments(array $subs, CarbonImmutable $start, ?int $target): void
    {
        $startS = $start->toDateTimeString();
        $endS = $start->addMonth()->toDateTimeString();

        // Active at any point in the window: had started before it closed, and
        // had not already ended when it opened.
        $anyPoint = count(array_filter($subs, function ($s) use ($startS, $endS) {
            if ($s['created'] === null || (string) $s['created'] >= $endS) {
                return false;
            }
            if ((string) $s['status'] === 'active') {
                return true;
            }
            if (! in_array((string) $s['status'], Record::TERMINAL_SUBSCRIPTION_STATUSES, true)) {
                return false;
            }
            $end = $s['ended_at'] ?? $s['last_order_at'];

            return $end !== null && (string) $end >= $startS;
        }));

        $this->newLine();
        $this->line('<options=bold>Or if your report measures at a different moment:</>');
        $this->newLine();

        $this->table(
            ['When "active" is measured', 'Count'],
            [
                ['at the start of the month', $this->mark($this->activeCountAt($subs, $startS), $target)],
                ['at the end of the month (what the dashboard shows)', $this->mark($this->activeCountAt($subs, $endS), $target)],
                ['at any point during the month', $this->mark($anyPoint, $target)],
            ],
        );
    }

    /**
     * The count on every single day of the month, under both the strict rule and
     * the "started and not yet ended" rule.
     *
     * A report snapshotted part-way through the month lands between the opening
     * and closing figures, and no month-boundary reading will ever reproduce it.
     * Scanning day by day finds the date it was actually taken.
     */
    private function renderDaily(array $subs, CarbonImmutable $start, ?int $target): void
    {
        $rows = [];

        for ($day = $start; $day <= $start->addMonth(); $day = $day->addDay()) {
            $t = $day->toDateTimeString();
            $rows[] = [
                $day->format('D j M Y'),
                $this->mark($this->activeCountAt($subs, $t), $target),
                $this->mark($this->startedNotEndedAt($subs, $t), $target),
            ];
        }

        $this->newLine();
        $this->line('<options=bold>Day by day (measured at 00:00 on each date):</>');
        $this->newLine();

        $this->table(['Date', 'active only', 'started & not ended'], $rows);
    }

    /** Subscriptions that had signed up before $t. */
    private function started(array $subs, string $t): array
    {
        return array_filter($subs, fn ($s) => $s['created'] !== null && (string) $s['created'] < $t);
    }

    /** The dashboard's own rule, evaluated at an arbitrary instant. */
    private function activeCountAt(array $subs, string $t): int
    {
        return count(array_filter($subs, fn ($s) => $this->classify($s, $t)[0] === 'included'));
    }

    /**
     * Everything that had started and had not yet ended: terminal statuses judged
     * on their end date, every other status taken as still running. This is the
     * reading that keeps on-hold and pending-cancel in the count.
     */
    private function startedNotEndedAt(array $subs, string $t): int
    {
        return count(array_filter($this->started($subs, $t), function ($s) use ($t) {
            if (! in_array((string) $s['status'], Record::TERMINAL_SUBSCRIPTION_STATUSES, true)) {
                return true;
            }

            $end = $s['ended_at'] ?? $s['last_order_at'];

            return $end === null || (string) $end >= $t;
        }));
    }

    /** Render a count, flagged when it reconciles with --target. */
    private function mark(int $count, ?int $target): string
    {
        return $target !== null && $count === $target
            ? "<fg=green;options=bold>{$count}  <-- matches your report</>"
            : (string) $count;
    }

    private function renderDataQuality(array $subs, string $t): void
    {
        $ended = array_filter($subs, fn ($s) => in_array((string) $s['status'], Record::TERMINAL_SUBSCRIPTION_STATUSES, true));
        $withEndDate = array_filter($ended, fn ($s) => $s['ended_at'] !== null);
        $noOrders = array_filter($ended, fn ($s) => $s['last_order_at'] === null);

        $this->newLine();
        $this->line('<options=bold>Data quality</>');
        $this->line(sprintf('  %6d  subscriptions in total', count($subs)));
        $this->line(sprintf('  %6d  ended (cancelled/expired)', count($ended)));
        $this->line(sprintf(
            '  %6d  of those carry a real end date (%s)',
            count($withEndDate),
            count($ended) > 0 ? round(count($withEndDate) / count($ended) * 100, 1).'%' : 'n/a',
        ));
        $this->line(sprintf('  %6d  of those have no linked orders either — no end date can be inferred', count($noOrders)));

        if (count($withEndDate) < count($ended)) {
            $this->newLine();
            $this->line('  <fg=yellow>Add an end-date column to the CSV export (ended_at, date_cancelled_gmt,</>');
            $this->line('  <fg=yellow>end_date, schedule_end, …) to replace the last-order guess with the real date.</>');
        }
    }
}
