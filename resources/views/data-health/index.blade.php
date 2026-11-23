@extends('layouts.app')

@section('title', 'Data Health — Hurayra Analytics')
@section('heading', 'Data Health')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <p class="text-sm text-slate-500">
        Rows the metrics leave out, or that could mislead them. Each list can be exported and checked against
        WooCommerce. Nothing here changes any data.
    </p>

    {{-- Summary: one tile per check. --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($sections as $key => $s)
            <a href="#{{ $key }}" class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-slate-300">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $s['title'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $key !== 'status_counts' && $s['count'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">
                    {{ number_format($s['count']) }}
                </p>
            </a>
        @endforeach
    </div>

    @foreach ($sections as $key => $s)
        <section id="{{ $key }}" class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-slate-700">
                        {{ $s['title'] }} <span class="font-medium text-slate-400">· {{ number_format($s['count']) }}</span>
                    </h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $s['description'] }}</p>
                </div>
                <a href="{{ route('data-health.export', $key) }}"
                   class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
                    Export CSV
                </a>
            </div>

            @isset($s['buckets'])
                <div class="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
                    @foreach ($s['buckets'] as $label => $n)
                        <span class="rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
                            {{ $label }} <span class="ml-1 font-bold text-slate-900">{{ $n }}</span>
                        </span>
                    @endforeach
                </div>
            @endisset

            <div class="max-h-96 overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-slate-50/95 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            @foreach ($s['columns'] as $label)
                                <th class="px-5 py-2.5">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse (array_slice($s['rows'], 0, 200) as $row)
                            <tr class="{{ ($row['note'] ?? '') === 'mismatch' ? 'bg-rose-50/60' : '' }}">
                                @foreach (array_keys($s['columns']) as $col)
                                    {{-- Money columns as money: "24.6" read as a count. --}}
                                    <td class="whitespace-nowrap px-5 py-2 text-slate-600 {{ in_array($col, ['total', 'amount', 'value', 'spend'], true) ? 'text-right tabular-nums' : '' }}">{{ in_array($col, ['total', 'amount', 'value', 'spend'], true) && is_numeric($row[$col] ?? null) ? '£'.number_format((float) $row[$col], 2) : ($row[$col] ?? '—') }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($s['columns']) }}" class="px-5 py-6 text-center text-sm text-slate-400">Nothing to report.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($s['count'] > 200)
                <p class="border-t border-slate-100 bg-amber-50 px-5 py-2.5 text-xs text-amber-800">
                    Showing the first 200 of {{ number_format($s['count']) }}. Export the CSV for the full list.
                </p>
            @endif
        </section>
    @endforeach
</div>
@endsection
