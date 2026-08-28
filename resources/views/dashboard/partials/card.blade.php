{{--
    Tier-1 KPI card: a number you would act on.

    Expects $key and a $m definition (see the metric table in index.blade.php):
    label, icon, dir, note, help. Optional $action = Alpine expression to run on
    click, which makes the whole card a button.

    Colour is semantic, never decorative: the icon tint says whether the metric
    is a good thing or a bad thing, so a reader can rank a section without
    reading it. Anything neutral stays grey.
--}}
@php
    $m = $m ?? [];
    $label = $m['label'] ?? ($label ?? '');
    $icon = $m['icon'] ?? '';
    $dir = $m['dir'] ?? 'flat';
    $note = $m['note'] ?? null;
    $help = $m['help'] ?? null;
    $action = $action ?? null;

    $tint = match ($dir) {
        'good' => 'bg-emerald-50 text-emerald-600',
        'bad' => 'bg-rose-50 text-rose-600',
        default => 'bg-slate-100 text-slate-500',
    };
@endphp
<{{ $action ? 'button' : 'div' }}
    @if ($action) type="button" @click="{{ $action }}" @endif
    class="group relative flex w-full items-center gap-4 rounded-xl border border-slate-200/80 bg-white p-4 text-left shadow-sm transition duration-200 hover:border-slate-300 hover:shadow-md">

    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $tint }}">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
        </svg>
    </span>

    <div class="min-w-0 flex-1">
        <dt class="flex items-center gap-1 text-[13px] font-medium text-slate-500">
            <span class="truncate">{{ $label }}</span>
            @if ($help)
                {{-- The explanation lives on the number it explains. --}}
                <span class="relative shrink-0" x-data="{ tip: false }"
                      @mouseenter="tip = true" @mouseleave="tip = false">
                    <svg class="h-3.5 w-3.5 text-slate-300 transition hover:text-slate-500" fill="none"
                         viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                    </svg>
                    <span x-show="tip" x-cloak
                          class="absolute left-1/2 top-6 z-30 w-64 -translate-x-1/2 rounded-lg bg-slate-800 px-3 py-2 text-[11px] font-normal leading-relaxed text-white shadow-xl">
                        {{ $help }}
                    </span>
                </span>
            @endif
        </dt>
        <dd class="mt-0.5 text-[26px] font-bold leading-tight tracking-tight text-slate-900" x-text="value('{{ $key }}')"></dd>
        @if ($note)
            <p class="mt-0.5 truncate text-[11px] text-slate-400" x-text="{{ $note }}"></p>
        @endif
    </div>

    @if ($action)
        <svg class="h-4 w-4 shrink-0 self-center text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500"
             fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
    @endif

    <span x-show="compare" x-cloak
          :class="changeBadgeClass('{{ $key }}')"
          class="inline-flex shrink-0 items-center gap-0.5 self-start rounded-full px-2 py-0.5 text-xs font-semibold">
        <span x-text="changeArrow('{{ $key }}')"></span>
        <span x-text="changeLabel('{{ $key }}')"></span>
    </span>
</{{ $action ? 'button' : 'div' }}>
