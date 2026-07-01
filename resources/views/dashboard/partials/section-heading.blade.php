{{-- Section heading with a gradient accent bar. Expects $title and $bar (gradient classes). --}}
<div class="mb-3 mt-8 flex items-center gap-2.5">
    <span class="h-5 w-1.5 rounded-full bg-linear-to-b {{ $bar }}"></span>
    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-600">{{ $title }}</h2>
</div>
