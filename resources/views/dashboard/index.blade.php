@extends('layouts.app')

@section('title', 'Dashboard — Hurayra Analytics')

@php
    // Heroicons (outline) path data, keyed for reuse below.
    $icons = [
        'user_plus' => 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z',
        'users' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'pause' => 'M15.75 5.25v13.5m-7.5-13.5v13.5',
        'x_circle' => 'm9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'check_badge' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.623 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.249-8.25-3.285Z',
        'bag' => 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
        'arrow_path' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
        'check_circle' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'exclaim' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
        'dollar' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z',
        'calc' => 'M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z',
        'scale' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.97ZM4.5 5.49l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L4.5 5.49Z',
    ];

    /**
     * One definition per metric, and the whole visual hierarchy falls out of it.
     *
     *   tier  1 = primary card (big, iconed) | 2 = secondary chip (quiet, inline)
     *   dir   'good' = up is better | 'bad' = up is worse | 'flat' = neither
     *   note  Alpine expression for the caption under the value
     *   help  hover text; keeps the explanation on the number it explains
     *         rather than in a paragraph competing with the data
     *
     * `dir` also drives the comparison badge: without it every increase reads
     * green, which paints a rising churn rate as good news.
     */
    $M = function (string $label, string $icon, int $tier = 1, string $dir = 'flat', ?string $note = null, ?string $help = null) {
        return compact('label', 'icon', 'tier', 'dir', 'note', 'help');
    };

    // --- Subscriptions: what moved during the period, then live states -------
    $flowCards = [
        'new_subscribers' => $M('New Subscribers', $icons['user_plus'], 1, 'good', "'joined during this period'"),
        'churned_in_period' => $M('Subscribers Lost', $icons['x_circle'], 1, 'bad', "'ended during this period'",
            'Every subscription whose end date falls inside this period, whenever it signed up. This is the churn numerator.'),
        'subscribers_active' => $M('Active Subscribers', $icons['users'], 1, 'good', 'periodEndNote()',
            'Status active at a single instant — the period end. On Hold and Pending Cancellation are separate states and are not included. Run php artisan subs:explain {YYYY-MM} to reconcile against another report.'),
    ];
    $stateChips = [
        'on_hold' => $M('On Hold', $icons['pause'], 2, 'bad', 'onHoldNote()',
            'A live state with no history in the source data, so it is read as-is at the period end.'),
        'pending_cancellation' => $M('Pending Cancellation', $icons['clock'], 2, 'bad', "'as of the period end'",
            'Cancellation requested but not yet effective. These have not churned yet.'),
        'failed_signups' => $M('Failed Sign-ups', $icons['exclaim'], 2, 'bad', "'never ordered'",
            'A subset of Subscribers Lost: subscriptions that ended having never completed an order. A checkout defect, not churn — they cost no revenue. Churn · Real excludes them.'),
    ];
    // This period's SIGN-UPS followed to today. Answers "did this intake
    // stick?", never "who left this month" — the mistake this dashboard invites.
    $signupCohortCards = [
        'cancelled_without_purchase' => $M('Cancelled · No Purchase', $icons['x_circle'], 2, 'bad', "'never completed an order'"),
        'cancelled_with_purchase' => $M('Cancelled · Purchased', $icons['check_badge'], 2, 'bad', "'ordered at least once'"),
    ];

    $orderCards = [
        'completed' => $M('Completed', $icons['check_circle'], 1, 'good'),
        'subscription_purchases' => $M('Subscription Purchases', $icons['arrow_path'], 1, 'good'),
    ];
    $orderChips = [
        'one_time_purchase' => $M('One-time Purchase', $icons['bag'], 2, 'good'),
        'renewal_purchases' => $M('Renewal Purchases', $icons['arrow_path'], 2, 'good'),
        'new_not_completed' => $M('New (not completed)', $icons['exclaim'], 2, 'bad'),
    ];

    $totalCards = [
        'total_revenue' => $M('Revenue · Gross', $icons['dollar'], 1, 'good', "'includes VAT and shipping'",
            'Completed orders at their full charged amount. VAT and shipping are roughly a quarter of this and are not yours to keep — see the breakdown below.'),
        'net_revenue_after_refunds' => $M('Revenue · Net', $icons['dollar'], 1, 'good', "'after VAT, shipping and refunds'",
            'What the business actually keeps: gross less VAT, less shipping, less refunds. Every LTV and ARPU figure elsewhere is built on gross and is therefore about a third higher than this.'),
        'mrr' => $M('MRR', $icons['arrow_path'], 1, 'good', "'recurring, normalised to 30 days'",
            'Every active subscription priced at its most recent payment and normalised to a month by its own billing cycle.'),
    ];
    $totalChips = [
        'arr' => $M('ARR', $icons['arrow_path'], 2, 'good', null,
            'MRR x 12. A run rate, not a forecast — it assumes today\'s book bills unchanged for a year.'),
        'arpu' => $M('ARPU', $icons['users'], 2, 'good', "'per active subscriber, per month'"),
        'average_order_value' => $M('Avg Order Value', $icons['calc'], 2, 'good'),
        'subscription_revenue' => $M('Subscription Revenue', $icons['arrow_path'], 2, 'good'),
        'one_time_revenue' => $M('One-time Revenue', $icons['bag'], 2, 'good'),
    ];

    $retentionCards = [
        'monthly_churn_rate_net' => $M('Churn · Real', $icons['x_circle'], 1, 'bad', "'excludes failed sign-ups'",
            'Subscribers lost during the period, minus those that never completed an order, over the number active when it opened.'),
        'net_revenue_retention' => $M('Net Revenue Retention', $icons['dollar'], 1, 'good', "'of the opening book'",
            'Of the recurring revenue on the books when the period opened, how much was still billing at the end. Counts only subscribers who were already there, so new sign-ups cannot flatter it. Above 100% means upgrades outweighed losses.'),
    ];
    $retentionChips = [
        'monthly_churn_rate' => $M('Churn · Gross', $icons['x_circle'], 2, 'bad', "'all leavers'",
            'The same rate including failed sign-ups. Kept so a previously published figure never changes meaning.'),
        'customer_churn_rate' => $M('Churn · Customers', $icons['users'], 2, 'bad', "'people, not subscriptions'",
            'A customer has churned only when every subscription they hold has ended. 27% of cancellations belong to people who still have a live one — plan changes the subscription rate reports as losses.'),
        'renewal_success_rate' => $M('Renewal Success', $icons['arrow_path'], 2, 'good'),
        'revenue_at_risk' => $M('Revenue at Risk', $icons['exclaim'], 2, 'bad', null,
            'Value of failed and pending renewal orders in the period — recoverable, involuntary churn.'),
        'failed_renewals' => $M('Failed Renewals', $icons['x_circle'], 2, 'bad'),
    ];

    $customerCards = [
        'unique_customers' => $M('Unique Customers', $icons['users'], 1, 'good'),
        'revenue_per_customer' => $M('Revenue / Customer', $icons['dollar'], 1, 'good'),
    ];
    $customerChips = [
        'new_customers' => $M('New Customers', $icons['user_plus'], 2, 'good'),
        'returning_customers' => $M('Returning Customers', $icons['arrow_path'], 2, 'good'),
        'repeat_rate' => $M('Repeat Rate', $icons['check_badge'], 2, 'good'),
    ];

    // Direction map handed to Alpine so the comparison badge knows which way is
    // up for each metric. Built from the definitions above - never duplicated.
    $allMetrics = array_merge(
        $flowCards, $stateChips, $signupCohortCards, $orderCards, $orderChips,
        $totalCards, $totalChips, $retentionCards, $retentionChips,
        $customerCards, $customerChips,
    );
    $directions = array_map(fn ($m) => $m['dir'], $allMetrics);

    /**
     * The overview KPIs. MRR and ARPU are the recurring base; NRR and Churn are
     * how fast it is leaking. Nothing else earns a place at this size.
     */
    $heroCards = [
        'mrr' => $M('MRR', $icons['arrow_path'], 1, 'good', "'recurring, normalised to 30 days'",
            'Every active subscription priced at its most recent payment and normalised to a month by its own billing cycle — a two-monthly plan at £40 is £20 of MRR. Gross: includes VAT and shipping, like every money figure here.'),
        'net_revenue_retention' => $M('Net Revenue Retention', $icons['scale'], 1, 'good', "'of the opening book'",
            'Of the recurring revenue on the books when the period opened, how much was still billing at the end. New sign-ups are excluded, so it cannot be flattered by acquisition.'),
        'monthly_churn_rate_net' => $M('Churn · Real', $icons['x_circle'], 1, 'bad', "'excludes failed sign-ups'",
            'Subscribers lost during the period, less those that never completed an order, over the number active when it opened.'),
        'total_revenue' => $M('Revenue', $icons['dollar'], 1, 'good', "'completed orders, gross'",
            'Completed orders in the period. Gross — it includes VAT and shipping, which together are about 24% of the figure.'),
    ];

@endphp


{{-- Block form, not @section(name, value): the inline form compiles its second
     argument as a PHP string, so the {{ }} echoes inside it never run. Js::from
     escapes for an HTML attribute, so this is safe inside x-data="…". --}}
@section('app_data')dashboard({ defaultYear: {{ (int) $years[0] }}, trendMetrics: {{ \Illuminate\Support\Js::from($trendMetrics) }}, segmentDimensions: {{ \Illuminate\Support\Js::from($segmentDimensions) }}, years: {{ \Illuminate\Support\Js::from($years) }}, directions: {{ \Illuminate\Support\Js::from($directions) }} })@endsection

@section('topbar')
    <div class="min-w-0 flex-1">
        <h1 class="truncate text-base font-bold tracking-tight text-slate-900" x-text="viewLabel()"></h1>
        <p class="truncate text-xs text-slate-500">
            <span x-text="periodLabel || '…'"></span>
            <span x-show="loading" x-cloak class="text-slate-400">· updating…</span>
        </p>
    </div>

    {{-- The period filter belongs in the chrome: it applies to every view. --}}
    <div class="flex shrink-0 items-center gap-2">
        <div class="hidden items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5 sm:flex">
            @foreach (['week' => 'W', 'month' => 'M', 'year' => 'Y', 'custom' => 'Range'] as $g => $short)
                <button type="button" @click="granularity = '{{ $g }}'"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                        :class="granularity === '{{ $g }}' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-400 hover:text-slate-600'">
                    {{ $short }}
                </button>
            @endforeach
        </div>

        <template x-if="granularity !== 'custom'">
            <div class="flex items-center gap-1.5">
                <template x-if="granularity !== 'year'">
                    @include('dashboard.partials.dropdown', [
                        'id' => 'month', 'model' => 'month', 'options' => 'monthOptions()',
                        'width' => 'w-32', 'compact' => true,
                    ])
                </template>
                <template x-if="granularity === 'week'">
                    @include('dashboard.partials.dropdown', [
                        'id' => 'week', 'model' => 'week', 'options' => 'weekOptions()',
                        'width' => 'w-44', 'compact' => true,
                    ])
                </template>
                @include('dashboard.partials.dropdown', [
                    'id' => 'year', 'model' => 'year', 'options' => 'yearOptions()',
                    'width' => 'w-24', 'compact' => true,
                ])
            </div>
        </template>

        <template x-if="granularity === 'custom'">
            <div class="flex items-center gap-1.5">
                <input type="date" x-model="from" @change="apply()" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm">
                <input type="date" x-model="to" @change="apply()" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm">
            </div>
        </template>

        <label class="hidden cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-500 xl:flex">
            <input type="checkbox" x-model="compare" @change="apply()" class="rounded border-slate-300 text-slate-800 focus:ring-slate-400">
            Compare
        </label>

        <a :href="exportHref()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
            CSV
        </a>
        <a :href="clientReportHref()" target="_blank"
           class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700">
            Report
        </a>
    </div>
@endsection

@section('content')
@if (! $hasData)

    <div class="mt-10 overflow-hidden rounded-3xl border border-dashed border-slate-300 bg-white/70 p-16 text-center shadow-sm">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-linear-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
        </span>
        <h2 class="mt-5 text-xl font-bold tracking-tight text-slate-900">No data yet</h2>
        <p class="mt-2 text-sm text-slate-500">Upload your WooCommerce orders CSV to light up the dashboard.</p>
        <a href="{{ route('uploads.index') }}"
           class="mt-6 inline-flex items-center gap-2 rounded-xl bg-linear-to-br from-indigo-500 to-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:shadow-xl hover:shadow-indigo-500/40">
            Upload CSV
        </a>
    </div>
@else
<div>

    {{-- =================== OVERVIEW =================== --}}
    {{-- The screen that answers "how is the business doing" without scrolling.
         Everything here is deliberately duplicated from a deeper view: this is a
         summary, and a summary that hides its own numbers is not one. --}}
    <div x-show="view === 'overview'" x-cloak>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($heroCards as $key => $c)
                @include('dashboard.partials.card', ['key' => $key, 'm' => $c])
            @endforeach
        </div>

        <p class="mt-3 text-xs text-slate-500" x-text="periodSummary()"></p>

        {{-- Charts first: the thing that reads as a dashboard at a glance. --}}
        <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="rounded-xl border border-slate-200/80 bg-white p-5 shadow-sm xl:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-700">Trend</h3>
                    <div class="no-print flex items-center gap-2">
                        @include('dashboard.partials.dropdown', [
                            'id' => 'trendMetric', 'model' => 'trendMetric', 'options' => 'trendOptions()',
                            'onChange' => 'fetchTrend()', 'width' => 'w-44', 'compact' => true,
                        ])
                        {{-- Any metric, split by any attribution column on the row:
                             "is this channel decaying" is a question the segment
                             snapshot cannot answer. --}}
                        @include('dashboard.partials.dropdown', [
                            'id' => 'trendBreakdown', 'model' => 'trendBreakdown', 'options' => 'breakdownOptions()',
                            'onChange' => 'fetchTrend()', 'width' => 'w-36', 'compact' => true,
                        ])
                    </div>
                </div>
                <div class="h-64"><canvas x-ref="trendCanvas"></canvas></div>
                {{-- A capped chart must never read as the whole picture. --}}
                <p x-show="trendOther > 0" x-cloak class="mt-2 text-xs text-slate-500">
                    Six biggest shown · <span class="font-semibold text-slate-600" x-text="trendOther"></span>
                    smaller values are not plotted.
                </p>
            </div>

            <div class="rounded-xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-700">Subscription mix</h3>
                <div class="mt-4">
                    @include('dashboard.partials.status-mix', ['compact' => true])
                </div>
            </div>
        </div>

        {{-- Two action panels: what is coming, and where it came from. --}}
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                    <div>
                        <h3 class="text-sm font-bold text-slate-700">Renewals due · next <span x-text="renewals.days"></span> days</h3>
                        <p class="text-[11px] text-slate-500">
                            <span class="font-semibold text-amber-600" x-text="renewals.at_first_renewal"></span>
                            at their first renewal — half never survive it
                        </p>
                    </div>
                    <button type="button" @click="view = 'acquisition'"
                            class="shrink-0 text-xs font-semibold text-slate-400 transition hover:text-slate-700">All →</button>
                </div>
                <div class="max-h-72 overflow-y-auto">
                    <table class="min-w-full text-xs">
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="r in renewals.rows.slice(0, 8)" :key="r.id">
                                <tr :class="r.first_renewal ? 'bg-amber-50/40' : ''">
                                    <td class="max-w-[180px] truncate px-5 py-2 text-slate-600" x-text="r.customer || `#${r.id}`"></td>
                                    <td class="whitespace-nowrap px-2 py-2 text-slate-400"
                                        x-text="r.days_away === 0 ? 'today' : `${r.days_away}d`"></td>
                                    <td class="px-5 py-2 text-right font-semibold tabular-nums text-slate-800" x-text="money(r.amount)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p x-show="!renewals.rows.length" x-cloak class="px-5 py-6 text-center text-xs text-slate-400">
                        Nothing scheduled in this window.
                    </p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                    <div>
                        <h3 class="text-sm font-bold text-slate-700">Where subscribers come from</h3>
                        <p class="text-[11px] text-slate-500">lifetime, by reaching a second payment</p>
                    </div>
                    <button type="button" @click="view = 'acquisition'"
                            class="shrink-0 text-xs font-semibold text-slate-400 transition hover:text-slate-700">All →</button>
                </div>
                <div class="max-h-72 overflow-y-auto px-5 py-3">
                    <template x-for="r in segments.rows.slice(0, 6)" :key="r.segment">
                        <div class="flex items-center gap-3 py-1.5">
                            <span class="w-28 shrink-0 truncate text-xs font-medium text-slate-600" x-text="r.segment"></span>
                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span class="block h-full rounded-full bg-linear-to-r from-sky-400 to-indigo-500"
                                      :style="`width: ${repeatBarWidth(r.repeat_pct)}`"></span>
                            </span>
                            <span class="w-10 shrink-0 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="`${r.repeat_pct}%`"></span>
                            <span class="w-14 shrink-0 text-right text-xs tabular-nums text-slate-400" x-text="money(r.ltv)"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- =================== SUBSCRIBERS =================== --}}
    <div x-show="view === 'subscribers'" x-cloak>
    {{-- ============ Subscriptions ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Subscriptions', 'id' => 'subscriptions', 'bar' => 'from-indigo-400 to-violet-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {{-- The lost card opens its own drill-down: the list belongs to the
             number, not to a button four paragraphs further down the page. --}}
        @foreach ($flowCards as $key => $c)
            @include('dashboard.partials.card', [
                'key' => $key,
                'm' => $c,
                'action' => $key === 'churned_in_period' ? 'toggleLost()' : null,
            ])
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($stateChips as $key => $c)
            @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    {{-- The growth engine. The cards above are one period; this is the shape of
         the whole year, and it is the only place the book's size is visible as
         a movement rather than a number. --}}
    <div class="mt-4 print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"
         x-show="churn.rows.length" x-cloak>
        <h3 class="text-sm font-bold text-slate-700">
            Subscriber Growth <span class="font-medium text-slate-400">· last 12 months</span>
        </h3>
        <p class="mt-1 text-xs text-slate-500">
            Sign-ups above the line, subscribers lost below it, and the size of the book at each month's end.
            When the pink bar is longer than the teal one, the book shrank that month.
        </p>
        <div class="mt-4 h-72"><canvas x-ref="growthCanvas"></canvas></div>
        <p x-show="partialMonthLabel()" x-cloak class="mt-2 text-xs text-amber-700">
            <span class="font-semibold" x-text="partialMonthLabel()"></span> is still filling up — its bars and the
            dashed segment count a partial month, not a fall.
        </p>
    </div>

    {{-- Set apart deliberately: these follow one intake forward in time, so they
         are not comparable with the flow cards above. --}}
    <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50/60 p-4">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
            This period's sign-ups, tracked to today
        </p>
        <div class="grid grid-cols-2 gap-3">
            @foreach ($signupCohortCards as $key => $c)
                @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
            @endforeach
        </div>
        <p class="mt-3 text-xs text-slate-500">
            Of the people who <em>signed up</em> in this period, how many have cancelled since — whenever they left.
            Not the same as <span class="font-semibold text-slate-600">Subscribers Lost</span> above, which counts everyone
            who <em>left</em> during the period regardless of when they joined.
        </p>
    </div>

    {{-- On-hold subscriptions that stopped paying are invisible to churn: churn
         counts terminal statuses only, and nothing promotes a stalled hold. --}}
    <div x-show="onHoldDormant() > 0" x-cloak
         class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-xs text-amber-900">
        <span class="font-semibold" x-text="onHoldDormant()"></span> on-hold subscription(s) have not had a
        <em>successful</em> payment in 45+ days — failed retries do not count. They have stopped paying but will never appear in the churn figures, because churn only counts
        <code class="font-mono">cancelled</code> and <code class="font-mono">expired</code>. Treat the churn rate as a
        floor while this number is non-zero.
    </div>

    {{-- Drill-down: the actual rows behind "Subscribers Lost", so the number is
         checkable against WooCommerce rather than taken on trust. --}}
    <div class="mt-3 print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm" x-show="lost.open" x-cloak>
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
            <div>
                <h3 class="text-sm font-bold text-slate-700">
                    Subscribers Lost <span class="font-medium text-slate-400">· <span x-text="periodLabel"></span></span>
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                    Every subscription whose end date falls inside this period, whenever it signed up. These are the rows
                    the churn rate counts — the ID matches the subscription id in WooCommerce.
                </p>
            </div>
            <a :href="lostExportHref()"
               class="no-print shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Export CSV
            </a>
        </div>

        <p x-show="lost.loading" x-cloak class="p-5 text-sm text-slate-500">Loading…</p>
        <p x-show="lost.error" x-cloak class="m-5 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" x-text="lost.error"></p>

        <template x-if="!lost.loading && !lost.error">
            <div>
                {{-- Win-back: how much of this "churn" walked back through the
                     door under the same email. --}}
                <div x-show="lostSummary().identifiable > 0" x-cloak
                     class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-slate-100 bg-slate-50/60 px-5 py-3 text-xs text-slate-600">
                    <span>
                        <span class="text-base font-bold text-emerald-600" x-text="lostSummary().resubscribed"></span>
                        of <span class="font-semibold" x-text="lostSummary().identifiable"></span>
                        resubscribed with the same email
                        (<span class="font-semibold" x-text="`${lostSummary().rate}%`"></span>)
                    </span>
                    <span x-show="lostSummary().median_days_to_return !== null">
                        median gap <span class="font-semibold text-slate-700" x-text="`${lostSummary().median_days_to_return}d`"></span>
                    </span>
                    <span x-show="lostSummary().still_active > 0">
                        <span class="font-semibold text-slate-700" x-text="lostSummary().still_active"></span> still active today
                    </span>
                    <span x-show="lostSummary().same_day_switches > 0" class="text-sky-700">
                        <span class="font-semibold" x-text="lostSummary().same_day_switches"></span> restarted the same day
                        (plan switches, not true churn)
                    </span>
                    <span x-show="lostSummary().unidentifiable > 0" class="text-slate-400">
                        <span class="font-semibold" x-text="lostSummary().unidentifiable"></span> with no email on record,
                        held out of the rate
                    </span>
                </div>

                {{-- What the churn cost, rather than how many people it was. Two
                     leavers at different price points are not the same loss. --}}
                <div class="flex flex-wrap items-center gap-x-8 gap-y-2 border-b border-slate-100 px-5 py-3 text-xs text-slate-600">
                    <span>
                        Recurring revenue lost
                        <span class="ml-1 text-base font-bold text-rose-600" x-text="money(lostSummary().recurring_lost)"></span>
                        <span class="ml-1 text-slate-400">per cycle</span>
                    </span>
                    <span x-show="lostSummary().recurring_recovered > 0">
                        recovered by still-active win-backs
                        <span class="font-semibold text-emerald-600" x-text="money(lostSummary().recurring_recovered)"></span>
                    </span>
                    <span>
                        lifetime spend of these customers
                        <span class="font-semibold text-slate-700" x-text="money(lostSummary().lifetime_spend_lost)"></span>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-5 py-2.5">Subscription</th>
                                <th class="px-5 py-2.5">Customer</th>
                                <th class="px-5 py-2.5">Signed up</th>
                                <th class="px-5 py-2.5">Ended</th>
                                <th class="px-5 py-2.5 text-right">Tenure</th>
                                <th class="px-5 py-2.5 text-right">Paid orders</th>
                                <th class="px-5 py-2.5 text-right">Spend</th>
                                <th class="px-5 py-2.5 text-right">Came back</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="r in lost.rows" :key="r.id">
                                <tr class="transition hover:bg-slate-50/60">
                                    <td class="whitespace-nowrap px-5 py-2.5">
                                        <span class="font-mono text-xs font-semibold text-slate-700" x-text="`#${r.id}`"></span>
                                        {{-- Joined and left inside the same window: in the churn
                                             numerator, but never in the base it is divided by. --}}
                                        <span x-show="r.joined_in_period"
                                              class="ml-1.5 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">new this period</span>
                                    </td>
                                    <td class="max-w-[220px] truncate px-5 py-2.5 text-slate-600" x-text="r.customer || '—'"></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-slate-500" x-text="shortDate(r.created)"></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-slate-500" x-text="shortDate(r.ended)"></td>
                                    <td class="px-5 py-2.5 text-right tabular-nums text-slate-700" x-text="`${r.tenure_days}d`"></td>
                                    <td class="px-5 py-2.5 text-right tabular-nums"
                                        :class="r.completed_orders === 0 ? 'font-semibold text-rose-600' : 'text-slate-600'"
                                        x-text="r.completed_orders"></td>
                                    <td class="px-5 py-2.5 text-right font-semibold tabular-nums text-slate-900" x-text="money(r.spend)"></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right">
                                        <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                              :class="returnBadgeClass(r)"
                                              :title="r.returned ? `Subscription #${r.returned_subscription_id}` : ''"
                                              x-text="returnLabel(r)"></span>
                                        {{-- Came back is not the same as stayed. --}}
                                        <span x-show="r.returned && r.returned_status"
                                              class="ml-1 rounded px-1.5 py-0.5 text-[11px] font-medium"
                                              :class="returnStateClass(r)"
                                              x-text="returnStateLabel(r)"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                {{-- Never let a capped list read as the whole story. --}}
                <p x-show="lost.returned < lost.total" x-cloak
                   class="border-t border-slate-100 bg-amber-50 px-5 py-2.5 text-xs text-amber-800">
                    Showing the first <span class="font-semibold" x-text="lost.returned"></span> of
                    <span class="font-semibold" x-text="lost.total"></span>. Export the CSV for the full list.
                </p>
            </div>
        </template>
    </div>
    </div>

    {{-- =================== RETENTION =================== --}}
    {{-- Kept apart from Subscribers on purpose. That view answers "who is on
         the book"; this one answers "how fast is it leaking, and why" --
         rates, tenure at churn, and the month-by-month history. --}}
    <div x-show="view === 'retention'" x-cloak>
    {{-- ============ Retention & churn ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Retention & Churn', 'id' => 'retention', 'bar' => 'from-rose-400 to-pink-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($retentionCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($retentionChips as $key => $c)
            @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    {{-- The one caption worth keeping visible: it makes the headline checkable. --}}
    <p class="mt-3 text-xs text-slate-500">
        Churn · Real = <span class="font-bold text-slate-700" x-text="value('churned_net_of_failed')"></span> real losses
        (<span x-text="value('churned_in_period')"></span> lost, less
        <span x-text="value('failed_signups')"></span> that never ordered)
        ÷ <span class="font-bold text-slate-700" x-text="value('active_at_period_start')"></span> active when
        <span class="font-semibold text-slate-600" x-text="periodLabel"></span> opened.
    </p>

    {{-- Tenure at churn: the same leavers as the churn rate above, split by how
         long they had been subscribed. Answers "who is leaving", not "how many". --}}
    <div class="mt-4 print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"
         x-show="tenure().total > 0" x-cloak>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-slate-700">
                    Tenure at Churn <span class="font-medium text-slate-400">· <span x-text="periodLabel"></span></span>
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                    The <span class="font-semibold text-slate-600" x-text="tenure().total"></span> subscribers who left
                    during this period, by how long they had been subscribed. Same people as the churn rate above —
                    this splits them by tenure rather than counting them.
                </p>
            </div>
            <div class="shrink-0 rounded-xl bg-rose-50 px-4 py-2 text-right">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-rose-400">Median tenure</p>
                <p class="text-lg font-bold tabular-nums text-rose-600" x-text="tenureMedianLabel()"></p>
            </div>
        </div>

        <div class="mt-4 space-y-2">
            <template x-for="b in tenure().buckets" :key="b.label">
                <div class="flex items-center gap-3">
                    <span class="w-24 shrink-0 text-xs font-medium text-slate-500" x-text="b.label"></span>
                    <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                        <span class="block h-full rounded-full bg-linear-to-r from-rose-400 to-pink-500"
                              :style="`width: ${tenureBarWidth(b)}`"></span>
                    </span>
                    <span class="w-8 shrink-0 text-right text-sm font-semibold tabular-nums text-slate-900" x-text="b.count"></span>
                    <span class="w-12 shrink-0 text-right text-xs tabular-nums text-slate-400" x-text="`${b.pct}%`"></span>
                </div>
            </template>
        </div>

        <p class="mt-3 text-xs text-slate-500">Tenure runs from sign-up to the subscription's end date.</p>
        <template x-if="churn.coverage !== null && churn.coverage < 100">
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Only <span class="font-semibold" x-text="`${churn.coverage}%`"></span> of ended subscriptions carry a
                real end date. For the rest the last linked order stands in, which understates tenure by up to one
                billing cycle.
            </p>
        </template>
    </div>

    {{-- The two rates the book turns on. Separate panels rather than one
         dual-axis chart: churn moves in single digits and NRR sits near 100,
         so sharing a scale would flatten one of them into a straight line. --}}
    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2" x-show="churn.rows.length" x-cloak>
        <div class="print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700">
                Churn Rate <span class="font-medium text-slate-400">· last 12 months</span>
            </h3>
            <p class="mt-1 text-xs text-slate-500">
                Real churn (solid) leaves out subscriptions that ended having never been billed — a broken checkout,
                not a lost customer. The dashed line is the gross rate those are still counted in.
            </p>
            <div class="mt-4 h-64"><canvas x-ref="churnRateCanvas"></canvas></div>
        </div>

        <div class="print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700">
                Net Revenue Retention <span class="font-medium text-slate-400">· last 12 months</span>
            </h3>
            <p class="mt-1 text-xs text-slate-500">
                What last month's subscribers are still worth this month. Above the 100% line the book grows without a
                single new sign-up; below it, acquisition is refilling a bucket that leaks.
            </p>
            <div class="mt-4 h-64"><canvas x-ref="nrrCanvas"></canvas></div>
        </div>
    </div>

    <p x-show="partialMonthLabel()" x-cloak class="mt-2 text-xs text-amber-700">
        <span class="font-semibold" x-text="partialMonthLabel()"></span> is still filling up — the dashed final segment
        counts a partial month.
    </p>

    {{-- Month-by-month subscriber history --}}
    <div class="mt-4 print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm" x-show="churn.rows.length" x-cloak>
        <div class="border-b border-slate-100 p-5">
            <h3 class="text-sm font-bold text-slate-700">
                Subscriber History <span class="font-medium text-slate-400">· last 12 months</span>
            </h3>
            <p class="mt-1 text-xs text-slate-500">
                Each month is counted from the sign-up and end dates of every subscription, so a closed month never
                changes. Somebody who was subscribed in March and cancels in June stays in March's count — only June's
                row moves.
            </p>
            <template x-if="churn.coverage !== null && churn.coverage < 100">
                <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <span class="font-semibold" x-text="`${churn.coverage}%`"></span> of ended subscriptions carry a real
                    end date from the CSV. For the rest the last linked order is used as the end date, which can place a
                    cancellation slightly early. Include an <code class="font-mono">ended_at</code> (or
                    <code class="font-mono">date_cancelled_gmt</code>) column in the export to make this exact.
                </p>
            </template>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5">Month</th>
                        <th class="px-5 py-2.5 text-right">Active at start</th>
                        <th class="px-5 py-2.5 text-right">New</th>
                        <th class="px-5 py-2.5 text-right">Churned</th>
                        <th class="px-5 py-2.5 text-right">Active at end</th>
                        <th class="px-5 py-2.5 text-right">Net</th>
                        <th class="px-5 py-2.5 text-right">Churn rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in churnRows()" :key="row.month">
                        <tr class="transition hover:bg-slate-50/60">
                            <td class="whitespace-nowrap px-5 py-2.5 font-medium text-slate-700">
                                <span x-text="cohortMonthLabel(row.month)"></span>
                                {{-- The month the data stops in is counted up to the last row imported. --}}
                                <span x-show="row.partial" x-cloak
                                      class="ml-1.5 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                    in progress
                                </span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="row.active_start"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-emerald-600" x-text="row.new"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-rose-600" x-text="row.churned"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-slate-900" x-text="row.active_end"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums"
                                :class="row.active_end - row.active_start < 0 ? 'text-rose-600' : 'text-emerald-600'"
                                x-text="netChangeLabel(row)"></td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                                        <span class="block h-full rounded-full bg-linear-to-r from-rose-400 to-pink-500"
                                              :style="`width: ${churnBarWidth(row.churn_rate)}`"></span>
                                    </span>
                                    <span class="w-12 text-right tabular-nums font-semibold text-slate-700"
                                          x-text="churnRateLabel(row.churn_rate)"></span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    {{-- =================== ACQUISITION =================== --}}
    <div x-show="view === 'acquisition'" x-cloak>
    {{-- ============ Acquisition ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Acquisition', 'bar' => 'from-sky-400 to-indigo-500', 'id' => 'acquisition'])

    {{-- Retention split by where the subscriber came from. Until this existed the
         dashboard could say the book was leaking but never which tap it came
         out of, so spend could not be judged against what it bought. --}}
    <div class="print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm"
         x-show="segments.rows.length" x-cloak>
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
            <div>
                <h3 class="text-sm font-bold text-slate-700">Retention by Acquisition Segment</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Reaching a <span class="font-semibold text-slate-600">second payment</span> is the column that
                    matters — it predicts lifetime value earlier than anything else, and about half of all payers
                    never get there. Lifetime value counts every order the subscription ever generated.
                </p>
            </div>
            <div class="no-print flex flex-wrap gap-1">
                <template x-for="d in segments.dimensions" :key="d">
                    <button type="button" @click="fetchSegments(d)"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                            :class="segments.dimension === d
                                ? 'bg-slate-800 text-white'
                                : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                            x-text="dimensionLabel(d)"></button>
                </template>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5" x-text="dimensionLabel(segments.dimension)"></th>
                        <th class="px-5 py-2.5 text-right">Subscriptions</th>
                        <th class="px-5 py-2.5 text-right">Never paid</th>
                        <th class="px-5 py-2.5 text-right">Still active</th>
                        <th class="px-5 py-2.5 text-right">Revenue</th>
                        <th class="px-5 py-2.5 text-right">LTV / sub</th>
                        <th class="px-5 py-2.5">Reached 2nd payment <span class="font-normal normal-case text-slate-300">· 95% range</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in segments.rows" :key="r.segment">
                        <tr class="transition hover:bg-slate-50/60">
                            <td class="max-w-[220px] truncate px-5 py-2.5 font-medium text-slate-700">
                                <span x-text="r.segment"></span>
                                <span x-show="! r.reliable"
                                      class="ml-1.5 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700"
                                      :title="`Only ${r.subs} subscriptions — too few to act on`">n too small</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="r.subs"></td>
                            {{-- Never paid is a checkout failure, not churn. --}}
                            <td class="px-5 py-2.5 text-right tabular-nums"
                                :class="r.never_paid_pct >= 25 ? 'font-semibold text-rose-600' : 'text-slate-500'"
                                x-text="`${r.never_paid_pct}%`"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-emerald-600" x-text="r.still_active"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="money(r.revenue)"></td>
                            <td class="px-5 py-2.5 text-right font-bold tabular-nums text-slate-900" x-text="money(r.ltv)"></td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                        <span class="block h-full rounded-full bg-linear-to-r from-sky-400 to-indigo-500"
                                              :style="`width: ${repeatBarWidth(r.repeat_pct)}`"></span>
                                    </span>
                                    <span class="w-12 text-right font-semibold tabular-nums"
                                          :class="r.reliable ? 'text-slate-700' : 'text-slate-400'"
                                          x-text="`${r.repeat_pct}%`"></span>
                                    {{-- The interval is the point: at n = 13 a
                                         69% rate spans 44%-94%, which is not a
                                         finding. --}}
                                    <span class="w-24 text-right text-[11px] tabular-nums text-slate-400"
                                          x-text="`${r.repeat_low}–${r.repeat_high}`"></span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- The renewal pipeline. `next_payment_at` is scheduled by WooCommerce, so
         this is a dated list rather than an inference — and the first renewal is
         the one worth saving. --}}
    <div class="mt-4 print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm"
         x-show="renewals.total > 0" x-cloak>
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
            <div>
                <h3 class="text-sm font-bold text-slate-700">Renewal Pipeline</h3>
                <p class="mt-1 text-xs text-slate-500">
                    <span class="font-bold text-slate-700" x-text="renewals.total"></span> subscriptions renew in the next
                    <span x-text="renewals.days"></span> days, worth
                    <span class="font-bold text-slate-700" x-text="money(renewals.value)"></span> —
                    of which <span class="font-bold text-amber-600" x-text="renewals.at_first_renewal"></span>
                    are at their <span class="font-semibold">first</span> renewal, the one half of subscribers never survive.
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Each subscription appears <em>once</em>, at its next scheduled payment — this is a work list, not a
                    cash forecast. A forecast would project each cycle repeatedly across the window.
                </p>
            </div>
            <div class="no-print flex items-center gap-1">
                <template x-for="d in [7, 14, 30]" :key="d">
                    <button type="button" @click="fetchRenewals(d)"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                            :class="renewals.days === d ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                            x-text="`${d}d`"></button>
                </template>
                <a :href="renewalsExportHref()"
                   class="ml-1 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
                    Export CSV
                </a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5">Subscription</th>
                        <th class="px-5 py-2.5">Customer</th>
                        <th class="px-5 py-2.5">Renews</th>
                        <th class="px-5 py-2.5 text-right">Payments so far</th>
                        <th class="px-5 py-2.5 text-right">Amount</th>
                        <th class="px-5 py-2.5">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in renewals.rows" :key="r.id">
                        <tr class="transition hover:bg-slate-50/60"
                            :class="r.first_renewal ? 'bg-amber-50/40' : ''">
                            <td class="whitespace-nowrap px-5 py-2.5">
                                <span class="font-mono text-xs font-semibold text-slate-700" x-text="`#${r.id}`"></span>
                                <span x-show="r.first_renewal"
                                      class="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">1st renewal</span>
                            </td>
                            <td class="max-w-[220px] truncate px-5 py-2.5 text-slate-600" x-text="r.customer || '—'"></td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-slate-500"
                                x-text="r.days_away === 0 ? 'today' : `in ${r.days_away}d`"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="r.payments_so_far"></td>
                            <td class="px-5 py-2.5 text-right font-semibold tabular-nums text-slate-900" x-text="money(r.amount)"></td>
                            <td class="max-w-[160px] truncate px-5 py-2.5 text-xs text-slate-400" x-text="r.source || '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    {{-- =================== CUSTOMERS =================== --}}
    <div x-show="view === 'customers'" x-cloak>
    {{-- ============ Customers ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Customers', 'id' => 'customers', 'bar' => 'from-indigo-400 to-violet-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($customerCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($customerChips as $key => $c)
            @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    {{-- Top customers (lifetime), full width --}}
    <div class="mt-4 print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm" x-show="topCustomers.length" x-cloak>
        <h3 class="text-sm font-bold text-slate-700">Top Customers <span class="font-medium text-slate-400">· lifetime completed spend</span></h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr><th class="pb-2">Customer</th><th class="pb-2 text-right">Orders</th><th class="pb-2 text-right">Spend</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(c, i) in topCustomers" :key="c.customer_id">
                        <tr>
                            <td class="py-2">
                                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-md bg-indigo-50 text-[11px] font-bold text-indigo-600" x-text="i + 1"></span>
                                <span class="font-medium text-slate-700" x-text="c.email || ('Customer #' + c.customer_id)"></span>
                            </td>
                            <td class="py-2 text-right tabular-nums text-slate-600" x-text="c.orders"></td>
                            <td class="py-2 text-right font-semibold tabular-nums text-slate-900" x-text="money(c.spend)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Campaign audiences. Every other panel here describes what happened;
         these are lists you can act on, exported straight to Klaviyo. --}}
    @include('dashboard.partials.section-heading', ['title' => 'Campaign Lists', 'id' => 'audiences', 'bar' => 'from-emerald-400 to-teal-500'])
    <div class="print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-700" x-text="audience.label || 'Campaign lists'"></h3>
                <p class="mt-1 text-xs text-slate-500">
                    <span class="font-bold text-slate-700" x-text="audience.total"></span> customers ·
                    <span class="font-bold text-slate-700" x-text="money(audience.value)"></span> of lifetime spend
                    between them. Sorted by what they have already spent, warmest first.
                </p>
            </div>
            <a :href="audienceExportHref()"
               class="no-print shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
                Export CSV
            </a>
        </div>

        <div class="no-print flex flex-wrap gap-1 border-b border-slate-100 px-5 py-3">
            <template x-for="(label, key) in audience.audiences" :key="key">
                <button type="button" @click="fetchAudience(key)"
                        class="rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                        :class="audience.key === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                        x-text="({
                            cross_sell: 'Cross-sell',
                            win_back: 'Win-back',
                            never_subscribed: 'Never subscribed',
                            partial_churn: 'Still subscribed',
                        })[key] ?? key"></button>
            </template>
        </div>

        <div class="max-h-96 overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead class="sticky top-0 bg-slate-50/95 text-left text-xs font-semibold uppercase tracking-wider text-slate-400 backdrop-blur">
                    <tr>
                        <th class="px-5 py-2.5">Customer</th>
                        <th class="px-5 py-2.5">Detail</th>
                        <th class="px-5 py-2.5">Cycle</th>
                        <th class="px-5 py-2.5 text-right">Payments</th>
                        <th class="px-5 py-2.5 text-right">Lifetime spend</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in audience.rows" :key="`${audience.key}-${r.customer}-${r.subscription_id}`">
                        <tr class="transition hover:bg-slate-50/60">
                            <td class="max-w-[240px] truncate px-5 py-2.5 text-slate-700" x-text="r.customer"></td>
                            <td class="max-w-[240px] truncate px-5 py-2.5 text-slate-500" x-text="r.detail || '—'"></td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-xs text-slate-400" x-text="r.cycle || '—'"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="r.payments"></td>
                            <td class="px-5 py-2.5 text-right font-semibold tabular-nums text-slate-900" x-text="money(r.value)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p x-show="! audience.rows.length && ! audience.loading" x-cloak class="px-5 py-8 text-center text-sm text-slate-400">
                Nobody in this audience.
            </p>
        </div>
        <p x-show="audience.total > audience.rows.length" x-cloak
           class="border-t border-slate-100 bg-amber-50 px-5 py-2.5 text-xs text-amber-800">
            Showing the first <span class="font-semibold" x-text="audience.rows.length"></span> of
            <span class="font-semibold" x-text="audience.total"></span>. Export the CSV for the full list.
        </p>
    </div>

    {{-- ============ One-time buyers who then subscribed ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'One-time → Subscription', 'id' => 'upsell', 'bar' => 'from-sky-400 to-indigo-500'])
    <div class="print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 p-5">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-700">
                    Customers who bought one-off first, then subscribed
                    <span class="font-medium text-slate-400">· all time</span>
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                    Matched on billing email (falling back to customer ID). Shows the first one-time order date and the
                    sign-up date of the subscription they took out afterwards.
                </p>
            </div>
            <div class="no-print flex shrink-0 items-center gap-2">
                <span x-show="upsellLoading" x-cloak class="text-xs text-slate-400">loading…</span>
                <a :href="upsellExportHref()"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Export CSV
                </a>
            </div>
        </div>

        <template x-if="upsellError">
            <div class="border-b border-rose-100 bg-rose-50 px-5 py-3 text-sm text-rose-700">
                <span class="font-semibold">Couldn’t load this list.</span>
                <span x-text="upsellError"></span>
            </div>
        </template>

        {{-- Headline numbers --}}
        <div class="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 lg:grid-cols-4">
            @php
                $upsellStats = [
                    ['Converted customers', "new Intl.NumberFormat().format(upsell.summary.converted || 0)", 'of <span x-text="new Intl.NumberFormat().format(upsell.summary.one_time_customers || 0)"></span> one-time buyers'],
                    ['Conversion rate', "upsell.summary.conversion_rate === null || upsell.summary.conversion_rate === undefined ? '—' : upsell.summary.conversion_rate + '%'", 'one-time buyers who later subscribed'],
                    ['Avg time to convert', "daysLabel(upsell.summary.avg_days_to_convert)", 'first one-time order → sign-up'],
                    ['Subscription revenue', "money(upsell.summary.subscription_revenue || 0)", 'completed subscription orders, lifetime'],
                ];
            @endphp
            @foreach ($upsellStats as [$label, $expr, $sub])
                <div class="bg-white p-5">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900" x-text="{{ $expr }}"></p>
                    <p class="mt-0.5 text-xs text-slate-400">{!! $sub !!}</p>
                </div>
            @endforeach
        </div>

        {{-- Controls --}}
        <div class="no-print flex flex-wrap items-center gap-4 border-b border-slate-100 p-4">
            <div class="relative min-w-56 flex-1">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input type="search" x-model="upsellSearch" placeholder="Search email, customer or subscription ID…"
                       class="w-full rounded-lg border-slate-300 py-1.5 pl-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600">
                <span>Subscription after the one-time order</span>
                <span class="relative">
                    <input type="checkbox" x-model="upsellConversionsOnly" @change="fetchUpsell()" class="peer sr-only">
                    <span class="block h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600"></span>
                    <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                </span>
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600">
                <span>Completed one-time orders only</span>
                <span class="relative">
                    <input type="checkbox" x-model="upsellCompletedOnly" @change="fetchUpsell()" class="peer sr-only">
                    <span class="block h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500"></span>
                    <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                </span>
            </label>
        </div>

        {{-- The list --}}
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5">Customer</th>
                        <th class="px-3 py-2.5">One-time order</th>
                        <th class="px-3 py-2.5 text-right">Orders</th>
                        <th class="px-3 py-2.5 text-right">One-time spend</th>
                        <th class="px-3 py-2.5">Subscribed on</th>
                        <th class="px-3 py-2.5 text-right">Gap</th>
                        <th class="px-3 py-2.5">Sub status</th>
                        <th class="px-5 py-2.5 text-right">Sub spend</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(c, i) in upsellVisible()" :key="c.key">
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="px-5 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-[11px] font-bold text-indigo-600" x-text="i + 1"></span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-700" x-text="c.email || ('Customer #' + c.customer_id)"></p>
                                        <p class="text-[11px] text-slate-400">
                                            <span x-show="c.customer_id">ID <span x-text="c.customer_id"></span> · </span>
                                            <span>sub #<span x-text="c.subscription_id"></span></span>
                                            <span x-show="!c.is_conversion" class="ml-1 font-semibold text-amber-600">subscribed first</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-slate-600" x-text="dateLabel(c.first_one_time_at)"></td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-slate-600" x-text="c.one_time_orders"></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-slate-600" x-text="money(c.one_time_spend)"></td>
                            <td class="whitespace-nowrap px-3 py-2.5 font-medium text-slate-700" x-text="dateLabel(c.subscribed_at)"></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-slate-500" x-text="daysLabel(c.days_to_convert)"></td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold capitalize"
                                      :class="subStatusClass(c.subscription_status)" x-text="c.subscription_status"></span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-right font-semibold tabular-nums text-slate-900" x-text="money(c.subscription_spend)"></td>
                        </tr>
                    </template>
                    <template x-if="!upsellLoading && !upsellError && upsellMatches().length === 0">
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-sm text-slate-400">
                                No customer bought a one-time product and then subscribed
                                <span x-show="upsellSearch">matching “<span x-text="upsellSearch"></span>”</span>.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-xs text-slate-400">
            <span>
                Showing <span class="font-semibold text-slate-600" x-text="upsellVisible().length"></span>
                of <span class="font-semibold text-slate-600" x-text="upsellMatches().length"></span>
                <span x-show="upsell.total > upsell.rows.length">
                    (<span x-text="new Intl.NumberFormat().format(upsell.total)"></span> in total — export the CSV for the full list)
                </span>
            </span>
            <button class="no-print rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    x-show="upsellShown < upsellMatches().length" x-cloak
                    @click="upsellShown += 50">
                Show more
            </button>
        </div>
    </div>
    </div>

    {{-- =================== REVENUE =================== --}}
    <div x-show="view === 'revenue'" x-cloak>
    {{-- ============ Orders ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Orders', 'id' => 'orders', 'bar' => 'from-sky-400 to-blue-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($orderCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($orderChips as $key => $c)
            @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    {{-- ===== "Not completed" detail panel ===== --}}
    <div class="mt-4 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <svg class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                “New (not completed)” breakdown
            </h3>
            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600">
                <span>Strict <span class="text-slate-400">(pending + processing)</span></span>
                <span class="relative">
                    <input type="checkbox" x-model="strict" @change="apply()" class="peer sr-only">
                    <span class="block h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-amber-500"></span>
                    <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                </span>
            </label>
        </div>
        <div class="mt-4 flex flex-wrap gap-2.5">
            <template x-for="(count, status) in (metrics.not_completed_breakdown || {})" :key="status">
                <span class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
                    <span x-text="status" class="capitalize"></span>
                    <span class="rounded-lg bg-white px-2 py-0.5 text-xs font-bold text-slate-900 shadow-sm" x-text="count"></span>
                </span>
            </template>
        </div>
        <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
            Standard total (≠ completed): <span class="font-bold text-slate-700" x-text="value('new_not_completed_standard')"></span>
            <span class="mx-1.5 text-slate-300">|</span>
            Strict total (pending + processing): <span class="font-bold text-slate-700" x-text="value('new_not_completed_strict')"></span>
        </p>
    </div>
    {{-- ============ Supporting totals ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Supporting Totals', 'id' => 'totals', 'bar' => 'from-emerald-400 to-teal-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($totalCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($totalChips as $key => $c)
            @include('dashboard.partials.stat-chip', ['key' => $key, 'm' => $c])
        @endforeach
    </div>

    {{-- Gross to net, spelled out. The dashboard reported gross as "revenue"
         for its whole life; this is the line that says what that included. --}}
    <div class="mt-4 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"
         x-show="metrics.net_revenue_known" x-cloak>
        <h3 class="text-sm font-bold text-slate-700">Gross to net</h3>
        <p class="mt-1 text-xs text-slate-500">
            VAT belongs to HMRC and shipping is a pass-through cost. Neither is revenue.
        </p>
        <div class="mt-4 space-y-1.5 text-sm">
            @php
                $waterfall = [
                    ['Gross revenue', 'gross_revenue', 'text-slate-900', false],
                    ['less VAT', 'tax_collected', 'text-slate-500', true],
                    ['less shipping', 'shipping_collected', 'text-slate-500', true],
                    ['less refunds', 'refunded', 'text-slate-500', true],
                ];
            @endphp
            @foreach ($waterfall as [$label, $key, $tone, $negative])
                <div class="flex items-center justify-between gap-3 {{ $negative ? 'pl-4' : '' }}">
                    <span class="{{ $negative ? 'text-slate-500' : 'font-medium text-slate-700' }}">{{ $label }}</span>
                    <span class="font-semibold tabular-nums {{ $tone }}">
                        @if ($negative)<span class="text-slate-400">−</span>@endif<span x-text="value('{{ $key }}')"></span>
                    </span>
                </div>
            @endforeach
            <div class="flex items-center justify-between gap-3 border-t border-slate-200 pt-2">
                <span class="font-bold text-slate-800">Net revenue kept</span>
                <span class="text-lg font-bold tabular-nums text-emerald-600" x-text="value('net_revenue_after_refunds')"></span>
            </div>
            {{-- Contribution needs a margin nobody has supplied yet. Saying so
                 is better than assuming one. --}}
            <div class="flex items-center justify-between gap-3 pt-1">
                <span class="text-xs text-slate-500">
                    Contribution after COGS
                    <span class="text-slate-400" x-show="metrics.gross_margin_pct === null">
                        · set <code class="font-mono">METRICS_GROSS_MARGIN_PCT</code> to enable
                    </span>
                </span>
                <span class="text-sm font-semibold tabular-nums text-slate-500" x-text="moneyOrDash(metrics.contribution)"></span>
            </div>
        </div>
    </div>

    {{-- Breakdown donuts: revenue split + subscription status mix --}}
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Revenue split --}}
        <div class="print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700">Revenue Split <span class="font-medium text-slate-400">· subscription vs one-time</span></h3>
            <div class="mt-4 flex items-center gap-5">
                <div class="relative h-40 w-40 shrink-0" @mouseleave="clearDonut('revenue')">
                    <canvas x-ref="revenueCanvas"></canvas>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                        <template x-if="donutHover.revenue">
                            <div>
                                <span class="block text-[10px] font-semibold uppercase tracking-wider"
                                      :style="`color: ${donutHover.revenue.color}`"
                                      x-text="donutHover.revenue.label"></span>
                                <span class="block text-base font-bold text-slate-900" x-text="money(donutHover.revenue.value)"></span>
                                <span class="block text-[10px] text-slate-400"
                                      x-text="`${share(donutHover.revenue.value, (metrics.subscription_revenue||0)+(metrics.one_time_revenue||0))}%`"></span>
                            </div>
                        </template>
                        <template x-if="! donutHover.revenue">
                            <div>
                                <span class="block text-[10px] font-medium uppercase tracking-wider text-slate-400">Total</span>
                                <span class="block text-base font-bold text-slate-900" x-text="value('total_revenue')"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="min-w-0 flex-1 space-y-3">
                    @php $revLegend = [['Subscription', 'subscription_revenue', '#d46681'], ['One-time', 'one_time_revenue', '#61bac0']]; @endphp
                    @foreach ($revLegend as $r)
                        <div>
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="flex items-center gap-2 text-slate-600">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $r[2] }}"></span>{{ $r[0] }}
                                </span>
                                <span class="font-semibold text-slate-900" x-text="value('{{ $r[1] }}')"></span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full" style="background: {{ $r[2] }}"
                                     :style="`width: ${share(metrics.{{ $r[1] }} || 0, (metrics.subscription_revenue||0)+(metrics.one_time_revenue||0))}%; background: {{ $r[2] }}`"></div>
                            </div>
                            <p class="mt-0.5 text-right text-xs text-slate-400">
                                <span x-text="share(metrics.{{ $r[1] }} || 0, (metrics.subscription_revenue||0)+(metrics.one_time_revenue||0))"></span>%
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Subscription status mix --}}
        <div class="print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700">Subscription Status Mix</h3>
            <div class="mt-4">
                @include('dashboard.partials.status-mix')
            </div>
        </div>
    </div>
</div>
    {{-- =================== COHORTS =================== --}}
    {{-- Cohort value and cohort retention answer the same question from two
         sides: what an intake earned, and how much of it stayed. They were
         split across the Acquisition and Customers tabs. --}}
    <div x-show="view === 'cohorts'" x-cloak>
    {{-- ============ Cohort value ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Cohort Value', 'id' => 'cohorts', 'bar' => 'from-emerald-400 to-teal-500'])
    <div class="print-block overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm"
         x-show="cohortValue.rows && cohortValue.rows.length" x-cloak>
        <div class="border-b border-slate-100 p-5">
            <h3 class="text-sm font-bold text-slate-700">
                Lifetime Value by Sign-up Month
            </h3>
            <p class="mt-1 text-xs text-slate-500">
                What each intake has earned, all orders counted. Read against what it costs to acquire a subscriber:
                this is the number that decides whether the churn rate is survivable. Cohorts from the last three
                months are still accruing revenue and are marked <span class="font-semibold">immature</span>.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5">Cohort</th>
                        <th class="px-5 py-2.5 text-right">Signed up</th>
                        <th class="px-5 py-2.5 text-right">Still active</th>
                        <th class="px-5 py-2.5 text-right">Retained</th>
                        <th class="px-5 py-2.5 text-right">Median tenure</th>
                        <th class="px-5 py-2.5 text-right">Total earned</th>
                        <th class="px-5 py-2.5">Value / subscriber</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in cohortValue.rows" :key="row.cohort">
                        <tr class="transition hover:bg-slate-50/60">
                            <td class="whitespace-nowrap px-5 py-2.5 font-medium text-slate-700">
                                <span x-text="cohortMonthLabel(row.cohort)"></span>
                                <span x-show="row.immature"
                                      class="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">immature</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="row.size"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-emerald-600" x-text="row.still_active"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="`${row.retained_pct}%`"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-500"
                                x-text="row.median_tenure_days === null ? '—' : `${row.median_tenure_days}d`"></td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600" x-text="money(row.total_spend)"></td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                        <span class="block h-full rounded-full bg-linear-to-r from-emerald-400 to-teal-500"
                                              :style="`width: ${valueBarWidth(row)}`"></span>
                                    </span>
                                    <span class="w-16 text-right font-bold tabular-nums text-slate-900"
                                          x-text="money(row.value_per_subscriber)"></span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============ Cohort retention ============ --}}
    <div class="mt-8 print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"
         x-show="cohorts.rows.length" x-cloak>
        <div class="flex items-baseline justify-between">
            <div>
                <h2 class="text-base font-bold tracking-tight text-slate-900">Cohort Retention</h2>
                <p class="mt-0.5 text-xs text-slate-500">Of subscribers who signed up each month, the % with a completed order N months later.</p>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full border-separate border-spacing-1 text-center text-xs">
                <thead>
                    <tr class="text-slate-400">
                        <th class="px-2 py-1 text-left font-semibold">Cohort</th>
                        <th class="px-2 py-1 font-semibold">Subs</th>
                        <template x-for="o in cohorts.offsets" :key="o">
                            <th class="px-2 py-1 font-semibold" x-text="'M' + o"></th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in cohorts.rows" :key="row.cohort">
                        <tr>
                            <td class="whitespace-nowrap px-2 py-1 text-left font-semibold text-slate-700" x-text="cohortMonthLabel(row.cohort)"></td>
                            <td class="px-2 py-1 font-medium text-slate-500" x-text="row.size"></td>
                            <template x-for="(cell, idx) in row.cells" :key="idx">
                                <td class="rounded-md px-2 py-1.5 font-semibold tabular-nums" :style="cohortStyle(cell.pct)"
                                    x-text="cell.pct > 0 ? cell.pct + '%' : '·'"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-400">M0 = sign-up month. Darker = higher retention.</p>
    </div>
</div>
    </div>

    {{-- =================== EMAIL =================== --}}
    {{-- Klaviyo has its own view: it is the only section fed by a live API
         rather than the CSV, and it answers a different question from the
         order metrics it used to sit under. --}}
    <div x-show="view === 'email'" x-cloak>
    {{-- ============ Email Performance (Klaviyo) ============ --}}
    @php
        // [key, label, icon, gradient, tooltip, revenue-subtext-key]
        $klaviyoTiles = [
            ['delivery_rate', 'Delivery Rate', $icons['check_circle'], 'from-emerald-400 to-teal-500', null, null],
            ['open_rate', 'Open Rate', $icons['users'], 'from-sky-400 to-blue-500', 'Apple Mail Privacy Protection auto-opens messages, so open rate can read higher than reality.', null],
            ['click_rate', 'Click Rate', $icons['arrow_path'], 'from-indigo-400 to-violet-500', null, null],
            ['revenue', 'Revenue', $icons['dollar'], 'from-emerald-400 to-teal-500', 'Total order revenue (Placed Order) attributed to campaigns.', null],
            ['conversions', 'Orders', $icons['check_badge'], 'from-fuchsia-400 to-purple-500', 'Orders (Placed Order) attributed to campaigns.', null],
            ['subscribers', 'New Subscribers', $icons['user_plus'], 'from-violet-400 to-fuchsia-500', 'New profiles that joined the newsletter list during the selected period (by list join date).', null],
            ['sub_created_conversions', 'Subscriptions from Email', $icons['arrow_path'], 'from-indigo-400 to-violet-500', 'WC Subscription Created events attributed to campaigns — new subscriptions driven by email.', 'sub_created_revenue'],
            ['sub_renewal_conversions', 'Renewals from Email', $icons['arrow_path'], 'from-emerald-400 to-teal-500', 'WC Subscription Renewal events attributed to campaigns.', 'sub_renewal_revenue'],
        ];
    @endphp
    <div class="mb-3 mt-8 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="h-5 w-1.5 rounded-full bg-linear-to-b from-indigo-400 to-violet-500"></span>
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-600">Email Performance · Klaviyo</h2>
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-400">
            <span x-show="klaviyo.syncedAt" x-cloak>Synced <span x-text="klaviyo.syncedAt ? new Date(klaviyo.syncedAt).toLocaleString() : ''"></span></span>
            <span x-show="klaviyo.revision" x-cloak>· rev&nbsp;<span x-text="klaviyo.revision"></span></span>
            <button @click="refreshKlaviyo()" :disabled="klaviyoRefreshing"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50">
                <svg class="h-3.5 w-3.5" :class="klaviyoRefreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                <span x-text="klaviyoRefreshing ? 'Refreshing…' : 'Refresh now'"></span>
            </button>
        </div>
    </div>

    {{-- Not connected --}}
    <template x-if="klaviyo.state === 'not_configured'">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Klaviyo isn’t connected. Set <code class="rounded bg-amber-100 px-1">KLAVIYO_API_KEY</code> and
            <code class="rounded bg-amber-100 px-1">KLAVIYO_LIST_ID</code> in your <code class="rounded bg-amber-100 px-1">.env</code>, then click Refresh now.
        </div>
    </template>

    {{-- Sync failed / error --}}
    <template x-if="klaviyo.state === 'failed' || klaviyo.state === 'error'">
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <span class="font-semibold">Klaviyo sync failed.</span>
            <span x-text="klaviyo.error || 'Unknown error.'"></span>
        </div>
    </template>

    @php
        $klaviyoFlowTiles = [
            ['flow_delivery_rate', 'Delivery Rate', $icons['check_circle'], 'from-emerald-400 to-teal-500', null, null],
            ['flow_open_rate', 'Open Rate', $icons['users'], 'from-sky-400 to-blue-500', 'Apple Mail Privacy Protection auto-opens messages, so open rate can read higher than reality.', null],
            ['flow_click_rate', 'Click Rate', $icons['arrow_path'], 'from-indigo-400 to-violet-500', null, null],
            ['flow_revenue', 'Revenue', $icons['dollar'], 'from-emerald-400 to-teal-500', 'Order revenue attributed to automated flows.', null],
            ['flow_conversions', 'Orders', $icons['check_badge'], 'from-fuchsia-400 to-purple-500', 'Orders attributed to automated flows.', null],
            ['flow_sub_created_conversions', 'Subscriptions from Flows', $icons['arrow_path'], 'from-indigo-400 to-violet-500', 'WC Subscription Created events attributed to automated flows (e.g. welcome series).', 'flow_sub_created_revenue'],
            ['flow_sub_renewal_conversions', 'Renewals from Flows', $icons['arrow_path'], 'from-emerald-400 to-teal-500', 'WC Subscription Renewal events attributed to automated flows.', 'flow_sub_renewal_revenue'],
        ];
    @endphp

    {{-- Campaigns --}}
    <div x-show="['ok','syncing','loading','pending'].includes(klaviyo.state)">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Campaigns</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($klaviyoTiles as [$k, $label, $icon, $grad, $tip, $sub])
                @include('dashboard.partials.klaviyo-tile', compact('k', 'label', 'icon', 'grad', 'tip', 'sub'))
            @endforeach
        </div>

        {{-- Flows --}}
        <p class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wider text-slate-400">Automated Flows</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($klaviyoFlowTiles as [$k, $label, $icon, $grad, $tip, $sub])
                @include('dashboard.partials.klaviyo-tile', compact('k', 'label', 'icon', 'grad', 'tip', 'sub'))
            @endforeach
        </div>
    </div>
    <p x-show="klaviyo.state === 'syncing'" x-cloak class="mt-2 text-xs text-slate-400">Fetching the latest snapshot from Klaviyo…</p>
    </div>

    </div>

@endif
@endsection
