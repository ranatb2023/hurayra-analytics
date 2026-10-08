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

### P0-3: Data freshness banner
- A strip at the top of every page shows the last import (or sync), the latest order's ID and UK time, and the dashboard order count against the source count.
- Green under 12 hours since the last order, amber under 24, red beyond. On the baseline data it is red and names 1 Oct.
- When the selected period extends past the data the strip adds "Data ends {date}. Figures after this are not real zeros."

### P0-4: "No orders" alert
- `php artisan alerts:no-orders`, scheduled hourly: when no new order has arrived for `ALERT_NO_ORDERS_HOURS` (default 12) between 08:00 and 23:00 UK time, it emails `ALERT_EMAIL_TO` and, if set, posts to `ALERT_SLACK_WEBHOOK`.
- Each incident is logged in `alert_incidents`, keyed on the last order before the silence, so it fires once per incident and re-arms only after a new order arrives.
- Requires `php artisan migrate` (new `alert_incidents` table).

### P1-1: Monthly churn rate denominator
- Churn rate = churned ÷ (active at start + new in period), for both the Real and Gross variants and for every month in the history. Exposed as `churn_base`.
- Same-month joiners who leave are now in the denominator as well as the numerator, so no month can exceed 100%. On the baseline data October 2025 goes from 165.5% to 46.6%.
- The formula is in the tooltip on both churn cards and in the Retention caption.

### P1-2: Adjusted churn including dormant on-hold
- New KPI **Churn · Adjusted** = (cancelled + expired + on-hold subscriptions that went dormant in the period) ÷ (active at start + new in period). Dormant = on hold with no successful payment for 1.5 billing cycles (45 days on a monthly plan); each is counted once, in the period it crosses that line.
- **Revenue at Risk** = monthly value of every dormant on-hold subscription + failed/pending renewal orders in the period (was renewal orders only, so £0.00 whenever none failed). Baseline: September £930.18; October to 8 Oct £679.22.
- Adjusted is shown next to Real on Overview and Retention.

### P1-3: Churn uses actual end dates, not scheduled ones
- A subscription counts as churned only when its status is cancelled/expired **and** its end date is on or before `now()` (capped at the data timestamp). This applies to the period cards, the drill-down list, tenure at churn and the monthly history.
- Cancelled/expired subscriptions with a future end date now go into **Pending cancellation** (`scheduled_cancellations`), alongside WooCommerce's pending-cancel status.
- The monthly history now runs to the data timestamp rather than stopping at the last order.
- Note: the 5 subscriptions churned in October on the baseline data are real. Their status notes show pending-cancel → cancelled on 6 Oct, before the 8 Oct import, so they are still counted.
