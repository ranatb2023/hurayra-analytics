{{-- A Klaviyo metric tile. Expects $k, $label, $icon, $grad, $tip, $sub (all from the loop). --}}
<div class="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-sm">
    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-linear-to-br {{ $grad }} text-white shadow-sm">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
    </span>
    <dt class="mt-3 flex items-center gap-1 text-[12px] font-medium text-slate-500">
        {{ $label }}
        @if ($tip)
            <span x-data="{ t: false }" class="relative inline-flex">
                <button type="button" @mouseenter="t = true" @mouseleave="t = false" @click="t = !t" class="text-slate-300 hover:text-slate-500">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                </button>
                <span x-show="t" x-cloak class="absolute left-1/2 top-6 z-30 w-52 -translate-x-1/2 rounded-lg bg-slate-800 px-3 py-2 text-[11px] font-normal leading-snug text-white shadow-lg">{{ $tip }}</span>
            </span>
        @endif
    </dt>
    <dd class="mt-0.5 text-2xl font-bold tracking-tight text-slate-900"
        :class="(klaviyo.state === 'syncing' || klaviyo.state === 'loading') ? 'animate-pulse text-slate-300' : ''"
        x-text="klaviyoValue('{{ $k }}')"></dd>
    @if ($sub)
        <dd class="mt-0.5 text-xs font-medium text-emerald-600" x-show="klaviyo.tiles && klaviyo.tiles['{{ $sub }}'] != null" x-cloak>
            <span x-text="klaviyoValue('{{ $sub }}')"></span> revenue
        </dd>
    @endif
</div>
