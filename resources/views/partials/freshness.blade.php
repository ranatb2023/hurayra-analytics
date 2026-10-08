{{--
    Data freshness strip, on every page.

    Every figure on the dashboard is only as current as the newest data behind
    it. A week-old export looks exactly like a quiet week, so the age of the
    data is stated before any number is read: green under 12 hours since the
    last order, amber under 24, red beyond that.

    Expects $freshness from DataFreshness::summary() (null hides the strip).
--}}
@if ($freshness)
    @php
        $f = $freshness;
        $tone = [
            'green' => ['bg-emerald-50 text-emerald-900 border-emerald-200', 'bg-emerald-500'],
            'amber' => ['bg-amber-50 text-amber-900 border-amber-200', 'bg-amber-500'],
            'red' => ['bg-rose-50 text-rose-900 border-rose-200', 'bg-rose-500'],
        ][$f['level']];
        $fmt = fn ($t) => $t ? $t->format('j M Y, H:i') : '—';
        $synced = $f['last_sync_at'] ?? null;
        $imported = $f['last_import_at'] ?? null;
        $latest = $f['latest_order'];
        $age = $f['hours_since_order'];
        $ageLabel = $age === null ? null : ($age < 48 ? $age.'h ago' : intdiv($age, 24).' days ago');
        $source = $f['source_orders'];
    @endphp
    <div class="border-b px-4 py-1.5 text-[11px] sm:px-6 lg:px-8 {{ $tone[0] }}" role="status" data-freshness="{{ $f['level'] }}">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5">
            <span class="inline-flex items-center gap-1.5 font-semibold">
                <span class="h-2 w-2 shrink-0 rounded-full {{ $tone[1] }}"></span>
                @if ($latest === null)
                    No orders in the data
                @elseif ($f['level'] === 'green')
                    Data is current
                @else
                    No new order for {{ $ageLabel }}
                @endif
            </span>

            <span>
                @if ($synced)
                    Last sync <span class="font-semibold">{{ $fmt($synced) }}</span>
                    @if ($imported) <span class="opacity-70">· last import {{ $fmt($imported) }}</span> @endif
                @else
                    Last import <span class="font-semibold">{{ $fmt($imported) }}</span>
                @endif
            </span>

            @if ($latest)
                <span>
                    Latest order <span class="font-semibold">#{{ $latest['id'] }}</span>,
                    <span class="font-semibold">{{ $fmt($latest['created_at']) }}</span>
                    <span class="opacity-70">({{ $ageLabel }})</span>
                </span>
            @endif

            <span title="Dashboard counts real order statuses only. The difference is listed on the Data Health page.">
                Orders <span class="font-semibold">{{ number_format($f['dashboard_orders']) }}</span> on dashboard
                · <span class="font-semibold">{{ number_format($source['count']) }}</span> in {{ $source['from'] }}
            </span>

            {{-- Only on the dashboard, where a period is selected. --}}
            @hasSection('app_data')
                <span x-show="dataEndsNote()" x-cloak class="font-semibold" x-text="dataEndsNote()"></span>
            @endif
        </div>
    </div>
@endif
