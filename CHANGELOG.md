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

### P1-4: One definition of "Still active"
- One shared rule, `MetricsService::isActive($sub, $asOf)`: status `active` at the data timestamp (from the imported status history when there is one). Overview, Acquisition and Cohorts all use it.
- Acquisition no longer drops small segments. They are folded into an **Other** row, and the table has an "All subscriptions" total.
- Cohort value adds an **Earlier** row for cohorts older than the 12 shown.
- Baseline: Overview 243, Acquisition 243, Cohorts 243 (were 243, 239 and 241).

### P1-5: ARPU label and calculation
- ARPU is **per paying subscriber**: MRR ÷ subscribers who are active and have been billed at least once. The card shows the count ("235 paying of 243 active").
- ARPU is carried at 4 decimal places (displayed in pennies), so ARPU × paying subscribers = MRR to the penny. Baseline: £18.5529 × 235 = £4,359.93.
- MRR is built from the same subscribers as the Active card (`isActive()`), not the live status column.

### P1-6: Invalid order statuses
- Orders count only with a real order status (completed, processing, pending, on-hold, cancelled, failed, refunded). The three `shop_order` rows with the subscription status `wc-active` (#5209, #5230, #6049) are excluded from every metric and trend, as WP Admin excludes them. They are not modified.
- The dashboard order count is now 3,987, matching WP Admin "All" (the source holds 3,990). The rows are listed on the Data Health page (P3-3).

### P1-7: Cohort median tenure
- Tenure = (end date, or now if still running) − start date, for every subscription in the cohort; the median is over all of them. Only the leavers were counted before.
- Baseline: the Aug 26 cohort (56% still active) goes from a median of 5 days to 41.

### P1-8: Cohort retention is survival
- Cohort retention at M(n) = % of the cohort not cancelled or expired at the end of month n. On hold is not leaving. The curve can only stay flat or fall. The month in progress is measured at the data timestamp and shown in italics; months that have not happened are blank.
- The old "% with a completed order in month n" grid is kept behind an **Order activity** toggle. It shows billing rhythm, not retention: two-monthly and six-weekly plans skip months.
- Baseline: no cohort's curve rises. The February 2026 cohort reads M3 25.0% → M4 21.9% (activity: 17.7% → 20.8%).

### P1-9: "Reached 2nd payment" right-censoring
- The denominator is now only the subscriptions whose 2nd payment was due by now: the scheduled next payment for a sub still on its first cycle, otherwise sign-up + one billing cycle. A sub that signed up yesterday is excluded rather than counted as a failure.
- Each row shows "n eligible". The confidence range and the "n too small" flag use that n.
- Baseline: Klaviyo, mostly recent sign-ups, is judged on 19 of its 30 subscriptions: 42.1%.
