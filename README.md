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

#### Optional: marketing attribution and billing cycle

`03-export-with-attribution.sql` supersedes `02-export.sql`. Same rows and same
first ten columns, so it stays importable; every extra column is optional and a
file exported before they existed still imports unchanged.

| Column | Source | Why |
| --- | --- | --- |
| `attribution_type`, `utm_source`, `utm_medium`, `utm_campaign`, `device_type` | WooCommerce Order Attribution (core since Woo 8.5) | Ties retention to the spend that bought it. `source_type = typein` means direct — treating that as a channel win is how direct ends up credited with everything. |
| `billing_period`, `billing_interval` | `_billing_period` / `_billing_interval` | The real renewal length. **Not uniformly monthly** — about 73% monthly, 16% two-monthly, 8% six-weekly. Any "overdue" rule on a fixed day count is wrong for a quarter of the book. |
| `next_payment_at` | `_schedule_next_payment` | The scheduled renewal. Makes {@see MetricsService::upcomingRenewals()} a dated list rather than an inference. |
| `coupon_code`, `discount_amount` | `wp_wc_order_coupon_lookup` | Whether discounted acquisitions retain worse. |
| `primary_product` | `wp_wc_order_product_lookup` | Highest-revenue line on the order, so retention splits by product without a second export. |

Derived from these:

- **Segment performance** (`GET /api/metrics/segments?dimension=utm_source`) — subscriptions, never-paid %, reached-second-payment %, still active, revenue and LTV per segment. `dimension` is whitelisted against `MetricsService::SEGMENT_DIMENSIONS` because the column name is interpolated into the SQL and cannot be a bound parameter.
- **Renewal pipeline** (`GET /api/metrics/renewals?days=14`, plus `/export`) — live subscriptions renewing in the window, flagging those at their **first** renewal: roughly half of all payers never reach a second payment, so that is the one worth intervening on.
- **Dormant on-hold** is now measured in **billing cycles** (`ON_HOLD_DORMANT_CYCLES = 1.5`), not a fixed 45 days.

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
| 2 | **Subscribers Count** | status `active` at the period end — still running, or ended on/after it | point-in-time |
| 3 | **Pending Cancellation** | status `pending-cancel` | live status |
| 4 | **On Hold** | status `on-hold` | live status |

> **Active Subscribers counts `active` only.** `on-hold` and `pending-cancel`
> are separate cards and are deliberately **not** folded into it. A subscription
> suspended for a failed payment has not ended, so a report that counts it as a
> subscriber is equally defensible and will read higher — this is a definition
> difference, not a bug. The card is captioned with the exact date it is
> measured at, because the figure also moves day to day within a period.
> `subs:explain` (below) shows precisely which subscriptions separate the two.
>
> One known limitation follows from this: `on-hold`, `pending` and
> `pending-cancel` have no history in the source data, so a subscription that is
> on hold *today* is left out of *every* past month, including months it spent
> as `active`. Only `cancelled`/`expired` carry an end date that can be read
> backwards.
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
- **Lifetime churn rate** (`churn_rate`) and **Active : Cancelled** (`active_cancelled_ratio`) — cumulative ratios that only ever climb. Still computed and exported, but no longer shown on the dashboard: they cannot be acted on, and sitting beside a monthly flow they invite reading one as the other.
- **Renewal success rate** — completed renewals ÷ all renewal orders in the period.
- **Failed renewals** / **Revenue at risk** — count and `SUM(total_amount)` of `failed`/`pending` renewal orders in the period (recoverable, involuntary churn).
- **Tenure at churn** — the same subscribers the monthly churn rate counts, bucketed by how long they had been subscribed (0–30 / 31–60 / 61–90 / 91–180 / 181+ days) plus the median. The rate says *how many* left; this says *who* — customers who never settled in, or long-standing ones drifting away. Tenure runs from sign-up to the same end date churn uses, so the bucket total always equals `churned_in_period`. Follows the active date filter, and is included in the CSV export and the client report.
- **Subscribers lost** (`churned_in_period`) — the flow card in the Subscriptions section: everyone whose subscription **ended** in the period, whenever they signed up. Distinct from *Cancelled with/without Purchase*, which are **sign-up cohort** cards (this period's intake, followed to today) and are grouped separately on the dashboard for that reason. Drill down to the individual rows via `GET /api/metrics/churned-subscriptions` (same filter params as `/summary`), or `…/export` for the uncapped CSV; rows that both joined and left inside the window are flagged, since they sit in the churn numerator but never in the base it divides by.
- **Win-back** — each row in the churned list is checked for a later subscription under the same customer key (billing email, lowercased; `cid:<customer_id>` when there is no email). A subscription that started while the old one was still running is a concurrent plan, not a return, so the cutoff is the **end date**. Same-day restarts are flagged as plan switches rather than counted as genuine win-backs, and customers with neither an email nor an id are reported as `unknown` and held out of the rate instead of counted as "did not return". Surfaced as a summary line and a *Came back* column on the drill-down, and as six extra columns in its CSV.
- **Failed sign-ups** (`failed_signups`) — leavers that ended having never completed an order. A checkout defect, not churn: nothing was earned to lose. Netted out of `churned_net_of_failed` / `monthly_churn_rate_net`, which sit beside the gross rate rather than replacing it, so a published figure never silently changes meaning.
- **Dormant on-hold** (`on_hold_dormant`) — `on-hold` subscriptions with no **completed** order in 45 days (`ON_HOLD_DORMANT_DAYS`). Failed retries do not count. These have stopped paying but can never become churn, since churn only counts terminal statuses — so the churn rate is a floor while this is non-zero.
- **Net / gross revenue retention** (`net_revenue_retention`, `gross_revenue_retention`) — of the recurring revenue on the books when the period opened, how much was still billing when it closed. Each subscriber is priced at its most recent completed order before the instant in question. NRR lets upgrades push it above 100%; GRR caps each subscriber at its starting price so it only measures loss. New sign-ups are excluded from both by design: this asks whether the existing book held, not whether acquisition replaced it.
- **Retention series** (`retentionSeries()`, surfaced through `GET /api/metrics/sparklines`) — net churn and NRR per month for the hero sparklines. Both are computed metrics, so `trend()` cannot bucket them; rather than run `compute()` once a month (~27 queries each), the whole subscription book and its completed orders are read once (3 queries) and every month is walked in memory. A test asserts the series and `compute()` agree month by month, so a sparkline can never tell a different story from the card above it.
- **Cohort value** (`GET /api/metrics/cohort-value?cohorts=12`) — lifetime completed spend per sign-up month: cohort size, still-active count, retention %, median tenure, total earned and value per subscriber. Read against acquisition cost, this is what decides whether the churn rate is survivable. Cohorts from the last three months are flagged `immature` — still accruing revenue.
- **Subscription status mix** — point-in-time donut across all six subscription statuses.
- **Subscriber history** (`GET /api/metrics/churn?months=12`) — a row per calendar month with *active at start*, *new*, *churned*, *active at end* and the churn rate. Every figure comes from sign-up and end dates, so a closed month's row never changes; a cancellation in June moves June's row and nothing before it. Also appended to the metrics CSV export.

### Dashboard shell

A fixed dark rail on the left, a top bar carrying the period filter, and five
views. The rail switches `view` in Alpine rather than navigating, so fetched data
survives moving between views.

| View | Holds |
| --- | --- |
| **Overview** | MRR, NRR, Churn·Real, Revenue; trend chart; subscription mix; renewals due; top channels. Sized to fit one screen. |
| **Subscribers** | Subscription cards, live-state chips, sign-up cohort, the subscribers-lost drill-down |
| **Retention** | Churn rates, revenue retention, tenure at churn, month-by-month subscriber history |
| **Acquisition** | Segment performance and the renewal pipeline |
| **Customers** | Customer cards, top customers, one-time → subscription conversion |
| **Cohorts** | Cohort value and cohort retention — the same intake seen as value earned and as share retained |
| **Revenue** | Orders, not-completed breakdown, supporting totals, revenue-split and status-mix donuts |
| **Email** | Klaviyo campaign and flow performance — the only section fed by a live API rather than the CSV |

The rail lives in `dashboard/partials/rail` and the **layout** renders it, so it
appears on every page. On the dashboard the entries are buttons that flip
Alpine's `view`; anywhere else they are links back to `?view=…`, which the
dashboard reads on boot. Defining the nav inside one view left the rail empty on
the upload page.

**Subscription mix** is not a doughnut of all six statuses. `cancelled` is a
cumulative lifetime count and was 74% of that chart, which meant the ring only
ever got pinker and buried the number that matters — how many subscribers are
live now. `partials/status-mix` makes the live book the subject (a segmented bar
of `active` / `on-hold` / `pending-cancel` / `pending`) and reports everything
ended underneath as context.

Charts inside a view that is hidden at first paint render at **zero size**.
`renderChartsFor(view)`, fired by a watcher on `view`, redraws them on the way
in; without it the Revenue view's donuts came up blank and clipped.

The remaining doughnut has **no tooltip**. The hole is the label: hovering a slice
names it in the centre, in the slice's own colour, with its share. A floating
tooltip on a doughnut covers the neighbouring slices you are comparing against,
and the space in the middle is already the right size. `donutOptions(key)` wires
this up; a `@mouseleave` on the wrapper clears it, since Chart.js reports no
hover once the pointer leaves the canvas.

Three things that will bite if you edit the shell:

- The Alpine root lives on the layout's outer `<div>` via `@yield('app_data')`,
  **not** inside `@section('content')` — the rail and top bar have to share the
  views' scope. Use the block form `@section('app_data') … @endsection`; the
  inline `@section(name, value)` form compiles its second argument as a PHP
  string, so `{{ }}` echoes inside it never run.
- Filter `<option>`s are rendered server-side. With `x-for`-generated options,
  `x-model` applies its value before the options exist and the select snaps to
  the first entry.

### Dashboard hierarchy

Cards are not all equal, and the difference is data, not markup. Every metric is
declared once in the table at the top of `dashboard/index.blade.php`:

| Field | Meaning |
| --- | --- |
| `tier` | `1` renders a **card** (`partials/card`) — a number you would act on. `2` renders a **chip** (`partials/stat-chip`) — quieter supporting detail, half the height, dimmed when the value is zero. |
| `dir` | `good` (up is better), `bad` (up is worse), `flat` (neither). Drives the semantic icon tint **and** the comparison badge, so a rising churn rate goes red rather than green. |
| `note` | Alpine expression for the caption under the value. |
| `help` | Hover text. Explanations belong on the number they explain, not in a paragraph competing with it. |

The `$directions` map is derived from the same table and handed to Alpine, so the
badge colours can never drift from the definitions. Sections carry an `id` that
registers them with the sticky nav's scroll tracker. The hero shows three metrics
that appear **nowhere else** on the page — repeating section cards there spends
the best space on the page saying nothing new.

### Gross, net, and what the business keeps

`total_amount` is **gross**: it carries VAT and shipping, together about 24% of
it here, and refunded money is still inside it. Export with
`04-export-with-net-revenue.sql` and the split arrives per order:

```
gross                    £4,802.70
  less VAT                 £800.48   ← HMRC's, never revenue
  less shipping            £218.75   ← pass-through
  less refunds              £72.90   ← its own record type, invisible to an order query
= net kept               £3,710.57
```

`net_revenue_known` says whether the split is real. Without those columns the net
figures report **null**, never gross-relabelled-as-net.

Contribution needs a margin nobody can derive from a WooCommerce export. Set
`METRICS_GROSS_MARGIN_PCT` (and `METRICS_CAC` for payback) in `config/metrics.php`;
until then both report as unset rather than assuming a number.

### Counting rules that were quietly wrong

- **Order statuses are whitelisted.** "Not completed" was `status != 'completed'`,
  which swept 26 deleted (`trash`) orders worth £3,099 into a retention metric.
  Unrecognised statuses are now excluded *and* surfaced in `unrecognised_statuses`
  rather than absorbed.
- **Churn is reported on customers as well as subscriptions.** A customer has
  churned only when *every* subscription they hold has ended. 172 of 639
  cancellations belong to people who still have a live one, so the
  subscription-level rate roughly doubles the customer-level one (16.6% vs 7.9%).
- **Customers are deduplicated on billing email.** `customer_id` is 0 for every
  guest and one person can hold several.
- **Segment rates carry a 95% interval and a sample size.** At n = 13 a 69%
  repeat rate spans 44–94%. At p = 0 the normal approximation returns zero, which
  reads as certainty, so the rule of three (3/n) is used at the extremes.

### Campaign lists

`GET /api/metrics/audience?audience=…` (plus `/export`) serves four lists, each
sorted by lifetime spend: `cross_sell` (single-flavour subscribers, the Combo
upgrade), `win_back` (every subscription ended, 3+ payments), `never_subscribed`
(one-time buyers who never signed up), `partial_churn` (cancelled one plan, kept
another — counted as churn, still buying).

### Reconciling a subscriber count

When the dashboard disagrees with another report, `subs:explain` puts every
subscription into exactly one labelled bucket for a given month:

```
php artisan subs:explain 2026-04                      # at the month end, as the cards read
php artisan subs:explain 2026-04 --at=start
php artisan subs:explain 2026-04 --at=2026-04-15      # any specific date
php artisan subs:explain 2026-04 --daily --target=156 # scan every day, flag what matches
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