# Changelog

All notable changes to Hurayra Analytics. One entry per audit task
(see `HURAYRA_ANALYTICS_FIXES.md` and `AUDIT_PLAN.md`).

## Unreleased

### P0-1: "Today" is the real current date
- New `App\Support\Clock`: one `now()` (server time, Europe/London) plus GMT/local conversion helpers.
- New `App\Services\DataFreshness`: the data timestamp is the last completed import or sync; snapshot metrics are measured at `min(period end, data timestamp, now)`.
- Renewal pipeline "today / in Nd" is counted in UK calendar days from the real date, not from the newest row in the data.
- The live-book "as of" label uses the capped snapshot instant, so it is never a future date.

### P0-2: Europe/London for all period bucketing
- Periods start at UK midnight; every `*_gmt` comparison converts with `Clock::gmt()`.
- Trend buckets (day/week/month/year) shift GMT to UK wall time in SQL using the tz database's own DST transitions (`Clock::localSql()`), so no fixed +1 and no dependency on MySQL tz tables.
- Month walks (subscriber history, retention, MRR), cohorts, the year picker, `subs:explain` and the dashboard's date labels all use UK dates.
- A subscription created at 23:30 GMT on 30 Sep now counts in October. On the baseline data Sep = 79 and Oct = 2: the brief's 78 misses #20506, created at 00:47 BST on 1 Sep, which moves in from August.
