/**
 * Alpine component driving the metrics dashboard.
 *
 * It owns the filter state, fetches the JSON endpoints on every change, and
 * keeps a Chart.js instance in sync for the trend. No full page reloads.
 */
export default (config = {}) => ({
    // --- filter state ---
    granularity: 'month',
    year: config.defaultYear ?? new Date().getFullYear(),
    month: new Date().getMonth() + 1,
    week: 1, // week-of-month index
    from: '',
    to: '',
    compare: false,
    strict: false,
    trendMetric: 'new_subscribers',
    trendType: 'line',
    openDropdown: null, // id of the currently open custom dropdown

    // --- results ---
    loading: false,
    error: null,
    metrics: {},
    comparison: null,
    periodLabel: '',
    chart: null,
    statusChart: null,
    revenueChart: null,
    sparks: {},
    topCustomers: [],
    cohorts: null,
    copied: false,

    // One-time buyers who later subscribed (lifetime, independent of the filter).
    upsell: { rows: [], summary: {}, total: 0 },
    upsellLoading: false,
    upsellError: null,
    upsellConversionsOnly: true,   // subscription must start on/after the one-time order
    upsellCompletedOnly: false,    // count only completed one-time orders
    upsellSearch: '',
    upsellShown: 25,

    // Klaviyo email-performance tiles (read from stored snapshots).
    klaviyo: { state: 'loading', tiles: {}, syncedAt: null, error: null, revision: '', configured: false },
    klaviyoPolls: 0,
    klaviyoRefreshing: false,

    // value formatting
    currencyKeys: ['total_revenue', 'average_order_value', 'subscription_revenue', 'one_time_revenue', 'revenue_at_risk', 'revenue_per_customer'],
    percentKeys: ['churn_rate', 'renewal_success_rate', 'repeat_rate'],

    init() {
        // React to filter changes without wiring each input by hand.
        this.$watch('granularity', () => this.apply());
        // Month/year drive several granularities; clamp the week then refresh once.
        this.$watch('month', () => { this.clampWeek(); this.apply(); });
        this.$watch('year', () => { this.clampWeek(); this.apply(); });
        this.refresh();
        // Period-independent panels — fetch once.
        this.fetchSparklines();
        this.fetchTopCustomers();
        this.fetchCohorts();
        this.fetchUpsell();
    },

    /** Day-based weeks within the selected month: 1–7, 8–14, … */
    weekOptions() {
        const daysInMonth = new Date(this.year, this.month, 0).getDate();
        const count = Math.ceil(daysInMonth / 7);
        const short = new Date(this.year, this.month - 1, 1).toLocaleString(undefined, { month: 'short' });
        return Array.from({ length: count }, (_, i) => {
            const start = i * 7 + 1;
            const end = Math.min((i + 1) * 7, daysInMonth);
            return { value: i + 1, label: `Week ${i + 1} · ${short} ${start}–${end}` };
        });
    },

    clampWeek() {
        const max = this.weekOptions().length;
        if (this.week > max) this.week = max;
    },

    // --- option sources for the custom dropdowns ---
    monthOptions() {
        return Array.from({ length: 12 }, (_, i) => ({
            value: i + 1,
            label: new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' }),
        }));
    },

    yearOptions() {
        return (config.years ?? []).map((y) => ({ value: y, label: String(y) }));
    },

    trendOptions() {
        return (config.trendMetrics ?? []).map((m) => ({ value: m.key, label: m.label }));
    },

    /** Resolve the display label for the currently selected value. */
    labelFor(options, value) {
        const found = options.find((o) => o.value == value);
        return found ? found.label : '';
    },

    filterParams() {
        const p = new URLSearchParams({ granularity: this.granularity });
        if (this.granularity === 'week') { p.set('year', this.year); p.set('month', this.month); p.set('week', this.week); }
        if (this.granularity === 'month') { p.set('year', this.year); p.set('month', this.month); }
        if (this.granularity === 'year') p.set('year', this.year);
        if (this.granularity === 'custom') { p.set('from', this.from); p.set('to', this.to); }
        if (this.compare) p.set('compare', '1');
        if (this.strict) p.set('strict', '1');
        return p;
    },

    /** Called by the "Apply" button and toggles. */
    apply() {
        this.refresh();
    },

    async refresh() {
        if (this.granularity === 'custom' && (!this.from || !this.to)) {
            this.error = 'Pick both a from and to date.';
            return;
        }
        this.loading = true;
        this.error = null;
        try {
            await Promise.all([this.fetchSummary(), this.fetchTrend()]);
        } catch (e) {
            this.error = e.message ?? 'Something went wrong loading metrics.';
        } finally {
            this.loading = false;
        }
        this.fetchKlaviyo(); // independent source; don't block the core metrics
    },

    async fetchSummary() {
        const res = await fetch(`/api/metrics/summary?${this.filterParams().toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Failed to load metrics.');
        const data = await res.json();
        this.metrics = data.metrics;
        this.comparison = data.comparison;
        this.periodLabel = data.period?.label ?? '';
        this.$nextTick(() => { this.renderStatusDonut(); this.renderRevenueDonut(); });
    },

    async fetchSparklines() {
        try {
            const res = await fetch('/api/metrics/sparklines?granularity=month', { headers: { Accept: 'application/json' } });
            if (res.ok) this.sparks = await res.json();
        } catch (_) { /* sparklines are non-critical */ }
    },

    async fetchTopCustomers() {
        try {
            const res = await fetch('/api/metrics/top-customers?limit=10', { headers: { Accept: 'application/json' } });
            if (res.ok) this.topCustomers = (await res.json()).customers ?? [];
        } catch (_) { /* non-critical */ }
    },

    async fetchCohorts() {
        try {
            const res = await fetch('/api/metrics/cohorts?offset=6', { headers: { Accept: 'application/json' } });
            if (res.ok) this.cohorts = await res.json();
        } catch (_) { /* non-critical */ }
    },

    // --- one-time -> subscription upsell list ---
    upsellParams() {
        const p = new URLSearchParams();
        p.set('conversions_only', this.upsellConversionsOnly ? '1' : '0');
        p.set('completed_only', this.upsellCompletedOnly ? '1' : '0');
        return p;
    },

    async fetchUpsell() {
        this.upsellLoading = true;
        this.upsellError = null;
        try {
            const p = this.upsellParams();
            p.set('limit', '500');
            const res = await fetch(`/api/metrics/one-time-to-subscription?${p.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            // Never fall back to an empty list on failure — a silent 0 reads as
            // "nobody converted", which is a very different claim.
            if (!res.ok) throw new Error(`Could not load the list (HTTP ${res.status}).`);
            const data = await res.json();
            this.upsell = { rows: data.customers ?? [], summary: data.summary ?? {}, total: data.total ?? 0 };
            this.upsellShown = 25;
        } catch (e) {
            this.upsellError = e.message ?? 'Could not load the list.';
            this.upsell = { rows: [], summary: {}, total: 0 };
        } finally {
            this.upsellLoading = false;
        }
    },

    upsellExportHref() {
        return `/api/metrics/one-time-to-subscription/export?${this.upsellParams().toString()}`;
    },

    /** Search-filtered rows (email / customer id / subscription id). */
    upsellMatches() {
        const q = this.upsellSearch.trim().toLowerCase();
        if (!q) return this.upsell.rows;
        return this.upsell.rows.filter((r) =>
            (r.email ?? '').toLowerCase().includes(q)
            || String(r.customer_id ?? '').includes(q)
            || String(r.subscription_id ?? '').includes(q));
    },

    upsellVisible() {
        return this.upsellMatches().slice(0, this.upsellShown);
    },

    /** Format a stored "Y-m-d H:i:s" GMT stamp without shifting the day. */
    dateLabel(value) {
        if (!value) return '—';
        const [y, m, d] = String(value).split(' ')[0].split('-');
        if (!y || !m || !d) return String(value);
        return new Date(+y, +m - 1, +d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
    },

    daysLabel(days) {
        if (days === null || days === undefined) return '—';
        if (days === 0) return 'same day';
        return `${new Intl.NumberFormat().format(days)} day${days === 1 ? '' : 's'}`;
    },

    subStatusClass(status) {
        return {
            active: 'bg-emerald-50 text-emerald-700',
            'on-hold': 'bg-amber-50 text-amber-700',
            cancelled: 'bg-rose-50 text-rose-700',
            'pending-cancel': 'bg-orange-50 text-orange-700',
            expired: 'bg-slate-100 text-slate-600',
            pending: 'bg-sky-50 text-sky-700',
        }[status] ?? 'bg-slate-100 text-slate-600';
    },

    async fetchTrend() {
        const p = new URLSearchParams({ metric: this.trendMetric, granularity: this.granularity });
        if (this.granularity === 'custom' && this.from && this.to) {
            p.set('from', this.from);
            p.set('to', this.to);
        }
        const res = await fetch(`/api/metrics/trend?${p.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Failed to load trend.');
        const data = await res.json();
        this.renderChart(data);
    },

    renderChart(data) {
        const ctx = this.$refs.trendCanvas;
        if (!ctx) return;
        const label = this.trendMetricLabel();

        // Recreate rather than mutate config.type — changing a chart's type at
        // runtime is unreliable in Chart.js v4.
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }

        this.chart = new window.Chart(ctx, {
            type: this.trendType,
            data: {
                labels: data.labels,
                datasets: [{
                    label,
                    data: data.values,
                    borderColor: '#d46681',
                    backgroundColor: 'rgba(212, 102, 129, 0.22)',
                    tension: 0.3,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true } },
                scales: { y: { beginAtZero: true } },
            },
        });
    },

    trendMetricLabel() {
        const found = (config.trendMetrics ?? []).find((m) => m.key === this.trendMetric);
        return found ? found.label : this.trendMetric;
    },

    // --- formatting helpers used by the Blade cards ---
    value(key) {
        const v = this.metrics?.[key];
        if (v === null || v === undefined) return '—';
        if (this.currencyKeys.includes(key)) {
            return this.money(v);
        }
        if (this.percentKeys.includes(key)) {
            return `${v}%`;
        }
        return new Intl.NumberFormat().format(v);
    },

    // --- sparklines (inline SVG path from a values array) ---
    sparkPath(values) {
        if (!values || values.length < 2) return '';
        const w = 100, h = 28;
        const max = Math.max(...values), min = Math.min(...values);
        const range = max - min || 1;
        const step = w / (values.length - 1);
        return values
            .map((v, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(h - ((v - min) / range) * h).toFixed(1)}`)
            .join(' ');
    },

    // --- donut charts ---
    // status key => colour (brand palette; must match the donut dataset order)
    statusColors: {
        active: '#61bac0', 'on-hold': '#e4b450', cancelled: '#d46681',
        'pending-cancel': '#a23e58', expired: '#94a3b8', pending: '#4a9aa0',
    },

    renderStatusDonut() {
        const ctx = this.$refs.statusCanvas;
        if (!ctx) return;
        const b = this.metrics.subscription_status_breakdown || {};
        const labels = Object.keys(b);
        const data = Object.values(b);
        const colors = labels.map((l) => this.statusColors[l] || '#cbd5e1');
        if (this.statusChart) this.statusChart.destroy();
        this.statusChart = new window.Chart(ctx, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } } },
        });
    },

    renderRevenueDonut() {
        const ctx = this.$refs.revenueCanvas;
        if (!ctx) return;
        const data = [this.metrics.subscription_revenue || 0, this.metrics.one_time_revenue || 0];
        if (this.revenueChart) this.revenueChart.destroy();
        this.revenueChart = new window.Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['Subscription', 'One-time'], datasets: [{ data, backgroundColor: ['#d46681', '#61bac0'], borderWidth: 0, hoverOffset: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } } },
        });
    },

    /** Percentage share of a value within a whole (rounded). */
    share(part, whole) {
        return whole > 0 ? Math.round((part / whole) * 100) : 0;
    },

    money(v) {
        return new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(v || 0);
    },

    // --- Klaviyo ---
    csrf() {
        return document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '';
    },

    async fetchKlaviyo(poll = false) {
        if (!poll) { this.klaviyo.state = 'loading'; this.klaviyoPolls = 0; }
        try {
            const res = await fetch(`/api/klaviyo/tiles?${this.filterParams().toString()}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error('Failed to load Klaviyo metrics.');
            const data = await res.json();
            this.klaviyo = {
                state: data.state,
                tiles: data.tiles ?? {},
                syncedAt: data.synced_at ?? null,
                error: data.error ?? null,
                revision: data.revision ?? '',
                configured: !!data.configured,
            };
            // While a sync is in flight, poll for the result (syncs can be slow
            // due to Klaviyo's report rate limits).
            if (data.state === 'syncing' && this.klaviyoPolls < 10) {
                this.klaviyoPolls++;
                setTimeout(() => this.fetchKlaviyo(true), 8000);
            }
        } catch (e) {
            this.klaviyo = { ...this.klaviyo, state: 'error', error: e.message };
        }
    },

    async refreshKlaviyo() {
        this.klaviyoRefreshing = true;
        try {
            await fetch(`/api/klaviyo/refresh?${this.filterParams().toString()}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
            });
            this.klaviyo.state = 'syncing';
            this.klaviyoPolls = 0;
            setTimeout(() => this.fetchKlaviyo(true), 4000);
        } catch (_) {
            this.klaviyo = { ...this.klaviyo, state: 'error', error: 'Could not start a refresh.' };
        } finally {
            this.klaviyoRefreshing = false;
        }
    },

    klaviyoValue(key) {
        const v = (this.klaviyo.tiles ?? {})[key];
        if (v === null || v === undefined) return '—';
        if (['revenue', 'sub_created_revenue', 'sub_renewal_revenue', 'flow_revenue', 'flow_sub_created_revenue', 'flow_sub_renewal_revenue'].includes(key)) return this.money(v);
        if (['delivery_rate', 'open_rate', 'click_rate', 'flow_delivery_rate', 'flow_open_rate', 'flow_click_rate'].includes(key)) return `${v}%`;
        return new Intl.NumberFormat().format(v);
    },

    // --- cohort heatmap cell styling ---
    cohortStyle(pct) {
        const alpha = pct > 0 ? (pct / 100) * 0.82 + 0.08 : 0;
        return `background-color: rgba(212,102,129,${alpha.toFixed(2)}); color: ${pct > 55 ? '#ffffff' : '#334155'};`;
    },

    cohortMonthLabel(ym) {
        const [y, m] = ym.split('-');
        return new Date(y, m - 1, 1).toLocaleString(undefined, { month: 'short', year: '2-digit' });
    },

    // --- reporting ---
    periodSummary() {
        const m = this.metrics;
        if (!m || !Object.keys(m).length) return '';
        return `${this.periodLabel}: ${this.value('new_subscribers')} new subscribers, `
            + `${this.value('churn_rate')} churn, ${this.value('total_revenue')} revenue across `
            + `${this.value('completed')} completed orders, ${this.value('unique_customers')} customers.`;
    },

    copySummary() {
        navigator.clipboard?.writeText(this.periodSummary());
        this.copied = true;
        setTimeout(() => { this.copied = false; }, 1800);
    },

    exportHref() {
        return `/api/metrics/export?${this.filterParams().toString()}`;
    },

    clientReportHref() {
        return `/report/client?${this.filterParams().toString()}`;
    },

    change(key) {
        const c = this.comparison?.[key];
        if (!c) return null;
        return c.change; // number | null (null = new, no baseline)
    },

    changeLabel(key) {
        const c = this.change(key);
        if (c === null || c === undefined) {
            return this.comparison ? 'new' : '';
        }
        const sign = c > 0 ? '+' : '';
        return `${sign}${c}%`;
    },

    changeArrow(key) {
        const c = this.change(key);
        if (c === null || c === undefined || c === 0) return '';
        return c > 0 ? '↑' : '↓';
    },

    changeBadgeClass(key) {
        const c = this.change(key);
        if (c === null || c === undefined) return 'bg-indigo-50 text-indigo-600';
        if (c > 0) return 'bg-emerald-50 text-emerald-700';
        if (c < 0) return 'bg-rose-50 text-rose-700';
        return 'bg-slate-100 text-slate-500';
    },
});
