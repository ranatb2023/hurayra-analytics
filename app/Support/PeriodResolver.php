<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns raw filter-bar input into a concrete {@see Period}.
 *
 * Accepted input shapes (all keys optional unless noted):
 *   granularity = week|month|year|custom   (required)
 *   week:   year = 2026, month = 6, week = 1..6  (week N within that month)
 *   month:  year = 2026, month = 6   (defaults to current month)
 *   year:   year = 2026              (defaults to current year)
 *   custom: from = Y-m-d, to = Y-m-d (both required; inclusive of `to`)
 *
 * "now" is injectable so tests are deterministic.
 */
class PeriodResolver
{
    public function __construct(private readonly ?CarbonImmutable $now = null)
    {
    }

    private function now(): CarbonImmutable
    {
        return $this->now ?? CarbonImmutable::now();
    }

    public function resolve(array $input): Period
    {
        $granularity = $input['granularity'] ?? 'month';

        return match ($granularity) {
            'week' => $this->week($input),
            'month' => $this->month($input),
            'year' => $this->year($input),
            'custom' => $this->custom($input),
            default => throw new InvalidArgumentException("Unknown granularity [{$granularity}]."),
        };
    }

    /**
     * Week N *within a month*: the month is split into 7-day blocks
     * (1–7, 8–14, 15–21, 22–28, 29–end). The final block may be short and never
     * spills into the next month. Week index is clamped to the month's range.
     */
    private function week(array $input): Period
    {
        $year = (int) ($input['year'] ?? $this->now()->year);
        $month = (int) ($input['month'] ?? $this->now()->month);
        $week = max(1, (int) ($input['week'] ?? 1));

        $firstOfMonth = CarbonImmutable::create($year, $month, 1, 0, 0, 0);
        $nextMonth = $firstOfMonth->addMonth();
        $daysInMonth = $firstOfMonth->daysInMonth;
        $weeksInMonth = (int) ceil($daysInMonth / 7);

        $week = min($week, $weeksInMonth);              // clamp (e.g. "week 6" of a 5-week month)
        $startDay = ($week - 1) * 7 + 1;

        $start = $firstOfMonth->addDays($startDay - 1);
        $end = $start->addWeek();
        if ($end > $nextMonth) {
            $end = $nextMonth;                           // don't spill past the month
        }

        $label = "Week {$week} of ".$firstOfMonth->format('F Y')
            .' ('.$start->format('M j').'–'.$end->subDay()->format('j').')';

        return new Period($start, $end, 'week', $label);
    }

    private function month(array $input): Period
    {
        $year = (int) ($input['year'] ?? $this->now()->year);
        $month = (int) ($input['month'] ?? $this->now()->month);

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0);
        $end = $start->addMonth();

        return new Period($start, $end, 'month', $start->format('F Y'));
    }

    private function year(array $input): Period
    {
        $year = (int) ($input['year'] ?? $this->now()->year);

        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0);
        $end = $start->addYear();

        return new Period($start, $end, 'year', (string) $year);
    }

    private function custom(array $input): Period
    {
        if (empty($input['from']) || empty($input['to'])) {
            throw new InvalidArgumentException('Custom range requires both "from" and "to".');
        }

        $start = CarbonImmutable::parse($input['from'])->startOfDay();
        // `to` is inclusive for the user, so the half-open end is the next day's start.
        $end = CarbonImmutable::parse($input['to'])->startOfDay()->addDay();

        if ($end <= $start) {
            throw new InvalidArgumentException('"to" must be on or after "from".');
        }

        return new Period(
            $start,
            $end,
            'custom',
            $start->format('M j, Y').' – '.$end->subDay()->format('M j, Y'),
        );
    }
}
