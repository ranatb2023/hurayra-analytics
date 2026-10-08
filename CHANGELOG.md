# Changelog

All notable changes to Hurayra Analytics. One entry per audit task
(see `HURAYRA_ANALYTICS_FIXES.md` and `AUDIT_PLAN.md`).

## Unreleased

### P0-1: "Today" is the real current date
- New `App\Support\Clock`: one `now()` (server time, Europe/London) plus GMT/local conversion helpers.
- New `App\Services\DataFreshness`: the data timestamp is the last completed import or sync; snapshot metrics are measured at `min(period end, data timestamp, now)`.
- Renewal pipeline "today / in Nd" is counted in UK calendar days from the real date, not from the newest row in the data.
- The live-book "as of" label uses the capped snapshot instant, so it is never a future date.
