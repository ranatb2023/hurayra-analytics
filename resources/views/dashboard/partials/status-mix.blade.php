{{--
    Subscription mix.

    Was a doughnut of all six statuses, which meant 74% of it was `cancelled` —
    a cumulative lifetime count sitting alongside point-in-time live states. That
    chart can only get less informative every month, because the pink arc never
    shrinks, and it buried the number that actually matters (how many subscribers
    are live right now) under the number that never changes.

    So the live book is the subject: a segmented bar of the states a subscription
    can currently be in, and everything that has ever ended reported underneath
    as context rather than as a competing slice.

    Params: $compact — true on the Overview, where vertical space is tight.
--}}
@php $compact ??= false; @endphp

<div>
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Live book</p>
            <p class="text-3xl font-bold tracking-tight text-slate-900" x-text="liveBookTotal()"></p>
        </div>
        <p class="text-right text-[11px] text-slate-400" x-text="periodEndNote()"></p>
    </div>

    {{-- One bar, proportional. Comparing arcs of a ring is harder than
         comparing lengths on a shared baseline, and this fits in a third of
         the height. --}}
    <div class="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
        <template x-for="row in liveStatusRows()" :key="row.status">
            <div class="h-full transition-all duration-300 first:rounded-l-full last:rounded-r-full"
                 :style="`width: ${row.pct}%; background: ${row.color}`"
                 :title="`${row.label}: ${row.count}`"></div>
        </template>
    </div>

    <div class="mt-3 space-y-1.5">
        <template x-for="row in liveStatusRows()" :key="row.status">
            <div class="flex items-center gap-2.5 text-sm">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="`background: ${row.color}`"></span>
                <span class="flex-1 truncate capitalize text-slate-600" x-text="row.label"></span>
                <span class="w-12 text-right font-semibold tabular-nums text-slate-900" x-text="row.count"></span>
                <span class="w-12 text-right text-xs tabular-nums text-slate-400" x-text="`${row.pct}%`"></span>
            </div>
        </template>
        <p x-show="! liveStatusRows().length" x-cloak class="py-2 text-sm text-slate-400">
            No live subscriptions in this period.
        </p>
    </div>

    {{-- Ended subscriptions are cumulative, so they are reported, not plotted. --}}
    <div class="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
        <span class="text-xs text-slate-500">
            Ended all-time
            @unless ($compact)
                <span class="text-slate-400">· cumulative, so not comparable with the live states above</span>
            @endunless
        </span>
        <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-500" x-text="endedStatusTotal()"></span>
    </div>
</div>
