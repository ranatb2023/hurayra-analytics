@php
    $bar ??= 'from-slate-300 to-slate-400';
    $id ??= null;
@endphp
{{-- Section heading. `$id` registers it with the sticky nav's scroll tracker. --}}
<div @if ($id) data-section="{{ $id }}" id="{{ $id }}" @endif class="mb-3 mt-8 flex items-center gap-2.5 scroll-mt-24">
    <span class="h-5 w-1.5 rounded-full bg-linear-to-b {{ $bar }}"></span>
    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-600">{{ $title }}</h2>
</div>
