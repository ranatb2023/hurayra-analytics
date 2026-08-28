{{--
    Styled listbox bound to a property on the parent `dashboard` scope.

    A native <select> cannot be styled consistently across browsers, and binding
    one to x-for options loses its initial value — the select snaps to the first
    entry before the options exist. This renders a button plus a panel instead,
    so neither problem applies.

    Params:
      $id       unique key tracking which dropdown is open (e.g. 'month')
      $model    parent property to read/write (e.g. 'month', 'trendMetric')
      $options  JS expression returning [{value,label}, …] (e.g. 'monthOptions()')
      $label    optional field label above the control
      $onChange optional JS to run after selecting (watchers usually handle it)
      $width    tailwind width class (default 'w-40')
      $compact  true for the top bar: smaller type, no label, tighter padding
--}}
@php
    $onChange ??= '';
    $width ??= 'w-40';
    $label ??= null;
    $compact ??= false;

    $button = $compact
        ? 'gap-1.5 rounded-lg px-2.5 py-1.5 text-xs'
        : 'mt-1 gap-2 rounded-lg px-3 py-2 text-sm';
@endphp
<div class="relative" @click.outside="openDropdown === '{{ $id }}' && (openDropdown = null)"
     @keydown.escape.window="openDropdown === '{{ $id }}' && (openDropdown = null)">
    @if ($label)
        <label class="block text-xs font-medium text-slate-500">{{ $label }}</label>
    @endif

    <button type="button"
            @click="openDropdown = (openDropdown === '{{ $id }}' ? null : '{{ $id }}')"
            class="flex {{ $width }} items-center justify-between {{ $button }} border border-slate-200 bg-white font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none"
            :class="openDropdown === '{{ $id }}' ? 'border-slate-400 ring-2 ring-slate-900/10' : ''">
        <span class="truncate" x-text="labelFor({{ $options }}, {{ $model }})"></span>
        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition duration-150"
             :class="openDropdown === '{{ $id }}' ? 'rotate-180 text-slate-600' : ''"
             fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
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
         class="absolute right-0 z-50 mt-1.5 max-h-72 min-w-full origin-top overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl shadow-slate-900/10">
        <template x-for="opt in {{ $options }}" :key="opt.value">
            <button type="button"
                    @click="{{ $model }} = opt.value; openDropdown = null; {{ $onChange }}"
                    class="flex w-full items-center justify-between gap-3 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-left text-xs transition"
                    :class="{{ $model }} == opt.value
                        ? 'bg-slate-900 font-semibold text-white'
                        : 'text-slate-600 hover:bg-slate-100'">
                <span class="truncate" x-text="opt.label"></span>
                <svg x-show="{{ $model }} == opt.value" class="h-3.5 w-3.5 shrink-0"
                     fill="none" viewBox="0 0 24 24" stroke-width="2.6" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </template>
    </div>
</div>
