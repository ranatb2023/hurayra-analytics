# Changelog

All notable changes to Hurayra Analytics. One entry per audit task
(see `HURAYRA_ANALYTICS_FIXES.md` and `AUDIT_PLAN.md`).

## Unreleased

### QA pass (2026-11-23)
Definitions (agreed with the client):
- **Paid = completed + processing** (`Record::PAID_STATUSES`): revenue, AOV, customers, "has paid", failed sign-ups, LTV and renewal success all use it. "New (not completed)" is now "Not paid" (strict = pending only).
- **Failed renewals = `failed` status only.** Pending renewals are counted as "awaiting payment"; renewal success = paid ÷ (paid + failed). Count and £ value come from the same rows.
- **Churn leads with Real everywhere** (dashboard, sentence, history table, client report). Adjusted = Real + newly dormant on-hold. History table shows Real and labelled Gross.
- **Plan switches are not churn:** a subscription whose customer starts another within 14 days of it ending is left out of every churn count and flagged "not churn" on the Subscribers Lost list.
- Failed first renewal excludes manual pauses and holds earlier than one billing cycle (less 3 days) after the first payment (46 → 26).

Bugs:
- API: bad period input (reversed or blank range, `year=abc`) is a JSON 422 on every `/api/*` route, not a 500 or an HTML 200. The dashboard shows the error, blanks the cards, and disables CSV/Report until the period is valid.
- Future periods can't be selected; week mode opens on the current week; the view is kept in `?view=` and each view opens at its top; the Subscribers Lost card scrolls to its list.
- Acquisition > Product reads the parent order's product (was all "(none)"); Cycle splits by interval (monthly / every 2 months / every 6 weeks).
- Client report: no auto-print, "as of" is the real (capped) snapshot, Real churn headline.
- AOV divided paid revenue by completed orders only.
- CSV export: readable labels, Previous / Change % always filled, bookkeeping keys dropped.
- All 14 dashboard requests start together instead of waiting on the cards; the trend follows the selected period (12 months / 13 weeks to its end).

Copy and layout: UK dates throughout, chart axes "Nov 26", no dev text (env var name, Klaviyo revision, P1-10), Data Health money as £, "two cats", Email tab explains Klaviyo vs UTM attribution, cohort "Active %" vs "Survival" labelled, Growth caption corrected, small segments draw no bar, raw ad campaign ids shortened, yearly NRR note. No orphan grid slots, no duplicate status chips, Customers cards full width, labels wrap instead of truncating, key table columns visible at 1150px, campaign lists without a scroll trap and with a loader, upload history and channel rules scroll sideways, keyboard/screen-reader tooltips, heatmap contrast. New channel rules for Brave/Ecosia/Yahoo search, TikTok, and Mollie as "Payment redirect (source lost)".

### Cancellation form: why people cancelled and the retention discount
- `06-discover-cancellation-form.sql` (finds where the form stores answers) and `07-export-cancellation-form.sql` (one row per `hpf_cancel_survey` submission, with the `_hpf_retention_*` offer from the subscription and the paid renewals that spent it; plus a row for each subscription that accepted the offer with no survey).
- New `cancellation_surveys` table, filled by uploading that CSV on the Upload page (recognised by its header, re-import updates in place, deleting the import removes its rows).
- Subscribers tab: "Cancellation reasons" panel for the period (by date sent): reasons with counts and shares, satisfaction, would return, and the retention discount (accepted / declined / used on a paid renewal / still subscribed / cancelled anyway). Click a reason or the discount box for the per-person list; CSV export (`/api/metrics/cancellation-surveys[/export]`).
- The offer is a direct discount, not a coupon, so "accepted" = the survey's `retention_used` or the subscription's `_hpf_retention_accepted_at`, and "used" = a completed/processing renewal carrying `_hpf_retention_factor`.

### On Hold tagged by reason: paused manually vs payment pending
- New `subscription_status_changes.hold_reason`, parsed from the WooCommerce note on every change to on-hold: "Subscription renewal payment due: …" = `payment_due`; any other change to on-hold (bare status change, bulk edit, customer suspend) = `manual`. Both the CSV import and the direct sync fill it.
- New metrics `on_hold_manual` + `on_hold_payment_due`, always adding up to `on_hold`. The latest hold before the snapshot instant decides; with no tagged history, an unpaid (pending/failed) renewal newer than the last completed order = payment pending, else manual (marked "estimate").
- The On Hold card note, its tooltip and the client report show the split; the Data Health on-hold list tags each subscription with Reason and Reason from.
- Subscribers tab: an "On Hold by reason" panel with both counts and a split bar. Clicking a count opens the list of who is on hold (reason, on hold since, days, last payment, amount), filterable by reason, with CSV export (`/api/metrics/on-hold`, `/api/metrics/on-hold/export`). It follows the period filter.
- On the current data: 95 on hold = 44 paused manually + 51 payment pending.

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

### P1-10: One-time → subscription conversion
- A one-time purchase is now always a **completed** order worth **more than £0**. Customers whose only one-time order was free or unpaid no longer appear.
- A conversion needs the subscription to start at least one UK calendar day after the first one-time purchase. An **Include same-day sign-ups** toggle restores the old reading. It replaces the "Completed one-time orders only" toggle, which is now always on.
- Baseline: 17.3% → 17.0% (171 of 1,004).

### P1-11: Renewal pipeline uses live status
- The pipeline lists only subscriptions that are **active** now, with a next payment (WooCommerce `_schedule_next_payment`) inside the window. No on-hold subscription appears in it.
- Subscriptions whose first renewal failed (on hold, paid exactly once) move to a separate **Failed first renewal** list with the date they went on hold, and can be exported.
- Baseline, next 14 days: 99 renewals, all active (was 105 including on-hold); 23 failed first renewals worth £614 per cycle.

### P2-1: "Pending" vs "Pending cancellation"
- WooCommerce's `pending` subscription status is now labelled **Awaiting payment** everywhere; `pending-cancel` is **Pending cancellation**.
- Both are shown on both the Overview (under the subscription mix) and the Subscribers page, and the status mix always lists both, even at zero.

### P2-2: Overview renewals card headline
- The card now leads with the total and value ("99 renewals · £2,277.90" on the baseline for the next 14 days), with "42 at first renewal" as the subline. The first-renewal count alone was being read as the total.

### P2-3: Campaign lists grouped by customer
- Campaign lists have one row per customer (billing email, falling back to customer ID), with a subscription count per row. The header uses the real customer count, and the subscription total is shown beside it when they differ.
- Baseline: Cross-sell reads "136 customers (148 subscriptions)" instead of "148 customers". The CSV export lists each customer once.

### P2-4: Small samples on the Overview acquisition card
- The Overview "Where subscribers come from" card applies the same n < 30 rule as the Acquisition page. Small segments (fb at n = 13) are greyed out and marked "n too small".

### P2-5: No raw `NULL` strings in the UI
- The literal text `NULL` that MySQL writes into a CSV export is imported as an empty value, and rows already stored with it are treated as empty when displayed.
- Every missing attribution value renders as **(none)** (it was variously "NULL", "(unattributed)" and "Not recorded"). "NULL · typein" no longer appears.

### P2-6: Subscriber History "On hold / pending" column
- Renamed **Net change on hold / pending**, with a tooltip giving the formula (active at start + new − churned − active at end) and explaining that a negative value means more subscriptions came back to Active than left it.

### P2-7: Email "New Subscribers"
- The Klaviyo tile is now **New list subscribers**: newsletter list sign-ups, not subscription customers. The tooltip says so.

### P2-8: ARR reconciles with MRR
- ARR is computed from the MRR as displayed (rounded to the penny) × 12, on the cards and in the monthly series. Baseline: £52,319.16 = £4,359.93 × 12 (was £52,319.14). The tooltip gives the formula.

### P3-1: Source / channel normalisation
- New `channel_rules` table, seeded with the audit mapping (Instagram Paid / Organic, Facebook, Google Ads, Google Organic, Email, Other Search, Direct), and a **Channel rules** admin screen to add, edit and delete rules.
- Acquisition defaults to a **Channel** view. Hovering a channel shows the raw sources behind it. Unmapped sources go to **Other** and are listed on the Channel rules screen (and on Data Health). Raw columns are still available as other views.
- Totals still reconcile with P1-4. Baseline: 973 subscriptions, 243 active, in 9 channels.
- Requires `php artisan migrate`.

### P3-2: Klaviyo click tracking
- The Klaviyo field mapping (`clicks_unique`, `opens_unique`, `delivered`) was already correct, and earlier months do show clicks (0.2–1.1%). The 0% came from precision: rates were rounded and stored to one decimal place, so a 0.04% campaign click rate was saved as 0.0%.
- Rates are now kept to two decimals, and the counts behind them are stored and shown under the tile ("3 of 8,000 delivered"), so a real zero can be told apart from a small rate.
- The API revision default moves from 2025-01-15 to **2026-07-15** (current GA). No revision in between changed the reports, list, metrics or account endpoints this app calls. **A local `.env` that sets `KLAVIYO_REVISION=2025-01-15` overrides this and should be updated.**
- Open-rate tooltips say the figure is inflated by Apple Mail privacy.
- Requires `php artisan migrate`; the next sync fills the new columns.

### P3-3: Data Health page
- New **Data Health** page (sidebar). Each check has a count, a list and a CSV export:
  - orders / subscriptions with an invalid status for their type;
  - £0 completed orders;
  - customers with multiple active subscriptions to the same product;
  - unmapped traffic sources;
  - on-hold subscriptions by age (0–15, 16–30, 31–45, 45+ days);
  - dashboard vs source row counts per status.
- Nothing on the page modifies data. Baseline: 3 invalid-status orders, 8 £0 completed orders, 14 duplicate active subscriptions, 15 unmapped sources, 55 on hold (18 / 15 / 10 / 12 by age); per-status counts match except the excluded wc-active and trash orders.

### P4-1: Direct sync from WooCommerce
- New read-only `woocommerce` database connection, configured by `WC_DB_HOST`, `WC_DB_PORT`, `WC_DB_NAME`, `WC_DB_USER`, `WC_DB_PASS` and `WC_TABLE_PREFIX` (default `wp_`). **Use a dedicated MySQL user with SELECT only, never the WordPress admin user.** Each session is also set `READ ONLY`.
- `php artisan woo:sync` (scheduled every 15 minutes when configured) reads orders and subscriptions with `date_updated_gmt` after the last sync, plus new status notes, using the same queries as `database/export/04` and `05`. Rows are upserted exactly as a CSV upload would write them. `--full` re-reads everything.
- `last_sync_at` is stored and shown in the freshness banner. With the sync connected, the banner's source order count and the Data Health status reconciliation come live from WooCommerce.
- CSV upload is unchanged and remains the fallback.
- Requires `php artisan migrate` (new `sync_state` table). Not yet exercised against the live database. Run `php artisan woo:sync --full` once after setting the credentials.

### Definition of done: KPI tooltips
- Every KPI card and chip (40) and the Klaviyo delivery tiles now has a tooltip stating its exact formula. A test fails if a card is added without one.

### Every table follows the period filter
- The period filter used to update only the cards, the trend chart and Klaviyo. Every table was loaded once and then ignored it. Now:
  - **Acquisition**: subscribers who signed up in the period, with an **All time** toggle (the view that reconciles with Overview active);
  - **Cohort value / retention**: the 12 cohorts up to the selected month;
  - **Subscriber History and its charts** (growth, churn, NRR, MRR): the 12 months ending at the selected month;
  - **Top customers**: completed spend in the period;
  - **One-time → subscription**: customers whose first one-off purchase was in the period (CSV export too).
- The renewal pipeline, failed first renewals and campaign lists are work lists "as of today". They are labelled as not affected by the period filter.
- Every table heading names the period it shows.
