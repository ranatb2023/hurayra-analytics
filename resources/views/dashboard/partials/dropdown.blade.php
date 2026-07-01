{{--
    Custom styled dropdown (listbox), bound to a property on the parent `dashboard` scope.
    Params:
      $id      - unique key, used to track which dropdown is open (e.g. 'month')
      $label   - field label
      $model   - parent property name to read/write (e.g. 'month', 'year', 'week', 'trendMetric')
      $options - JS expression returning [{value,label}, …] (e.g. 'monthOptions()')
      $onChange- JS to run after selecting (default: '' — watchers handle the refresh)
      $width   - tailwind width class for the button (default 'w-44')
--}}
@php
    $onChange ??= '';
    $width ??= 'w-44';
@endphp
<div class="relative" @click.outside="openDropdown === '{{ $id }}' && (openDropdown = null)"
     @keydown.escape.window="openDropdown === '{{ $id }}' && (openDropdown = null)">
    <label class="block text-xs font-medium text-slate-500">{{ $label }}</label>

    <button type="button"
            @click="openDropdown = (openDropdown === '{{ $id }}' ? null : '{{ $id }}')"
            class="mt-1 flex {{ $width }} items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
            :class="openDropdown === '{{ $id }}' ? 'border-indigo-500 ring-2 ring-indigo-500/30' : ''">
        <span class="truncate" x-text="labelFor({{ $options }}, {{ $model }})"></span>
        <svg class="h-4 w-4 shrink-0 text-slate-400 transition" :class="openDropdown === '{{ $id }}' ? 'rotate-180' : ''"
             fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div x-show="openDropdown === '{{ $id }}'" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute z-50 mt-2 max-h-72 {{ $width }} origin-top overflow-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-300/40 ring-1 ring-slate-900/5">
        <template x-for="opt in {{ $options }}" :key="opt.value">
            <button type="button"
                    @click="{{ $model }} = opt.value; openDropdown = null; {{ $onChange }}"
                    class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition"
                    :class="{{ $model }} == opt.value ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-700 hover:bg-slate-100'">
                <span class="truncate" x-text="opt.label"></span>
                <svg x-show="{{ $model }} == opt.value" class="h-4 w-4 shrink-0 text-indigo-600"
                     fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </template>
    </div>
</div>
