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
<body class="h-full text-slate-800 antialiased">
    <div class="min-h-full">
        <header class="no-print sticky top-0 z-40 border-b border-slate-200/70 bg-white/80 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-xl bg-linear-to-br from-indigo-600 to-violet-700 px-3 py-2 shadow-lg shadow-indigo-500/30">
                        <img src="{{ asset('assets/img/Hurayra-svg-updated.webp') }}" alt="Hurayra" class="h-8 w-auto">
                    </span>
                </a>
                <nav class="flex items-center gap-1 rounded-xl border border-slate-200/80 bg-slate-50/80 p-1 text-sm font-medium">
                    <a href="{{ route('dashboard') }}"
                       class="rounded-lg px-3.5 py-1.5 transition {{ request()->routeIs('dashboard') ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
                        Dashboard
                    </a>
                    <a href="{{ route('uploads.index') }}"
                       class="rounded-lg px-3.5 py-1.5 transition {{ request()->routeIs('uploads.*') ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
                        Upload CSV
                    </a>
                </nav>
            </div>
        </header>

        @if (session('status'))
            <div class="mx-auto max-w-7xl px-4 pt-5 sm:px-6 lg:px-8">
                <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm font-medium text-emerald-800 shadow-sm">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    {{ session('status') }}
                </div>
            </div>
        @endif

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            @yield('content')
        </main>

        <footer class="no-print mx-auto max-w-7xl px-4 pb-8 pt-4 text-center text-xs text-slate-400 sm:px-6 lg:px-8">
            Hurayra Analytics · all metrics derived from a single WooCommerce export
        </footer>
    </div>
    @stack('scripts')
</body>
</html>
