# Audit plan: Hurayra Analytics fixes

Maps every task in `HURAYRA_ANALYTICS_FIXES.md` to the files it touches. Updated
as each task lands; the "What changed" column is the record of what was done.

## Stack (from exploring the repo)

| Concern | Where |
|---|---|
| Framework | Laravel 12, PHP 8.2, Alpine.js + Chart.js (Vite), Tailwind 4 |
| Storage | `records` (orders + subscriptions, one row per `wp_wc_orders.id`), `subscription_status_changes` (order-note history), `imports` |
| CSV import / parsing | `app/Services/CsvImportService.php`, `app/Services/StatusHistoryImportService.php`, `app/Jobs/ImportCsvJob.php`, `app/Http/Controllers/UploadController.php` |
| Export queries run against WooCommerce | `database/export/*.sql` |
| Every metric | `app/Services/MetricsService.php` (+ `app/Services/OrderRollup.php`) |
| Period / date windows | `app/Support/Period.php`, `app/Support/PeriodResolver.php` |
| HTTP API | `app/Http/Controllers/MetricsController.php`, `DashboardController.php`, `KlaviyoController.php` |
| UI labels | `resources/views/dashboard/index.blade.php` (metric table at the top), `resources/views/dashboard/partials/*`, `resources/js/dashboard.js`, `resources/views/reports/client.blade.php` |
| Klaviyo | `app/Services/KlaviyoService.php`, `app/Jobs/SyncKlaviyoMetrics.php`, `config/klaviyo.php` |
| Scheduling | `routes/console.php` |

Times in the database are GMT strings (`date_created_gmt`, app timezone UTC).
All bucketing and "today" maths moves to Europe/London through one helper.

## Task map

| Task | Files | What changed |
|---|---|---|
| P0-1 Real "today" | new `app/Support/Clock.php`, new `app/Services/DataFreshness.php`, `MetricsService` (`upcomingRenewals`, snapshot capping), `MetricsController::summary`, `dashboard.js` (`periodEndNote`) | Added `Clock` (Europe/London `now()`, GMT↔local helpers) and `DataFreshness::asOf()` (now, capped at the last import/sync). `compute()` caps a period that runs past the data at `asOf` and returns `snapshot_at`/`snapshot_capped`; the "as of" label reads it. `upcomingRenewals()` measures from `Clock::now()` in UK calendar days. Tests: `RealTodayTest`; base `TestCase` freezes time. |
| P0-2 Europe/London bucketing | `Clock`, `Period`, `PeriodResolver`, `MetricsService` (`compute`, `bucketExpression`, month walks, cohorts), `MetricsController`, `config/app.php` | Periods are built at UK midnight (`PeriodResolver`) and converted to GMT for queries (`Clock::gmt`). Trend buckets shift the GMT column with `Clock::localSql()`, a CASE built from the tz database transitions (works on MySQL without tz tables, and on SQLite). Month walks, cohorts, hold-start estimates, the year picker, `subs:explain` and JS date labels use UK dates. Tests: `UkTimezoneBucketingTest`. Real data: Sep = 79, Oct = 2 (the brief says 78; #20506 at 00:47 BST on 1 Sep also moves in from August). |
| P0-3 Freshness banner | `DataFreshness`, `AppServiceProvider` (view composer), `layouts/app.blade.php`, new `partials/freshness.blade.php`, `dashboard.js` | `DataFreshness::summary()` (last import/sync, latest order id + UK time, dashboard vs source order counts, green/amber/red at 12h/24h since the last order), shared to `layouts.app` by a view composer and rendered by `partials/freshness.blade.php` inside the sticky header on every page. On the dashboard, `dataEndsNote()` adds "Data ends {date}. Figures after this are not real zeros." when the period runs past the data. Tests: `DataFreshnessTest`. |
| P0-4 No-orders alert | new `config/alerts.php`, new migration `alert_incidents`, new `app/Services/NoOrdersAlert.php`, new command `alerts:no-orders`, `routes/console.php`, `.env.example` | |
| P1-1 Churn denominator | `MetricsService` (`compute`, `churnSeries`, `retentionSeries`), blade tooltips | |
| P1-2 Adjusted churn + revenue at risk | `MetricsService`, `config/metrics.php`, blade (Overview + Retention) | |
| P1-3 Actual end dates | `MetricsService` (churn predicates capped at `asOf`, pending-cancellation count) | |
| P1-4 One "still active" | `MetricsService::isActive()`, `segmentPerformance`, `cohortValue`, blade | |
| P1-5 ARPU | `MetricsService::recurringRevenue`, blade label | |
| P1-6 Invalid order statuses | `MetricsService::ordersInWindow`, `trendMetrics`, `newCustomerCount` | |
| P1-7 Cohort median tenure | `MetricsService::cohortValue` | |
| P1-8 Cohort survival | `MetricsService::cohortRetention`, blade toggle, `dashboard.js` | |
| P1-9 2nd-payment censoring | `MetricsService::segmentPerformance`, blade | |
| P1-10 One-time conversion | `MetricsService::oneTimeToSubscription`, `MetricsController`, blade, `dashboard.js` | |
| P1-11 Renewal pipeline status | `MetricsService::upcomingRenewals`, blade, `dashboard.js`, export | |
| P2-1 … P2-8 Labels | blade, partials, `dashboard.js`, `reports/client.blade.php`, `MetricsService` (P2-3, P2-5, P2-8), `CsvImportService` (P2-5) | |
| P3-1 Channel rules | new migration + model `ChannelRule`, new `app/Services/ChannelResolver.php`, admin screen (controller + view + routes), `MetricsService::segmentPerformance` | |
| P3-2 Klaviyo clicks | `KlaviyoService`, `config/klaviyo.php`, `.env.example`, email tiles | |
| P3-3 Data Health page | new `app/Services/DataHealthService.php`, new controller + view + routes, rail link | |
| P4-1 Direct sync | `config/database.php` (`woocommerce` connection), new `app/Services/WooSyncService.php`, new command `woo:sync`, schedule, `DataFreshness` (`last_sync_at`) | |
| P5 | Not started in this pass (see "Deferred") | |

## Decisions

- **ARPU (P1-5):** MRR ÷ paying subscribers (active and billed at least once), labelled "per paying subscriber" with the count shown. Eight active subscriptions have never been billed, so dividing by all active would understate the price.
- **Data timestamp:** the last completed import or sync (whichever is later), never later than `now()`. Snapshot metrics are measured at `min(period end, data timestamp)`.
- **Dormant on-hold (P1-2):** a flat 45 days without a successful payment, as the brief specifies, configurable via `METRICS_DORMANT_ON_HOLD_DAYS`.
- **Adjusted churn numerator:** a dormant on-hold subscription is counted in the period in which it crosses the 45-day mark, so the same subscription is not re-counted every month.
- **Cohort survival (P1-8):** a subscription survives month *n* while it has not ended (cancelled/expired) by the end of that month. That keeps the curve monotone; pausing is not leaving.

## Deferred

- P5 (new features) is a separate piece of work after P0–P4.
