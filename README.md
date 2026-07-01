# Hurayra Analytics

Upload a single CSV — a MySQL export of the WooCommerce **HPOS** `wp_wc_orders`
table (which holds both **orders** and **subscriptions**) — and get a filterable
metrics dashboard. One file in, every metric out.

Built with **Laravel 12**, **MySQL** (SQLite for tests), **Blade + Tailwind 4**,
**Alpine.js + Chart.js** (live filtering via a JSON API, no full reloads), and a
**queued, chunked** importer.

---

## Quick start

```bash
composer install
npm install

# .env is preconfigured for MySQL db `hurayra-analytics`. Adjust if needed, then:
php artisan migrate

# Generate + import representative sample data (≈497 rows):
php artisan db:seed --class=SampleDataSeeder

npm run build           # or `npm run dev` while developing

# Run the app + a queue worker (uploads are processed on the queue):
php artisan serve
php artisan queue:work  # in a second terminal — required for uploads to import
```

Open <http://localhost:8000>. Or run everything at once with `composer dev`
(server + queue + logs + vite).

Run the tests:

```bash
php artisan test
```

---

## The CSV format

There is **one** accepted format. The header is validated on upload and a
mismatch is rejected with a clear message. Expected columns (order-independent):

```
id, record_type, status, date_created_gmt, total_amount, subscription_id, order_relationship, billing_email
```

| Column | Meaning |
| --- | --- |
| `id` | WooCommerce row id. **Upsert key** — re-uploading the same file never duplicates. |
| `record_type` | `shop_order` or `shop_subscription`. The master switch for which metrics a row feeds. |
| `status` | Stored with a `wc-` prefix in WooCommerce; **normalised on import** (prefix stripped, lowercased). |
| `date_created_gmt` | Datetime used for **all** time filtering (sign-up date for subs, order date for orders). |
| `total_amount` | Order / recurring value. Blank / non-numeric → `0`. |
| `subscription_id` | The subscription a row belongs to. Orders link to their subscription via this; one-time orders leave it empty. |
| `order_relationship` | `subscription` \| `parent` \| `renewal` \| `one_time`. Separates subscription orders from one-time orders. |
| `billing_email` | Trimmed + lowercased on import. |

### Import behaviour
- **Streamed** line-by-line via `SplFileObject` (never loads the whole file into memory), upserted in chunks of 500.
- **Normalised**: `wc-` stripped from status, emails trimmed/lowercased, dates parsed defensively (`0000-00-00` and junk → `null`), blank/NaN numerics → `0`.
- **Idempotent**: upsert on `id`. Importing the same file twice leaves the row count unchanged.
- Rows with no `id` or no `record_type` are **skipped** and sampled into the import's `error_log`; the upload history shows imported/skipped counts per batch, and batches can be deleted.

---

## Metric definitions

Every metric respects the active date filter on `date_created_gmt`. Two time
semantics are used (confirmed with the product owner):

- **Cohort** — events inside the window: `start ≤ date_created_gmt < end`.
- **Snapshot** — current status as of the period **end**: `date_created_gmt < end`,
  sign-up lower bound ignored. Answers “how many are in state X right now”.

### Subscriptions (`record_type = shop_subscription`)
| # | Metric | Definition | Semantics |
| --- | --- | --- | --- |
| 1 | **New Subscribers** | subscription rows created in the period | cohort |
| 2 | **Subscribers Count** | status `active` | snapshot |
| 3 | **Pending Cancellation** | status `pending-cancel` | snapshot |
| 4 | **On Hold** | status `on-hold` | snapshot |
| 5 | **Cancelled without Purchase** | signed up in the period, status `cancelled`, **and 0** linked order rows with status `completed` | cohort + lifetime link |
| 6 | **Cancelled with Purchase** | signed up in the period, status `cancelled`, **and ≥1** linked completed order | cohort + lifetime link |

> The two "Cancelled" cards use the **cohort** rule (sign-up date in the period) so they track the selected week/month, unlike the *active / on-hold / pending-cancel* snapshots which answer "how many right now". The linked completed order is still matched across all time.

Linking is done **at query time**: order rows where `subscription_id = <the
subscription's id>`. A linked completed order counts **whenever** it happened
(lifetime). Never baked in at import.

### Orders (`record_type = shop_order`)
| # | Metric | Definition |
| --- | --- | --- |
| 7 | **One-time Purchase** | `order_relationship = one_time` |
| 8 | **Subscription Purchases** | `order_relationship IN (parent, renewal)` |
| 9 | **Renewal Purchases** | `order_relationship = renewal` |
| 10 | **Completed** | status `completed` |
| 11 | **New (not completed)** | status `!= completed`; the detail panel breaks it down (cancelled / failed / refunded / pending / processing). A **strict toggle** narrows it to `pending + processing` only. |

All order metrics are **cohort** (events in the window).

### Supporting totals
- **Total revenue** — `SUM(total_amount)` over **completed** orders in the period.
- **Average order value** — revenue ÷ completed-order count.
- **Active : Cancelled ratio** — active vs cancelled subscriptions (snapshot).
- **Revenue split** — subscription (`parent`+`renewal`) vs one-time, over completed orders.

### Retention & churn
- **Churn rate** — (cancelled + expired) ÷ all subscriptions (snapshot).
- **Renewal success rate** — completed renewals ÷ all renewal orders in the period.
- **Failed renewals** / **Revenue at risk** — count and `SUM(total_amount)` of `failed`/`pending` renewal orders in the period (recoverable, involuntary churn).
- **Subscription status mix** — snapshot donut across all six subscription statuses.

### Customers (needs the `customer_id` column)
- **Unique / New / Returning customers** — distinct `customer_id` on orders in the period; *new* = first-ever order falls in the period.
- **Repeat rate** — share of period customers with ≥2 orders in the period.
- **Revenue / customer** — completed revenue ÷ unique customers.
- **Top customers** — lifetime completed spend, ranked.

### Cohort retention
Of the subscribers who signed up in month *M*, the % with a completed order (parent or renewal) in month *M+k*, for k = 0…6. Rendered as a heatmap (M0 = sign-up month).

> **Note:** `customer_id` and `parent_order_id` arrive as "extra" columns in the real export — they're stored when present (extra columns are otherwise ignored). If you imported a file *before* upgrading, **re-upload it** to populate `customer_id` and light up the Customers panels.

### Reporting
- A copy-ready **period summary** sentence, **Export CSV** of the current filtered metrics, and **Print / PDF** (chrome is hidden in print).
- Headline **sparklines** (12-month) and a **revenue-split donut**.

### Data limitation — MRR
True **MRR** needs each subscription's billing interval (monthly/yearly), which isn't in the export. We surface order-based revenue and "revenue at risk" instead. Add a `billing_period` column to the CSV if you want real MRR and I'll wire it up.

---

## Filtering

A filter bar drives everything via two JSON endpoints
(`/api/metrics/summary`, `/api/metrics/trend`); cards and chart update without a
reload.

- **Granularity**: Week / Month / Year / Custom range.
- **Week**: this / last week. **Month**: month + year. **Year**: year dropdown.
- **Custom**: from / to (inclusive of the `to` day).
- **Compare to previous period** — previous calendar week/month/year (or the
  equal-length preceding window for custom); each card shows the % change.
- **Trend chart** (line or bar) plots a chosen metric over time, bucketed by the
  selected granularity.

---

## Klaviyo email-performance module

A self-contained module (it does **not** touch the CSV importer) that pulls live
email stats from the Klaviyo Reporting API on a schedule and shows six tiles that
respect the dashboard's date filter: **Delivery Rate, Open Rate, Click Rate,
Revenue (£), Conversions, Subscribers**.

### Setup
1. Create a Klaviyo **private API key** with scopes: `campaigns:read`, `flows:read`, `metrics:read`, `lists:read`.
2. In `.env`:
   ```
   KLAVIYO_API_KEY=pk_xxx
   KLAVIYO_LIST_ID=YourNewsletterListId
   KLAVIYO_REVISION=2025-01-15
   ```
3. Populate data:
   - One-off / no worker needed: `php artisan klaviyo:sync` (syncs current week/month/year synchronously).
   - Ongoing: run a queue worker (`php artisan queue:work`) **and** the scheduler (`php artisan schedule:work`) — the hourly job refreshes the current buckets, and on-demand syncs (custom ranges, "Refresh now") run on the worker.

> Find your **List ID** in Klaviyo → **Audience → Lists & Segments** → open the list → **Settings → List ID** (a short id like `SRb6Ju`). It must be a **List**, not a Segment.

### How it works
- **Never calls Klaviyo on page load.** A scheduled job (`SyncKlaviyoMetrics`, hourly) fetches a snapshot per period bucket (current week/month/year) into `klaviyo_metrics`; the dashboard reads that table.
- Changing the filter shows the matching stored snapshot; a **custom range with no snapshot** dispatches a one-off sync and the tiles show a *syncing* state (auto-polled). The **Refresh now** button dispatches a sync for the active bucket.
- The first five tiles come from one `POST /api/campaign-values-reports/` call; **rates are recomputed from summed counts (weighted by delivered/recipients), not averaged**.
- **New Subscribers** counts profiles whose list **join date (`joined_group_at`) falls in the selected period** (paged through `GET /api/lists/{id}/profiles/` with a date filter) — i.e. new email subscribers gained that week/month/year, not the total list size.
- **Subscriptions from Email / Renewals from Email** run the campaign-values report with `conversion_metric_id` set to **`WC Subscription Created`** / **`WC Subscription Renewal`** (the WooCommerce Subscriptions → Klaviyo events), so they show how many subscription purchases (and £ value) were **attributed to campaigns** in the period. If your store doesn't fire those events, the tiles show "—".
- **Automated Flows** — the section is split into **Campaigns** and **Automated Flows**. The flow tiles mirror the campaign ones (delivery/open/click, revenue, orders, subscriptions, renewals) but use the **flow-values report** (`POST /api/flow-values-reports/`) for welcome series, abandoned-cart, etc.
- Each bucket now makes up to six report calls (campaigns + flows × order/subscription/renewal metrics). That's heavier on Klaviyo's tightly-throttled reporting endpoints, so a sync can take a minute or two — the retry/backoff (honouring `Retry-After`) absorbs it, and the job timeout is 600s. Keep `queue:work` running; the dashboard auto-polls while a sync is in flight.
- `KlaviyoService` centralises auth + the pinned `revision` header and retries 429/5xx with exponential backoff (honouring `Retry-After`).
- If the key/list is missing or a call fails, the tiles render a clear **"not connected" / "sync failed"** state with the error — never a broken page.

### Caveats (surfaced in the UI / here)
- **Apple Mail Privacy Protection** auto-opens messages, inflating **Open Rate** — there's a tooltip on that tile noting it may read high.
- Report timeframes use the **account timezone** (auto-detected from `GET /api/accounts/`, cached; override with `KLAVIYO_TIMEZONE`) so day/month boundaries line up with the Klaviyo UI. Needs the key to also allow `accounts:read` for auto-detect; otherwise set `KLAVIYO_TIMEZONE` explicitly.
- The API attributes stats by **event date**, while Klaviyo's in-app reports use **send date**, so these tiles can still differ slightly from the Klaviyo UI.
- **Pinned revision:** Klaviyo deprecates API revisions on a ~yearly cycle. The active revision is shown next to the tiles and set via `KLAVIYO_REVISION` / `config/klaviyo.php` — **bump that one value (and re-test) when notified.**

## Architecture

| Piece | Location |
| --- | --- |
| Migrations | `database/migrations` — `imports`, `records` |
| Models | `app/Models/Import.php`, `app/Models/Record.php` |
| CSV import (validate + stream + normalise + upsert) | `app/Services/CsvImportService.php` |
| Queued importer | `app/Jobs/ImportCsvJob.php` |
| Metrics (all 11 + totals + trend + compare) | `app/Services/MetricsService.php` |
| Date windows | `app/Support/Period.php`, `app/Support/PeriodResolver.php` |
| Controllers | `app/Http/Controllers` — `Dashboard`, `Metrics`, `Upload` |
| Frontend | `resources/js/dashboard.js`, `resources/views` |
| Sample data | `app/Support/SampleCsvGenerator.php`, `php artisan sample:csv`, `SampleDataSeeder` |
| Tests | `tests/Feature` — metrics numbers, period windows, import normalisation/idempotency |

The metrics logic is isolated from controllers (`MetricsService` is pure — no
request/session access) and covered by feature tests with a fully enumerated
dataset, so the numbers are provably correct.
#   h u r a y r a - a n a l y t i c s  
 