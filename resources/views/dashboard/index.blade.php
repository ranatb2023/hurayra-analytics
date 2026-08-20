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

    // key => [label, icon, icon-chip classes, top-bar gradient]
    $subscriptionCards = [
        'new_subscribers' => ['New Subscribers', $icons['user_plus'], 'bg-indigo-50 text-indigo-600', 'from-indigo-400 to-violet-500'],
        // The caption matters: this counts status `active` at one instant. A
        // report that includes on-hold, or that was snapshotted mid-period, will
        // legitimately read higher — see `php artisan subs:explain`.
        'subscribers_active' => ['Active Subscribers', $icons['users'], 'bg-emerald-50 text-emerald-600', 'from-emerald-400 to-teal-500', "periodEndNote()"],
        'pending_cancellation' => ['Pending Cancellation', $icons['clock'], 'bg-amber-50 text-amber-600', 'from-amber-400 to-orange-500'],
        'on_hold' => ['On Hold', $icons['pause'], 'bg-sky-50 text-sky-600', 'from-sky-400 to-cyan-500'],
        'cancelled_without_purchase' => ['Cancelled · No Purchase', $icons['x_circle'], 'bg-rose-50 text-rose-600', 'from-rose-400 to-pink-500'],
        'cancelled_with_purchase' => ['Cancelled · Purchased', $icons['check_badge'], 'bg-fuchsia-50 text-fuchsia-600', 'from-fuchsia-400 to-purple-500'],
    ];
    $orderCards = [
        'one_time_purchase' => ['One-time Purchase', $icons['bag'], 'bg-sky-50 text-sky-600', 'from-sky-400 to-blue-500'],
        'subscription_purchases' => ['Subscription Purchases', $icons['arrow_path'], 'bg-indigo-50 text-indigo-600', 'from-indigo-400 to-violet-500'],
        'renewal_purchases' => ['Renewal Purchases', $icons['arrow_path'], 'bg-violet-50 text-violet-600', 'from-violet-400 to-purple-500'],
        'completed' => ['Completed', $icons['check_circle'], 'bg-emerald-50 text-emerald-600', 'from-emerald-400 to-green-500'],
        'new_not_completed' => ['New (not completed)', $icons['exclaim'], 'bg-amber-50 text-amber-600', 'from-amber-400 to-orange-500'],
    ];
    $totalCards = [
        'total_revenue' => ['Total Revenue', $icons['dollar'], 'bg-emerald-50 text-emerald-600', 'from-emerald-400 to-teal-500'],
        'average_order_value' => ['Avg Order Value', $icons['calc'], 'bg-teal-50 text-teal-600', 'from-teal-400 to-cyan-500'],
        'subscription_revenue' => ['Subscription Revenue', $icons['arrow_path'], 'bg-indigo-50 text-indigo-600', 'from-indigo-400 to-violet-500'],
        'one_time_revenue' => ['One-time Revenue', $icons['bag'], 'bg-sky-50 text-sky-600', 'from-sky-400 to-blue-500'],
        'active_cancelled_ratio' => ['Active : Cancelled', $icons['scale'], 'bg-violet-50 text-violet-600', 'from-violet-400 to-fuchsia-500'],
    ];
    $retentionCards = [
        'monthly_churn_rate' => ['Monthly Churn', $icons['x_circle'], '', 'from-rose-400 to-pink-500'],
        'churn_rate' => ['Lifetime Churn', $icons['scale'], '', 'from-rose-400 to-orange-500'],
        'renewal_success_rate' => ['Renewal Success', $icons['arrow_path'], '', 'from-emerald-400 to-teal-500'],
        'revenue_at_risk' => ['Revenue at Risk', $icons['exclaim'], '', 'from-amber-400 to-orange-500'],
        'failed_renewals' => ['Failed Renewals', $icons['x_circle'], '', 'from-rose-400 to-red-500'],
    ];
    $customerCards = [
        'unique_customers' => ['Unique Customers', $icons['users'], '', 'from-indigo-400 to-violet-500'],
        'new_customers' => ['New Customers', $icons['user_plus'], '', 'from-sky-400 to-blue-500'],
        'returning_customers' => ['Returning Customers', $icons['arrow_path'], '', 'from-emerald-400 to-teal-500'],
        'repeat_rate' => ['Repeat Rate', $icons['check_badge'], '', 'from-violet-400 to-fuchsia-500'],
        'revenue_per_customer' => ['Revenue / Customer', $icons['dollar'], '', 'from-teal-400 to-cyan-500'],
    ];
@endphp

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
<div x-data="dashboard({ defaultYear: {{ (int) $years[0] }}, trendMetrics: @js($trendMetrics), years: @js($years) })" x-cloak>

    {{-- ============ Hero band ============ --}}
    <div class="relative overflow-hidden rounded-2xl bg-linear-to-br from-indigo-600 via-indigo-700 to-violet-800 p-6 text-white shadow-xl shadow-indigo-500/20 sm:p-7">
        <div class="pointer-events-none absolute -right-12 -top-16 h-56 w-56 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute bottom-0 right-1/3 h-40 w-40 rounded-full bg-fuchsia-400/20 blur-3xl"></div>
        <div class="relative">
            <div class="flex items-center gap-2 text-indigo-100">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,0.25)]"></span>
                <span class="text-sm font-medium">Showing <span class="font-semibold text-white" x-text="periodLabel || '…'"></span></span>
                <span x-show="loading" x-cloak class="text-xs text-indigo-200">· updating…</span>
            </div>
            <div class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-4">
                @foreach (['total_revenue' => 'Total Revenue', 'subscribers_active' => 'Active Subscribers', 'new_subscribers' => 'New Subscribers', 'completed' => 'Completed Orders'] as $hk => $hl)
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-200">{{ $hl }}</p>
                        <p class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl" x-text="value('{{ $hk }}')"></p>
                        <div class="mt-1 flex items-center gap-2">
                            <span x-show="compare" x-cloak class="inline-flex items-center gap-0.5 rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold text-white backdrop-blur">
                                <span x-text="changeArrow('{{ $hk }}')"></span><span x-text="changeLabel('{{ $hk }}')"></span>
                            </span>
                            <template x-if="sparks['{{ $hk }}'] && sparks['{{ $hk }}'].length > 1">
                                <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-7 w-24 text-white/70">
                                    <path :d="sparkPath(sparks['{{ $hk }}'])" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
                                </svg>
                            </template>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============ Report bar ============ --}}
    <div class="mt-5 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200/70 bg-white p-4 shadow-sm">
        <p class="min-w-0 flex-1 text-sm text-slate-600" x-text="periodSummary()"></p>
        <div class="no-print flex items-center gap-2">
            <button @click="copySummary()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" /></svg>
                <span x-text="copied ? 'Copied!' : 'Copy'"></span>
            </button>
            <a :href="exportHref()"
               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Export CSV
            </a>
            <button @click="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                Print / PDF
            </button>
            <a :href="clientReportHref()" target="_blank"
               class="inline-flex items-center gap-1.5 rounded-lg bg-linear-to-br from-indigo-500 to-violet-600 px-3 py-1.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/30 transition hover:shadow-lg">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                Download report for Client
            </a>
        </div>
    </div>

    {{-- ============ Filter bar ============ --}}
    <div class="mt-5 rounded-2xl border border-slate-200/70 bg-white shadow-sm no-print">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 p-4">
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
                <template x-for="g in [['week','Week'],['month','Month'],['year','Year'],['custom','Custom']]" :key="g[0]">
                    <button type="button" @click="granularity = g[0]"
                            :class="granularity === g[0] ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            class="rounded-lg px-4 py-1.5 text-sm font-semibold transition" x-text="g[1]"></button>
                </template>
            </div>

            <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600">
                <span>Compare to previous</span>
                <span class="relative">
                    <input type="checkbox" x-model="compare" @change="apply()" class="peer sr-only">
                    <span class="block h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600"></span>
                    <span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                </span>
            </label>
        </div>

        <div class="flex flex-wrap items-end gap-3 p-4">
            <div x-show="granularity === 'week' || granularity === 'month'">
                @include('dashboard.partials.dropdown', ['id' => 'month', 'label' => 'Month', 'model' => 'month', 'options' => 'monthOptions()', 'width' => 'w-40'])
            </div>

            <div x-show="granularity !== 'custom'">
                @include('dashboard.partials.dropdown', ['id' => 'year', 'label' => 'Year', 'model' => 'year', 'options' => 'yearOptions()', 'width' => 'w-28'])
            </div>

            <div x-show="granularity === 'week'">
                @include('dashboard.partials.dropdown', ['id' => 'week', 'label' => 'Week of month', 'model' => 'week', 'options' => 'weekOptions()', 'onChange' => 'apply()', 'width' => 'w-48'])
            </div>

            <template x-if="granularity === 'custom'">
                <div class="flex items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500">From</label>
                        <input type="date" x-model="from"
                               class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500">To</label>
                        <input type="date" x-model="to"
                               class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <button @click="apply()"
                            class="rounded-lg bg-linear-to-br from-indigo-500 to-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/30 transition hover:shadow-lg">
                        Apply
                    </button>
                </div>
            </template>

            <div class="ml-auto flex items-center gap-2 self-center text-sm text-slate-400">
                <span x-show="error" x-cloak x-text="error" class="font-medium text-rose-600"></span>
            </div>
        </div>
    </div>

    {{-- ============ Subscriptions ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Subscriptions', 'bar' => 'from-indigo-400 to-violet-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($subscriptionCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'label' => $c[0], 'icon' => $c[1], 'accent' => $c[2], 'bar' => $c[3], 'note' => $c[4] ?? null])
        @endforeach
    </div>

    <p class="mt-3 text-xs text-slate-500">
        <span class="font-semibold text-slate-600">Active Subscribers</span> counts status <code class="font-mono">active</code>
        at a single instant — the end of the selected period. <span class="font-semibold text-slate-600">On Hold</span> and
        <span class="font-semibold text-slate-600">Pending Cancellation</span> are separate states and are <em>not</em> included in it.
        A report that counts those too, or that was taken part-way through the period, will read higher; run
        <code class="font-mono">php artisan subs:explain {{ '{YYYY-MM}' }}</code> to see exactly which subscriptions account for the difference.
    </p>

    {{-- ============ Orders ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Orders', 'bar' => 'from-sky-400 to-blue-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($orderCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'label' => $c[0], 'icon' => $c[1], 'accent' => $c[2], 'bar' => $c[3]])
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

    {{-- ============ Retention & churn ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Retention & Churn', 'bar' => 'from-rose-400 to-pink-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($retentionCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'label' => $c[0], 'icon' => $c[1], 'bar' => $c[3]])
        @endforeach
    </div>

    {{-- Monthly churn maths, spelled out so the headline number is checkable. --}}
    <p class="mt-3 text-xs text-slate-500">
        Monthly churn = <span class="font-bold text-slate-700" x-text="value('churned_in_period')"></span> subscribers lost
        during <span class="font-semibold text-slate-600" x-text="periodLabel"></span>
        ÷ <span class="font-bold text-slate-700" x-text="value('active_at_period_start')"></span> active when it opened.
        <span class="mx-1.5 text-slate-300">|</span>
        Lifetime churn = all cancelled + expired ÷ all subscriptions ever.
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
                            <td class="px-5 py-2.5 font-medium text-slate-700" x-text="cohortMonthLabel(row.month)"></td>
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

    {{-- ============ Customers ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Customers', 'bar' => 'from-indigo-400 to-violet-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($customerCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'label' => $c[0], 'icon' => $c[1], 'bar' => $c[3]])
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

    {{-- ============ One-time buyers who then subscribed ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'One-time → Subscription', 'bar' => 'from-sky-400 to-indigo-500'])
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

    {{-- Breakdown donuts: revenue split + subscription status mix --}}
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Revenue split --}}
        <div class="print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700">Revenue Split <span class="font-medium text-slate-400">· subscription vs one-time</span></h3>
            <div class="mt-4 flex items-center gap-5">
                <div class="relative h-40 w-40 shrink-0">
                    <canvas x-ref="revenueCanvas"></canvas>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-slate-400">Total</span>
                        <span class="text-base font-bold text-slate-900" x-text="value('total_revenue')"></span>
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
            <h3 class="text-sm font-bold text-slate-700">Subscription Status Mix <span class="font-medium text-slate-400">· as of period end</span></h3>
            <div class="mt-4 flex items-center gap-5">
                <div class="relative h-40 w-40 shrink-0">
                    <canvas x-ref="statusCanvas"></canvas>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-slate-400">Subs</span>
                        <span class="text-base font-bold text-slate-900" x-text="value('total_subscriptions')"></span>
                    </div>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                        <template x-for="(count, status) in (metrics.subscription_status_breakdown || {})" :key="status">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="flex min-w-0 items-center gap-2 text-slate-600">
                                    <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="`background: ${statusColors[status] || '#cbd5e1'}`"></span>
                                    <span class="truncate capitalize" x-text="status"></span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-slate-900" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ Supporting totals ============ --}}
    @include('dashboard.partials.section-heading', ['title' => 'Supporting Totals', 'bar' => 'from-emerald-400 to-teal-500'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($totalCards as $key => $c)
            @include('dashboard.partials.card', ['key' => $key, 'label' => $c[0], 'icon' => $c[1], 'accent' => $c[2], 'bar' => $c[3]])
        @endforeach
    </div>

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

    {{-- ============ Trend chart ============ --}}
    <div class="mt-8 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-base font-bold tracking-tight text-slate-900">Trend</h2>
                <p class="mt-0.5 text-xs text-slate-500">Bucketed by <span class="font-medium text-slate-600" x-text="granularity"></span> across all data.</p>
            </div>
            <div class="flex items-end gap-2">
                @include('dashboard.partials.dropdown', ['id' => 'trend', 'label' => 'Metric', 'model' => 'trendMetric', 'options' => 'trendOptions()', 'onChange' => 'fetchTrend()', 'width' => 'w-52'])
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                    <template x-for="t in [['line','Line'],['bar','Bar']]" :key="t[0]">
                        <button type="button" @click="trendType = t[0]; fetchTrend()"
                                :class="trendType === t[0] ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                                class="rounded-md px-3 py-1 text-sm font-medium transition" x-text="t[1]"></button>
                    </template>
                </div>
            </div>
        </div>
        <div class="mt-5 h-80">
            <canvas x-ref="trendCanvas"></canvas>
        </div>
    </div>

    {{-- ============ Cohort retention ============ --}}
    <div class="mt-8 print-block rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm"
         x-show="cohorts && cohorts.rows && cohorts.rows.length" x-cloak>
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
@endif
@endsection
