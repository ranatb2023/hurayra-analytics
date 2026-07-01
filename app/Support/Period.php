<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * An immutable, half-open date window [start, end) plus its granularity.
 *
 * All time filtering in the app uses half-open ranges: start <= date < end.
 * `end` is therefore the exclusive upper bound and doubles as the
 * "point-in-time" boundary for snapshot subscription metrics.
 */
class Period
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,   // exclusive
        public readonly string $granularity,    // week|month|year|custom
        public readonly string $label,
    ) {
    }

    /**
     * The immediately preceding comparison window.
     *
     * For calendar granularities this is the previous calendar week/month/year
     * (so Feb compares against Jan, not "31 days earlier"). For custom ranges it
     * is the equal-length window ending where this one starts.
     */
    public function previous(): self
    {
        $start = match ($this->granularity) {
            'month' => $this->start->subMonth(),
            'year' => $this->start->subYear(),
            // week (month-scoped block) + custom: equal-length preceding window.
            default => $this->start->subSeconds($this->start->diffInSeconds($this->end)),
        };

        return new self($start, $this->start, $this->granularity, 'Previous '.$this->granularity);
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start->toDateTimeString(),
            'end' => $this->end->toDateTimeString(),
            'granularity' => $this->granularity,
            'label' => $this->label,
        ];
    }
}
