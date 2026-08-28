{{--
    Tier-2 stat chip: diagnostic detail, deliberately quieter than a card.

    Same definition shape as card.blade.php. No icon, half the height, label and
    value on one line — so a section reads as "two things that matter, then some
    supporting numbers" instead of eight equal boxes.

    A zero here dims itself: an empty count is not news and should not draw the
    eye the way a real number does.
--}}
@php
    $m = $m ?? [];
    $label = $m['label'] ?? '';
    $dir = $m['dir'] ?? 'flat';
    $note = $m['note'] ?? null;
    $help = $m['help'] ?? null;
@endphp
<div class="rounded-lg border border-slate-200/70 bg-white px-3.5 py-2.5 shadow-sm transition hover:border-slate-300"
     :class="isZero('{{ $key }}') ? 'opacity-55' : ''">
    <div class="flex items-baseline justify-between gap-2">
        <span class="flex min-w-0 items-center gap-1 text-[12px] font-medium text-slate-500">
            <span class="truncate">{{ $label }}</span>
            @if ($help)
                <span class="relative shrink-0" x-data="{ tip: false }"
                      @mouseenter="tip = true" @mouseleave="tip = false">
                    <svg class="h-3 w-3 text-slate-300 transition hover:text-slate-500" fill="none"
                         viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                    </svg>
                    <span x-show="tip" x-cloak
                          class="absolute left-1/2 top-5 z-30 w-60 -translate-x-1/2 rounded-lg bg-slate-800 px-3 py-2 text-[11px] font-normal leading-relaxed text-white shadow-xl">
                        {{ $help }}
                    </span>
                </span>
            @endif
        </span>
        <span class="shrink-0 text-[17px] font-bold tabular-nums tracking-tight"
              :class="isZero('{{ $key }}') ? 'text-slate-400' : '{{ $dir === 'bad' ? 'text-rose-600' : 'text-slate-900' }}'"
              x-text="value('{{ $key }}')"></span>
    </div>
    @if ($note)
        <p class="mt-0.5 truncate text-[10px] text-slate-400" x-text="{{ $note }}"></p>
    @endif
    <div x-show="compare" x-cloak class="mt-1">
        <span :class="changeBadgeClass('{{ $key }}')"
              class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold">
            <span x-text="changeArrow('{{ $key }}')"></span>
            <span x-text="changeLabel('{{ $key }}')"></span>
        </span>
    </div>
</div>
