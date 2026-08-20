<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Report — {{ $period->label }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon.svg') }}">
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        @media print { .no-print { display: none !important; } body { background: #fff; } }
    </style>
</head>
@php
    $gbp = fn ($v) => '£'.number_format((float) $v, 2);
    $subscriptionRows = [
        ['New Subscribers', $m['new_subscribers']],
        ['Active Subscribers', $m['subscribers_active']],
        ['Pending Cancellation', $m['pending_cancellation']],
        ['On Hold', $m['on_hold']],
        ['Cancelled · No Purchase', $m['cancelled_without_purchase']],
        ['Cancelled · Purchased', $m['cancelled_with_purchase']],
    ];
    $pct = fn ($v) => $v === null ? '—' : $v.'%';
    $retentionRows = [
        ['Monthly Churn Rate', $pct($m['monthly_churn_rate'] ?? null)],
        ['Subscribers Lost', number_format($m['churned_in_period'] ?? 0)],
        ['Active at Period Start', number_format($m['active_at_period_start'] ?? 0)],
        ['Renewal Success', $pct($m['renewal_success_rate'] ?? null)],
    ];
    $orderRows = [
        ['One-time Purchase', $m['one_time_purchase']],
        ['Subscription Purchases', $m['subscription_purchases']],
        ['Renewal Purchases', $m['renewal_purchases']],
        ['Completed', $m['completed']],
        ['New (not completed)', $m['new_not_completed']],
    ];
@endphp
<body class="h-full text-slate-800 antialiased">

    {{-- Toolbar (screen only) --}}
    <div class="no-print sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-3">
        <span class="text-sm font-medium text-slate-500">Client report preview</span>
        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Back to dashboard</a>
            <button onclick="window.print()" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">Download / Save as PDF</button>
        </div>
    </div>

    <div class="mx-auto my-6 max-w-3xl bg-white p-8 shadow-sm print:my-0 print:max-w-none print:p-0 print:shadow-none">

        {{-- Header --}}
        <div class="mb-6 flex items-start justify-between border-b border-slate-200 pb-5">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-xl bg-linear-to-br from-indigo-600 to-violet-700 px-3 py-2.5">
                    <img src="{{ asset('assets/img/Hurayra-svg-updated.webp') }}" alt="Hurayra" class="h-6 w-auto">
                </span>
            </div>
            <div class="text-right text-sm">
                <p class="font-semibold text-slate-800">{{ $period->label }}</p>
                <p class="text-slate-400">Generated {{ $generatedAt->format('M j, Y') }}</p>
            </div>
        </div>

        {{-- Key figures --}}
        <div class="mb-6 grid grid-cols-4 gap-3">
            @php $keyFigures = [['Total Revenue', $gbp($m['total_revenue'])], ['Active Subscribers', number_format($m['subscribers_active'])], ['New Subscribers', number_format($m['new_subscribers'])], ['Completed Orders', number_format($m['completed'])]]; @endphp
            @foreach ($keyFigures as $kf)
                <div class="rounded-xl bg-linear-to-br from-indigo-600 to-violet-700 p-4 text-white">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-indigo-200">{{ $kf[0] }}</p>
                    <p class="mt-1 text-xl font-bold">{{ $kf[1] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Subscriptions --}}
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Subscriptions</h2>
        <div class="mb-6 grid grid-cols-3 gap-3">
            @foreach ($subscriptionRows as $row)
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[12px] font-medium text-slate-500">{{ $row[0] }}</p>
                    <p class="mt-0.5 text-2xl font-bold text-slate-900">{{ number_format($row[1]) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Retention & churn --}}
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Retention &amp; Churn</h2>
        <div class="mb-2 grid grid-cols-4 gap-3">
            @foreach ($retentionRows as $row)
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[12px] font-medium text-slate-500">{{ $row[0] }}</p>
                    <p class="mt-0.5 text-2xl font-bold text-slate-900">{{ $row[1] }}</p>
                </div>
            @endforeach
        </div>
        <p class="mb-6 text-[11px] text-slate-400">
            Subscriber counts are taken from each subscription's sign-up and end dates, so a closed month keeps the
            figure it had at the time — a later cancellation does not remove anyone from it.
        </p>

        {{-- Orders --}}
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Orders</h2>
        <div class="mb-6 grid grid-cols-3 gap-3">
            @foreach ($orderRows as $row)
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="text-[12px] font-medium text-slate-500">{{ $row[0] }}</p>
                    <p class="mt-0.5 text-2xl font-bold text-slate-900">{{ number_format($row[1]) }}</p>
                </div>
            @endforeach
        </div>

        {{-- New (not completed) breakdown --}}
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">“New (not completed)” breakdown</h2>
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex flex-wrap gap-2">
                @foreach (($m['not_completed_breakdown'] ?? []) as $status => $count)
                    <span class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm">
                        <span class="capitalize text-slate-600">{{ $status }}</span>
                        <span class="rounded bg-white px-2 py-0.5 text-xs font-bold text-slate-900">{{ number_format($count) }}</span>
                    </span>
                @endforeach
            </div>
            <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                Standard total (≠ completed): <span class="font-bold text-slate-700">{{ number_format($m['new_not_completed_standard']) }}</span>
                <span class="mx-1.5 text-slate-300">|</span>
                Strict total (pending + processing): <span class="font-bold text-slate-700">{{ number_format($m['new_not_completed_strict']) }}</span>
            </p>
        </div>

        <p class="mt-8 text-center text-[11px] text-slate-400">Hurayra Analytics · {{ $period->label }} · generated {{ $generatedAt->format('M j, Y g:i A') }}</p>
    </div>

    {{-- Auto-open the print/save dialog for a one-click download. --}}
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
</body>
</html>
