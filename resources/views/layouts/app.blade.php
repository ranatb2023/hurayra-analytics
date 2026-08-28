<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hurayra Analytics')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    App shell: a fixed dark rail on the left, content to the right.

    The rail is what makes this read as a tool rather than a page — it stays put,
    it is the only dark surface, and every view hangs off it. Navigation lives
    inside the dashboard's Alpine scope (@yield('nav')) so switching views is a
    state change, not a page load.
--}}
<body class="h-full bg-slate-100 text-slate-800 antialiased">
{{-- The Alpine root wraps the WHOLE shell, not just the content: the rail
     and the top bar drive the same state the views read, so they have to
     share one scope. --}}
<div class="min-h-full lg:pl-60" @hasSection('app_data') x-data="@yield('app_data')" x-cloak @endif>

    {{-- ---------------------------------------------------------- sidebar --}}
    <aside class="no-print fixed inset-y-0 left-0 z-40 hidden w-60 flex-col bg-slate-900 lg:flex">
        <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-white/5 px-5">
            <img src="{{ asset('assets/img/Hurayra-svg-updated.webp') }}" alt="Hurayra" class="h-7 w-auto">
        </div>

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
            @include('dashboard.partials.rail')
        </nav>

        <div class="shrink-0 border-t border-white/5 p-3">
            <a href="{{ route('uploads.index') }}"
               class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition
                      {{ request()->routeIs('uploads.*') ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white' }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                Upload CSV
            </a>
            <p class="mt-2 px-3 text-[10px] leading-relaxed text-slate-600">
                All metrics derived from one WooCommerce export.
            </p>
        </div>
    </aside>

    {{-- Narrow screens get the rail as a horizontal strip instead. --}}
    <div class="no-print sticky top-0 z-40 flex items-center gap-3 overflow-x-auto bg-slate-900 px-4 py-2.5 lg:hidden">
        <img src="{{ asset('assets/img/Hurayra-svg-updated.webp') }}" alt="Hurayra" class="h-6 w-auto shrink-0">
        <div class="flex gap-1">@include('dashboard.partials.rail')</div>
    </div>

    {{-- ------------------------------------------------------- top bar --}}
    <header class="no-print sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur-xl">
        <div class="flex h-16 items-center gap-4 px-4 sm:px-6 lg:px-8">
            @hasSection('topbar')
                @yield('topbar')
            @else
                <h1 class="text-base font-bold tracking-tight text-slate-900">@yield('heading', 'Hurayra Analytics')</h1>
            @endif
        </div>
    </header>

    @if (session('status'))
        <div class="px-4 pt-5 sm:px-6 lg:px-8">
            <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm font-medium text-emerald-800 shadow-sm">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                {{ session('status') }}
            </div>
        </div>
    @endif

    <main class="px-4 py-6 sm:px-6 lg:px-8">
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
