@extends('layouts.app')

@section('title', 'Channel rules — Hurayra Analytics')
@section('heading', 'Channel rules')

@section('content')
@php
    $input = 'w-full rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp
<div class="mx-auto max-w-5xl space-y-6">

    <div class="rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-bold text-slate-700">How raw sources become channels</h2>
        <p class="mt-1 text-xs text-slate-500">
            Rules run in priority order (lowest first). A rule matches when the subscription's
            <code class="font-mono">utm_source</code> is in its source list and, if the rule lists mediums, its
            <code class="font-mono">utm_medium</code> is in that list. With no utm_source, WooCommerce's source type
            (<code class="font-mono">typein</code>, <code class="font-mono">organic</code>…) is matched instead.
            Anything no rule matches is reported as <span class="font-semibold">Other</span>. Matching ignores case.
        </p>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ implode(' ', $errors->all()) }}
        </div>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="w-20 px-4 py-2.5">Priority</th>
                    <th class="w-44 px-4 py-2.5">Channel</th>
                    <th class="min-w-[24rem] px-4 py-2.5">Sources (comma-separated)</th>
                    <th class="w-52 px-4 py-2.5">Mediums <span class="font-normal normal-case">(blank = any)</span></th>
                    <th class="w-36 px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rules as $rule)
                    <tr>
                        <td colspan="5" class="p-0">
                            <form method="POST" action="{{ route('channels.update', $rule) }}" class="flex items-center gap-0">
                                @csrf
                                @method('PUT')
                                <div class="w-20 px-4 py-2"><input name="priority" type="number" value="{{ $rule->priority }}" class="{{ $input }}"></div>
                                <div class="w-44 px-4 py-2"><input name="channel" value="{{ $rule->channel }}" class="{{ $input }}"></div>
                                <div class="min-w-[24rem] flex-1 px-4 py-2"><input name="sources" value="{{ $rule->sources }}" title="{{ $rule->sources }}" aria-label="Sources for {{ $rule->channel }}" class="{{ $input }}"></div>
                                <div class="w-52 px-4 py-2"><input name="mediums" value="{{ $rule->mediums }}" class="{{ $input }}"></div>
                                <div class="flex w-36 items-center gap-1.5 px-4 py-2">
                                    <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">Save</button>
                                    <button form="delete-{{ $rule->id }}" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50"
                                            onclick="return confirm('Delete the rule for {{ e($rule->channel) }}?')">Delete</button>
                                </div>
                            </form>
                            <form id="delete-{{ $rule->id }}" method="POST" action="{{ route('channels.destroy', $rule) }}" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                        </td>
                    </tr>
                @endforeach
                <tr class="bg-slate-50/50">
                    <td colspan="5" class="p-0">
                        <form method="POST" action="{{ route('channels.store') }}" class="flex items-center">
                            @csrf
                            <div class="w-20 px-4 py-2"><input name="priority" type="number" value="{{ ($rules->max('priority') ?? 0) + 10 }}" class="{{ $input }}"></div>
                            <div class="w-44 px-4 py-2"><input name="channel" placeholder="e.g. TikTok" class="{{ $input }}"></div>
                            <div class="min-w-[24rem] flex-1 px-4 py-2"><input name="sources" placeholder="tiktok.com, tiktok" aria-label="Sources" class="{{ $input }}"></div>
                            <div class="w-52 px-4 py-2"><input name="mediums" placeholder="any" class="{{ $input }}"></div>
                            <div class="w-36 px-4 py-2">
                                <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Add rule</button>
                            </div>
                        </form>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
        <div class="border-b border-slate-100 p-5">
            <h2 class="text-sm font-bold text-slate-700">Unmapped sources <span class="font-medium text-slate-400">· reported as Other</span></h2>
            <p class="mt-1 text-xs text-slate-500">Raw values no rule matches yet. Add one to a rule above to give it a channel.</p>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-2.5">Source</th>
                    <th class="px-5 py-2.5">Medium</th>
                    <th class="px-5 py-2.5 text-right">Subscriptions</th>
                    <th class="px-5 py-2.5 text-right">Orders</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($unmapped as $u)
                    <tr>
                        <td class="px-5 py-2 font-mono text-xs text-slate-700">{{ $u['source'] }}</td>
                        <td class="px-5 py-2 font-mono text-xs text-slate-500">{{ $u['medium'] ?? '(none)' }}</td>
                        <td class="px-5 py-2 text-right tabular-nums text-slate-600">{{ number_format($u['subscriptions']) }}</td>
                        <td class="px-5 py-2 text-right tabular-nums text-slate-600">{{ number_format($u['orders']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-6 text-center text-sm text-slate-400">Every source is mapped.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
