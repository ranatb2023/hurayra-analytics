{{--
    A single premium KPI tile (horizontal layout — fills wide columns cleanly).
    Expects: $key, $label, $icon (svg path "d"), $bar (gradient classes e.g. "from-indigo-400 to-violet-500").
    Reads its value/comparison from the Alpine `dashboard` scope.
--}}
@php
    $icon ??= 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z';
    $bar ??= 'from-slate-300 to-slate-400';
@endphp
<div class="group flex items-center gap-4 rounded-2xl border border-slate-200/70 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-slate-300/70 hover:shadow-lg hover:shadow-slate-200/60">
    <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br {{ $bar }} text-white shadow-md transition group-hover:scale-105">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
        </svg>
    </span>

    <div class="min-w-0 flex-1">
        <dt class="truncate text-[13px] font-medium text-slate-500">{{ $label }}</dt>
        <dd class="mt-0.5 text-[26px] font-bold leading-tight tracking-tight text-slate-900" x-text="value('{{ $key }}')"></dd>
    </div>

    <span x-show="compare" x-cloak
          :class="changeBadgeClass('{{ $key }}')"
          class="inline-flex shrink-0 items-center gap-0.5 self-start rounded-full px-2 py-0.5 text-xs font-semibold">
        <span x-text="changeArrow('{{ $key }}')"></span>
        <span x-text="changeLabel('{{ $key }}')"></span>
    </span>
</div>
