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

#### Optional: the subscription end date

`status` is a **live** value — every import overwrites it, and it carries no
history. On its own it can only answer "who is active *today*", which makes past
months shrink every time somebody cancels (see
[Point-in-time subscribers](#point-in-time-subscribers)).

Include an end date in the export and that stops. Any **one** of these column
names is picked up, in this order — the first one present in the header wins:

| Priority | Columns | Meaning |
| --- | --- | --- |
| 1 | `ended_at`, `date_ended_gmt`, `end_date`, `schedule_end` | When the subscription actually finished. |
| 2 | `date_cancelled_gmt`, `cancelled_date`, `schedule_cancelled` | When cancellation was **requested** — usually earlier, since a subscription cancelled on the 1st still runs to the end of its paid term. |
| 3 | `date_modified_gmt`, `date_updated_gmt` | Last touch on the row. Rough, but close enough for a subscription cancelled and never edited since. |

**Ready-made queries live in [`database/export/`](database/export/):** run
`01-discover-schedule-keys.sql` to confirm the schedule meta keys in your
install, then export with `02-export.sql`.

It is read **only** for `shop_subscription` rows in a terminal status
(`cancelled` / `expired`) — for a live subscription a "last modified" stamp is
not an end date — and a value earlier than the sign-up date is clamped to it.
The column is entirely optional: without it the app falls back to each
subscription's last linked order (see below).

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
- **Point-in-time** — the state a subscription was in at the period **end**, not
  the state it is in today. Sign-up lower bound ignored. See below.

### Point-in-time subscribers

A subscriber count for a past month has to stay put. If March said 412 active
subscribers, it must still say 412 next year — customers who cancelled in June
were genuinely subscribed in March, and taking them back out rewrites history.

Counting `status = 'active'` does exactly that rewriting, so subscription
counts are resolved against an **end date** instead. A subscription is active at
instant `T` when:

```
date_created_gmt < T  AND  ( status = 'active'  OR  ( status is cancelled/expired  AND  end ≥ T ) )
```

`end` is the imported [`ended_at`](#optional-the-subscription-end-date) when the
CSV carries one, and otherwise **the date of the subscription's last linked
order** — the most recent point we can prove it was still being billed. The
fallback is an approximation: it can place a cancellation up to one billing
cycle early, so the dashboard shows what share of ended subscriptions have a real
end date and nudges you to add the column. Everything below is exact once it is
there.

`on-hold`, `pending` and `pending-cancel` are live states with no history in the
source data, so they are still read as-is and keep their own cards. A
subscription sitting in `pending-cancel` has not cancelled yet, so once it does,
the months before its end correctly show it as active.

### Subscriptions (`record_type = shop_subscription`)
| # | Metric | Definition | Semantics |
| --- | --- | --- | --- |
| 1 | **New Subscribers** | subscription rows created in the period | cohort |
| 2 | **Subscribers Count** | active at the period end — still running, or ended on/after it | point-in-time |
| 3 | **Pending Cancellation** | status `pending-cancel` | live status |
| 4 | **On Hold** | status `on-hold` | live status |
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
- **Monthly churn rate** — subscribers who **left during** the period ÷ subscribers **active when it opened**. A flow, so it moves month to month. `null` when nobody was active at the start (no base to lose from).
- **Lifetime churn rate** — (cancelled + expired) ÷ all subscriptions, as of the period end. A cumulative ratio that only ever climbs; kept for continuity.
- **Renewal success rate** — completed renewals ÷ all renewal orders in the period.
- **Failed renewals** / **Revenue at risk** — count and `SUM(total_amount)` of `failed`/`pending` renewal orders in the period (recoverable, involuntary churn).
- **Subscription status mix** — point-in-time donut across all six subscription statuses.
- **Subscriber history** (`GET /api/metrics/churn?months=12`) — a row per calendar month with *active at start*, *new*, *churned*, *active at end* and the churn rate. Every figure comes from sign-up and end dates, so a closed month's row never changes; a cancellation in June moves June's row and nothing before it. Also appended to the metrics CSV export.

### Reconciling a subscriber count

When the dashboard disagrees with another report, `subs:explain` puts every
subscription into exactly one labelled bucket for a given month:

```
php artisan subs:explain 2026-04          # measured at the month end, as the cards read
php artisan subs:explain 2026-04 --at=start
```

It prints what was counted and why, what was excluded and why, the totals under
each competing definition of "active", the same figure measured at the start /
end / any point in the month, and how much of the end-date timing is real rather
than inferred. A gap that matches one of the alternative definitions is a
definition mismatch; a gap that matches none of them is a data problem — almost
always missing end dates.

### Customers (needs the `customer_id` column)
- **Unique / New / Returning customers** — distinct `customer_id` on orders in the period; *new* = first-ever order falls in the period.
- **Repeat rate** — share of period customers with ≥2 orders in the period.
- **Revenue / customer** — completed revenue ÷ unique customers.
- **Top customers** — lifetime completed spend, ranked.

### One-time → Subscription (upsell list)
The named list of customers who **bought a one-off product first and later took out a subscription**, with **both dates**: the first one-time order and the sign-up date of the subscription that followed it.

| Column | Meaning |
| --- | --- |
| Customer | Billing email (or `Customer #id` when the export has no email) |
| One-time order | Date of their **first** one-time order (`order_relationship = one_time`) |
| Orders / One-time spend | How many one-time orders, and the **completed** total |
| Subscribed on | `date_created_gmt` of the first subscription starting **on/after** that one-time order |
| Gap | Days between the two — the time it took to convert |
| Sub status | Current status of that subscription |
| Sub spend | Lifetime **completed** parent + renewal revenue for that customer |

- **Identity** is the **billing email** (it's on both orders and subscriptions, and unique per guest), falling back to `customer_id` when a row has no email. `customer_id = 0` is WooCommerce's guest marker and is never used as an identity — every guest shares it.
- **Two toggles**: *Subscription after the one-time order* (on by default — the true conversion; switch it off to also list customers who subscribed **first** and bought one-off later, flagged “subscribed first”) and *Completed one-time orders only* (off by default, matching the **One-time Purchase** card which counts every status).
- Headline stats above the table: converted customers, **conversion rate** (of all one-time buyers), average time to convert, and the subscription revenue those customers went on to generate.
- The list is **lifetime** — it ignores the dashboard's date filter, so it's the full list. The table renders 25 rows at a time (*Show more*, plus a search box); **Export CSV** gives every row.
- Endpoints: `GET /api/metrics/one-time-to-subscription` (`conversions_only`, `completed_only`, `limit`) and `.../export` for the CSV.

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
#   h u r a y r a - a n a l y t i c s 
 
 