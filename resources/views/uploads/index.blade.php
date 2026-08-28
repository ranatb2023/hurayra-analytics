@extends('layouts.app')

@section('title', 'Upload CSV — Hurayra Analytics')
@section('heading', 'Upload CSV')

@section('content')
<div class="grid gap-8 lg:grid-cols-5">
    {{-- ===== Upload form ===== --}}
    <div class="lg:col-span-2">
        <h1 class="text-xl font-bold tracking-tight text-slate-900">Upload orders CSV</h1>
        <p class="mt-1 text-sm text-slate-500">
            MySQL export of <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[12px]">wp_wc_orders</code> — orders &amp; subscriptions under HPOS.
        </p>

        @if ($errors->any())
            <div class="mt-4 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <span>{{ $errors->first('file') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" class="mt-4"
              x-data="{ name: '', dragging: false }">
            @csrf
            <label
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="dragging = false; $refs.file.files = $event.dataTransfer.files; name = $refs.file.files[0]?.name ?? ''"
                :class="dragging ? 'border-indigo-500 bg-indigo-50/70 scale-[1.01]' : 'border-slate-300 bg-white hover:border-indigo-300 hover:bg-slate-50'"
                class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-12 text-center transition">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                </span>
                <span class="mt-4 text-sm font-semibold text-slate-700">
                    <span x-show="!name">Drag &amp; drop a CSV, or <span class="text-indigo-600">click to browse</span></span>
                    <span x-show="name" x-text="name" class="text-indigo-700"></span>
                </span>
                <span class="mt-1 text-xs text-slate-400">.csv up to 200 MB</span>
                <input type="file" name="file" accept=".csv,text/csv" x-ref="file" class="hidden"
                       @change="name = $refs.file.files[0]?.name ?? ''">
            </label>

            <button type="submit" :disabled="!name"
                    class="mt-4 w-full rounded-xl bg-linear-to-br from-indigo-500 to-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:shadow-xl hover:shadow-indigo-500/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:shadow-none">
                Upload &amp; import
            </button>
        </form>

        <div class="mt-6 rounded-2xl border border-slate-200/70 bg-white p-4 shadow-sm">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500">Required columns</h2>
            <code class="mt-2 block wrap-break-word text-xs leading-relaxed text-slate-600">{{ implode(', ', $expectedColumns) }}</code>
            <p class="mt-2 text-xs text-slate-400">
                Unrecognised columns are ignored, but several optional ones are <em>read</em> when present:
                <span class="font-mono text-slate-500">ended_at</span>,
                <span class="font-mono text-slate-500">utm_source</span>,
                <span class="font-mono text-slate-500">utm_medium</span>,
                <span class="font-mono text-slate-500">utm_campaign</span>,
                <span class="font-mono text-slate-500">device_type</span>,
                <span class="font-mono text-slate-500">billing_period</span>,
                <span class="font-mono text-slate-500">billing_interval</span>,
                <span class="font-mono text-slate-500">next_payment_at</span>,
                <span class="font-mono text-slate-500">coupon_code</span>,
                <span class="font-mono text-slate-500">primary_product</span>.
                Use <span class="font-mono text-slate-500">03-export-with-attribution.sql</span> to include them.
            </p>
        </div>
    </div>

    {{-- ===== Import history ===== --}}
    <div class="lg:col-span-3">
        <h2 class="text-xl font-bold tracking-tight text-slate-900">Import history</h2>
        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">File</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Rows</th>
                        <th class="px-4 py-3 text-right">Imported</th>
                        <th class="px-4 py-3 text-right">Skipped</th>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($imports as $import)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $import->original_filename }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $badge = match ($import->status) {
                                        'completed' => 'bg-emerald-100 text-emerald-700',
                                        'failed' => 'bg-rose-100 text-rose-700',
                                        default => 'bg-amber-100 text-amber-700',
                                    };
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">{{ $import->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($import->total_rows) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($import->imported_rows) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if ($import->skipped_rows > 0)
                                    <span class="text-rose-600">{{ number_format($import->skipped_rows) }}</span>
                                @else
                                    0
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $import->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('uploads.destroy', $import) }}"
                                      onsubmit="return confirm('Delete this import and all its records?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-medium text-rose-600 hover:text-rose-800">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @if ($import->status === 'failed' && $import->error_log)
                            <tr class="bg-rose-50/50">
                                <td colspan="7" class="px-4 py-2 text-xs text-rose-700">
                                    {{ \Illuminate\Support\Str::limit(json_encode($import->error_log), 300) }}
                                </td>
                            </tr>
                        @elseif ($import->skipped_rows > 0)
                            <tr class="bg-amber-50/40">
                                <td colspan="7" class="px-4 py-2 text-xs text-amber-700">
                                    {{ $import->skipped_rows }} row(s) skipped (missing/invalid id or record_type, or unparseable values).
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">No imports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-500">
            Re-uploading the same file is safe — rows upsert on their WooCommerce <code>id</code>, so nothing duplicates.
        </p>
    </div>
</div>
@endsection
