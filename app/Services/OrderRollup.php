<?php

namespace App\Services;

/**
 * One window of orders, pre-aggregated by (status, relationship).
 *
 * {@see MetricsService::compute()} slices the same rows a dozen ways - by
 * status, by relationship, by both, as counts and as sums. Reading them from
 * a single grouped result keeps that to one query instead of one per slice.
 *
 * A NULL relationship is a real value in this data (orders imported without
 * one), so it is keyed under an empty string rather than dropped.
 */
final class OrderRollup
{
    /** @var array<string, array<string, array{n:int, amount:float}>> status => relationship => totals */
    private array $cells = [];

    /** @param array<int, object> $rows */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $rel = (string) ($row->order_relationship ?? '');

            $this->cells[$status][$rel] = [
                'n' => (int) $row->n,
                'amount' => (float) $row->amount,
            ];
        }
    }

    /** Orders in one status, any relationship. */
    public function status(string $status): int
    {
        return array_sum(array_column($this->cells[$status] ?? [], 'n'));
    }

    /** Orders in one relationship, any status. */
    public function relationship(string $relationship): int
    {
        $n = 0;

        foreach ($this->cells as $byRel) {
            $n += $byRel[$relationship]['n'] ?? 0;
        }

        return $n;
    }

    /** Orders in one status AND one relationship. */
    public function count(string $status, string $relationship): int
    {
        return $this->cells[$status][$relationship]['n'] ?? 0;
    }

    /** Summed amount for one status AND one relationship. */
    public function amount(string $status, string $relationship): float
    {
        return $this->cells[$status][$relationship]['amount'] ?? 0.0;
    }

    /** Every order in the window, whatever its status. */
    public function total(): int
    {
        $n = 0;

        foreach ($this->cells as $byRel) {
            $n += array_sum(array_column($byRel, 'n'));
        }

        return $n;
    }

    /**
     * Completed revenue, optionally narrowed to a set of relationships.
     *
     * @param  array<int, string>|null  $relationships  null = all of them
     */
    public function revenue(?array $relationships = null): float
    {
        $cells = $this->cells['completed'] ?? [];

        if ($relationships === null) {
            return array_sum(array_column($cells, 'amount'));
        }

        $sum = 0.0;

        foreach ($relationships as $rel) {
            $sum += $cells[$rel]['amount'] ?? 0.0;
        }

        return $sum;
    }
}
