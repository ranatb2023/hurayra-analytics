<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The one place the app asks "what time is it" and "what day is that".
 *
 * Two clocks were in use before this existed: the server's, and the newest row
 * in the data. Relative dates ("renews in 8d") were measured from the second,
 * so a dataset a week stale shifted every renewal by a week and labelled the
 * live book as of a date that had not happened yet.
 *
 * The database stores GMT (`*_gmt` columns, app timezone UTC). The business
 * runs on UK time, so anything bucketed by day, week, month or year is first
 * converted to {@see tz()} -- with the real tz database, so BST is handled by
 * the calendar rather than by a fixed +1.
 *
 * Tests freeze time with CarbonImmutable::setTestNow().
 */
final class Clock
{
    /** The business timezone every bucket and label is expressed in. */
    public static function tz(): string
    {
        return (string) config('app.business_timezone', 'Europe/London');
    }

    /** The real current instant, in the business timezone. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    /** A stored GMT value, as a business-timezone instant. */
    public static function toLocal(string|DateTimeInterface $gmt): CarbonImmutable
    {
        $instant = $gmt instanceof DateTimeInterface
            ? CarbonImmutable::instance($gmt)
            : CarbonImmutable::parse($gmt, 'UTC');

        return $instant->setTimezone(self::tz());
    }

    /** Any instant, as the GMT string the database compares against. */
    public static function gmt(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->toDateTimeString();
    }

    /** The business-timezone calendar month ('Y-m') a stored GMT value falls in. */
    public static function monthKey(string $gmt): string
    {
        return self::toLocal($gmt)->format('Y-m');
    }

    /**
     * SQL that shifts a GMT datetime column to business-timezone wall time.
     *
     * Built from the tz database's own transitions rather than a hand-written
     * DST rule, so it is right for every year the table covers. MySQL's
     * CONVERT_TZ() would be simpler but needs the server's tz tables loaded,
     * which shared hosting rarely has, and SQLite has no equivalent at all.
     */
    public static function localSql(string $column, string $driver, int $fromYear = 2015, int $toYear = 2036): string
    {
        $zone = new DateTimeZone(self::tz());
        $transitions = $zone->getTransitions(
            (new \DateTimeImmutable("{$fromYear}-01-01", new DateTimeZone('UTC')))->getTimestamp(),
            (new \DateTimeImmutable("{$toYear}-01-01", new DateTimeZone('UTC')))->getTimestamp(),
        );

        if ($transitions === false || $transitions === []) {
            return $column;
        }

        $shift = fn (int $seconds) => $seconds === 0
            ? $column
            : ($driver === 'sqlite'
                ? "datetime({$column}, '".($seconds > 0 ? '+' : '')."{$seconds} seconds')"
                : "DATE_ADD({$column}, INTERVAL {$seconds} SECOND)");

        // The first entry is the offset in force at $fromYear; anything outside
        // the covered span falls back to it.
        $base = (int) $transitions[0]['offset'];
        $cases = [];
        $count = count($transitions);

        for ($i = 0; $i < $count; $i++) {
            $offset = (int) $transitions[$i]['offset'];

            if ($offset === $base) {
                continue;
            }

            $from = gmdate('Y-m-d H:i:s', (int) $transitions[$i]['ts']);
            $until = $i + 1 < $count ? gmdate('Y-m-d H:i:s', (int) $transitions[$i + 1]['ts']) : null;

            $cases[] = "WHEN {$column} >= '{$from}'".($until ? " AND {$column} < '{$until}'" : '')
                .' THEN '.$shift($offset);
        }

        if ($cases === []) {
            return $shift($base);
        }

        return '(CASE '.implode(' ', $cases).' ELSE '.$shift($base).' END)';
    }
}
