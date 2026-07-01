<?php

namespace Tests\Feature;

use App\Support\PeriodResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PeriodResolverTest extends TestCase
{
    private function resolver(): PeriodResolver
    {
        // Thursday, 11 June 2026.
        return new PeriodResolver(CarbonImmutable::parse('2026-06-11 14:30:00'));
    }

    public function test_month_window_is_half_open(): void
    {
        $p = $this->resolver()->resolve(['granularity' => 'month', 'year' => 2026, 'month' => 3]);

        $this->assertSame('2026-03-01 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-04-01 00:00:00', $p->end->toDateTimeString());
        $this->assertSame('March 2026', $p->label);
    }

    public function test_year_window(): void
    {
        $p = $this->resolver()->resolve(['granularity' => 'year', 'year' => 2025]);

        $this->assertSame('2025-01-01 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-01-01 00:00:00', $p->end->toDateTimeString());
    }

    public function test_week_one_of_month(): void
    {
        $p = $this->resolver()->resolve(['granularity' => 'week', 'year' => 2026, 'month' => 3, 'week' => 1]);

        $this->assertSame('2026-03-01 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-03-08 00:00:00', $p->end->toDateTimeString());
        $this->assertStringContainsString('Week 1 of March 2026', $p->label);
    }

    public function test_week_three_of_month(): void
    {
        $p = $this->resolver()->resolve(['granularity' => 'week', 'year' => 2026, 'month' => 3, 'week' => 3]);

        $this->assertSame('2026-03-15 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-03-22 00:00:00', $p->end->toDateTimeString());
    }

    public function test_last_week_of_month_does_not_spill_over(): void
    {
        // March has 31 days -> week 5 is Mar 29–31, ending at the month boundary.
        $p = $this->resolver()->resolve(['granularity' => 'week', 'year' => 2026, 'month' => 3, 'week' => 5]);

        $this->assertSame('2026-03-29 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-04-01 00:00:00', $p->end->toDateTimeString());
    }

    public function test_week_index_is_clamped_to_month(): void
    {
        // February 2026 has 28 days -> only 4 weeks. "Week 6" clamps to week 4.
        $p = $this->resolver()->resolve(['granularity' => 'week', 'year' => 2026, 'month' => 2, 'week' => 6]);

        $this->assertSame('2026-02-22 00:00:00', $p->start->toDateTimeString());
        $this->assertSame('2026-03-01 00:00:00', $p->end->toDateTimeString());
        $this->assertStringContainsString('Week 4 of February 2026', $p->label);
    }

    public function test_custom_range_includes_to_date(): void
    {
        $p = $this->resolver()->resolve(['granularity' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame('2026-03-01 00:00:00', $p->start->toDateTimeString());
        // `to` is inclusive, so the half-open end is the next day's midnight.
        $this->assertSame('2026-04-01 00:00:00', $p->end->toDateTimeString());
    }

    public function test_previous_period_is_equal_length_and_adjacent(): void
    {
        $march = $this->resolver()->resolve(['granularity' => 'month', 'year' => 2026, 'month' => 3]);
        $prev = $march->previous();

        $this->assertSame('2026-02-01 00:00:00', $prev->start->toDateTimeString());
        $this->assertSame('2026-03-01 00:00:00', $prev->end->toDateTimeString());
    }

    public function test_custom_requires_both_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->resolve(['granularity' => 'custom', 'from' => '2026-03-01']);
    }
}
