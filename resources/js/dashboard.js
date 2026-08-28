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
    trendBreakdown: '',   // '' = one line; otherwise an attribution column
    trendOther: 0,        // segments the split left unplotted
    openDropdown: null, // id of the currently open custom dropdown

    // Which view is on screen. The rail switches this rather than navigating,
    // so fetched data survives moving between views. Arriving from another page
    // (?view=acquisition) opens straight on that view.
    view: (() => {
        const wanted = new URLSearchParams(window.location.search).get('view');
        const known = ['overview', 'subscribers', 'retention', 'acquisition', 'customers', 'cohorts', 'revenue', 'email'];
        return known.includes(wanted) ? wanted : 'overview';
    })(),

    // --- results ---
    loading: false,
    error: null,
    metrics: {},
    comparison: null,
    periodLabel: '',
    period: null,
    chart: null,
    statusChart: null,
    revenueChart: null,
    growthChart: null,
    churnRateChart: null,
    nrrChart: null,
    sparks: {},
    topCustomers: [],
    // Shaped, not null: x-show hides a section but still evaluates the
    // x-for inside it, so a null here throws on every first paint.
    cohorts: { offsets: [], rows: [] },
    // Lifetime value by sign-up month (period-independent).
    cohortValue: { rows: [] },
    // Acquisition-segment performance; dimension is user-switchable.
    segments: { dimension: 'utm_source', rows: [], dimensions: [], loading: false },
    // The live renewal pipeline.
    renewals: { days: 14, total: 0, at_first_renewal: 0, value: 0, rows: [], loading: false },
    // Campaign audiences: lists you can push straight to Klaviyo.
    audience: { key: 'cross_sell', label: '', total: 0, value: 0, rows: [], audiences: {}, loading: false },
    // Month-by-month subscriber history. Fixed once a month closes — a later
    // cancellation moves that month's row, never the ones before it.
    churn: { rows: [], coverage: null },
    copied: false,

    // The subscriptions behind the period's churn number, fetched on demand so
    // the dashboard does not pay for the list unless somebody opens it.
    lost: { open: false, loading: false, error: null, rows: [], total: 0, returned: 0, summary: {} },

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
    currencyKeys: ['total_revenue', 'average_order_value', 'subscription_revenue', 'one_time_revenue',
        'revenue_at_risk', 'revenue_per_customer', 'mrr', 'arr', 'arpu',
        'gross_revenue', 'net_revenue', 'tax_collected', 'shipping_collected', 'refunded',
        'net_revenue_after_refunds', 'contribution'],
    percentKeys: ['churn_rate', 'monthly_churn_rate', 'monthly_churn_rate_net', 'net_revenue_retention',
        'gross_revenue_retention', 'renewal_success_rate', 'repeat_rate', 'end_date_coverage',
        'customer_churn_rate'],

    init() {
        // React to filter changes without wiring each input by hand.
        this.$watch('granularity', () => this.apply());
        // Month/year drive several granularities; clamp the week then refresh once.
        this.$watch('month', () => { this.clampWeek(); this.apply(); });
        this.$watch('year', () => { this.clampWeek(); this.apply(); });
        this.$watch('view', (v) => this.renderChartsFor(v));
        this.refresh();
        // Period-independent panels — fetch once.
        this.fetchSparklines();
        this.fetchTopCustomers();
        this.fetchCohorts();
        this.fetchCohortValue();
        this.fetchSegments();
        this.fetchRenewals();
        this.fetchAudience();
        this.fetchChurn();
        this.fetchUpsell();
        this.$nextTick(() => this.trackSections());
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

    /** Any metric can be split by any attribution column travelling on a row. */
    breakdownOptions() {
        return [{ value: '', label: 'No split' }].concat(
            (config.segmentDimensions ?? []).map((d) => ({ value: d, label: `By ${this.dimensionLabel(d).toLowerCase()}` })),
        );
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
        this.period = data.period ?? null;
        // The drill-down belongs to the period it was fetched for; drop it so a
        // filter change cannot leave last period's rows under this period's
        // count. Refetched only if the panel is still open.
        this.lost = { ...this.lost, rows: [], total: 0, returned: 0, summary: {}, error: null };
        if (this.lost.open) this.fetchLost();
        this.$nextTick(() => { this.renderRevenueDonut(); });
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

    async fetchSegments(dimension = null) {
        if (dimension) this.segments.dimension = dimension;
        this.segments.loading = true;
        try {
            const res = await fetch(`/api/metrics/segments?dimension=${this.segments.dimension}&min=5`,
                { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const d = await res.json();
                this.segments = { ...this.segments, rows: d.rows ?? [], dimensions: d.dimensions ?? [] };
            }
        } catch (_) { /* non-critical */ } finally { this.segments.loading = false; }
    },

    /** Human label for a raw column name. */
    dimensionLabel(d) {
        return ({
            utm_source: 'Source', utm_medium: 'Medium', utm_campaign: 'Campaign',
            attribution_type: 'Type', device_type: 'Device', coupon_code: 'Coupon',
            billing_period: 'Cycle', primary_product: 'Product',
        })[d] ?? d;
    },

    /** Bar width for the repeat-rate meter, on a 0-100 scale. */
    repeatBarWidth(pct) {
        return `${Math.max(2, Math.min(100, pct)).toFixed(1)}%`;
    },

    async fetchRenewals(days = null) {
        if (days) this.renewals.days = days;
        this.renewals.loading = true;
        try {
            const res = await fetch(`/api/metrics/renewals?days=${this.renewals.days}`,
                { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const d = await res.json();
                this.renewals = { ...this.renewals, total: d.total ?? 0, at_first_renewal: d.at_first_renewal ?? 0,
                    value: d.value ?? 0, rows: d.rows ?? [] };
            }
        } catch (_) { /* non-critical */ } finally { this.renewals.loading = false; }
    },

    async fetchAudience(key = null) {
        if (key) this.audience.key = key;
        this.audience.loading = true;
        try {
            const res = await fetch(`/api/metrics/audience?audience=${this.audience.key}`,
                { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const d = await res.json();
                this.audience = { ...this.audience, label: d.label ?? '', total: d.total ?? 0,
                    value: d.value ?? 0, rows: d.rows ?? [], audiences: d.audiences ?? {} };
            }
        } catch (_) { /* non-critical */ } finally { this.audience.loading = false; }
    },

    audienceExportHref() {
        return `/api/metrics/audience/export?audience=${this.audience.key}`;
    },

    /** Currency, or an em dash when the figure has not been supplied. */
    moneyOrDash(v) {
        return v === null || v === undefined ? '—' : this.money(v);
    },

    pctOrDash(v) {
        return v === null || v === undefined ? '—' : `${v}%`;
    },

    renewalsExportHref() {
        return `/api/metrics/renewals/export?days=${this.renewals.days}`;
    },

    async fetchCohortValue() {
        try {
            const res = await fetch('/api/metrics/cohort-value?cohorts=12', { headers: { Accept: 'application/json' } });
            if (res.ok) this.cohortValue = await res.json();
        } catch (_) { /* non-critical */ }
    },

    /** On Hold is a live state; dormant ones are the part that will never churn. */
    onHoldNote() {
        const d = this.metrics.on_hold_dormant ?? 0;
        if (!d) return 'live state, as of the period end';
        return `${d} dormant 45d+ — stopped paying, never counted as churn`;
    },

    onHoldDormant() {
        return this.metrics.on_hold_dormant ?? 0;
    },

    /** Bar width for the cohort-value column, scaled to the richest cohort. */
    valueBarWidth(row) {
        const peak = Math.max(...(this.cohortValue.rows ?? []).map((r) => r.value_per_subscriber), 0);
        if (!peak || !row.value_per_subscriber) return '0%';
        return `${Math.max(2, (row.value_per_subscriber / peak) * 100).toFixed(1)}%`;
    },

    async fetchChurn() {
        try {
            // /history is /churn plus net churn and NRR per month: the charts
            // and the table below them read from the same rows.
            const res = await fetch('/api/metrics/history?months=12', { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                this.churn = { rows: data.rows ?? [], coverage: data.end_date_coverage ?? null };
                this.$nextTick(() => this.renderHistoryCharts());
            }
        } catch (_) { /* non-critical */ }
    },

    /** Title for the top bar; mirrors the sidebar labels. */
    viewLabel() {
        return ({
            overview: 'Overview',
            subscribers: 'Subscribers',
            retention: 'Retention & Churn',
            acquisition: 'Acquisition',
            customers: 'Customers',
            cohorts: 'Cohort Analysis',
            revenue: 'Revenue & Orders',
            email: 'Email Performance',
        })[this.view] ?? 'Dashboard';
    },

    /** Newest month first, the way you read a history table. */
    churnRows() {
        return [...this.churn.rows].reverse();
    },

    churnRateLabel(rate) {
        return rate === null || rate === undefined ? '—' : `${rate}%`;
    },

    /** Bar width for the inline churn-rate meter, capped at a 25% full scale. */
    churnBarWidth(rate) {
        if (!rate) return '0%';
        return `${Math.min(100, (rate / 25) * 100).toFixed(1)}%`;
    },

    netChangeLabel(row) {
        const net = row.active_end - row.active_start;
        return net > 0 ? `+${net}` : String(net);
    },

    // --- tenure at churn (how long the period's leavers had been subscribed) ---
    tenure() {
        return this.metrics.tenure_at_churn ?? { total: 0, median_days: null, buckets: [] };
    },

    /**
     * Bars are scaled against the biggest bucket, not against 100%, so the
     * shape of the distribution stays readable when churn is concentrated.
     */
    tenureBarWidth(bucket) {
        const peak = Math.max(...this.tenure().buckets.map((b) => b.count), 0);
        if (!peak || !bucket.count) return '0%';
        return `${Math.max(2, (bucket.count / peak) * 100).toFixed(1)}%`;
    },

    tenureMedianLabel() {
        const d = this.tenure().median_days;
        return d === null || d === undefined ? '—' : `${d} days`;
    },

    // --- drill-down: the individual subscriptions counted as churn ---
    async toggleLost() {
        this.lost.open = !this.lost.open;
        if (this.lost.open && this.lost.rows.length === 0) await this.fetchLost();
    },

    async fetchLost() {
        this.lost.loading = true;
        this.lost.error = null;
        try {
            const res = await fetch(`/api/metrics/churned-subscriptions?${this.filterParams().toString()}`, {
                headers: { Accept: 'application/json' },
            });
            // An empty list on failure would read as "nobody churned", which is
            // a claim, not an error state.
            if (!res.ok) throw new Error(`Could not load the list (HTTP ${res.status}).`);
            const data = await res.json();
            this.lost = {
                ...this.lost,
                rows: data.rows ?? [],
                total: data.total ?? 0,
                returned: data.returned ?? 0,
                summary: data.summary ?? {},
            };
        } catch (e) {
            this.lost.error = e.message;
        } finally {
            this.lost.loading = false;
        }
    },

    lostExportHref() {
        return `/api/metrics/churned-subscriptions/export?${this.filterParams().toString()}`;
    },

    lostSummary() {
        return this.lost.summary ?? {};
    },

    /** When they came back: "switched", "next day", "29d later". */
    returnLabel(r) {
        if (r.returned === null) return 'unknown';
        if (!r.returned) return '\u2014';
        if (r.same_day_switch) return 'switched';
        return r.days_to_return === 0 ? 'next day' : `${r.days_to_return}d later`;
    },

    /**
     * Whether that comeback is still running. A win-back that has since
     * cancelled again is not a recovered customer, so the two facts are shown
     * separately rather than collapsed into one "returned" flag.
     */
    returnStateLabel(r) {
        if (!r.returned || !r.returned_status) return '';
        return r.returned_status === 'active' ? 'still active' : `now ${r.returned_status}`;
    },

    returnStateClass(r) {
        return r.returned_status === 'active'
            ? 'bg-emerald-50 text-emerald-700'
            : 'bg-slate-100 text-slate-500';
    },

    returnBadgeClass(r) {
        if (r.returned === null) return 'bg-slate-100 text-slate-400';
        if (!r.returned) return 'text-slate-300';
        // A same-day restart is a plan change, not a recovered customer.
        if (r.same_day_switch) return 'bg-sky-50 text-sky-700';
        return 'bg-emerald-50 text-emerald-700';
    },

    /** Short date for the drill-down table. */
    shortDate(value) {
        if (!value) return '—';
        const [y, m, d] = String(value).split(' ')[0].split('-');
        return new Date(y, m - 1, d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: '2-digit' });
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
        if (this.trendBreakdown) p.set('breakdown', this.trendBreakdown);
        const res = await fetch(`/api/metrics/trend?${p.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Failed to load trend.');
        const data = await res.json();
        this.renderChart(data);
    },

    // Split-trend series colours, in plotting order (brand palette).
    seriesColors: ['#d46681', '#61bac0', '#e4b450', '#4a9aa0', '#a23e58', '#94a3b8'],

    /** A trend value in its own unit — money, a rate, or a plain count. */
    trendValueLabel(v, unit) {
        if (v === null || v === undefined) return '—';
        if (unit === 'currency') return this.money(v);
        if (unit === 'percent') return `${v}%`;
        return new Intl.NumberFormat().format(v);
    },

    /** The same, shortened: an axis has no room for £4,984.16 twelve times. */
    axisLabel(v, unit) {
        if (unit === 'currency') {
            return new Intl.NumberFormat('en-GB', {
                style: 'currency', currency: 'GBP', notation: 'compact', maximumFractionDigits: 1,
            }).format(v || 0);
        }
        if (unit === 'percent') return `${v}%`;
        return new Intl.NumberFormat('en-GB', { notation: 'compact' }).format(v || 0);
    },

    renderChart(data) {
        const ctx = this.$refs.trendCanvas;
        if (!ctx) return;

        const unit = data.unit ?? 'count';
        // One shape either way: an unsplit trend is a single series.
        const series = (data.series ?? []).length
            ? data.series
            : [{ label: data.label ?? this.trendMetricLabel(), values: data.values ?? [] }];
        const split = !!data.breakdown;

        this.trendOther = data.other_segments ?? 0;

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
                datasets: series.map((s, i) => {
                    const color = this.seriesColors[i % this.seriesColors.length];

                    return {
                        label: s.label,
                        data: s.values,
                        borderColor: color,
                        backgroundColor: split ? color : 'rgba(212, 102, 129, 0.22)',
                        tension: 0.3,
                        // One line reads well as an area; six areas drawn over
                        // each other read as mud.
                        fill: !split,
                        borderWidth: 2,
                        pointRadius: split ? 2 : 3,
                        // Ratios and net revenue are null where the month has
                        // no denominator; join across rather than break.
                        spanGaps: true,
                    };
                }),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { boxWidth: 10, usePointStyle: true } },
                    tooltip: {
                        callbacks: { label: (c) => `${c.dataset.label}: ${this.trendValueLabel(c.parsed.y, unit)}` },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    // A rate lives in a narrow band near its own level; forcing
                    // its axis to zero flattens every move that matters.
                    y: { beginAtZero: unit !== 'percent', ticks: { callback: (v) => this.axisLabel(v, unit) } },
                },
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

    /**
     * The instant the snapshot cards are measured at, as a date a person reads.
     * `period.end` is exclusive, so April's is stored as 1 May and shown as
     * 30 Apr — the last day actually inside the period.
     */
    periodEndNote() {
        const end = this.period?.end;
        if (!end) return '';
        const [y, m, d] = String(end).split(' ')[0].split('-');
        if (!y || !m || !d) return '';
        const last = new Date(+y, +m - 1, +d - 1);
        return `as of ${last.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })}`;
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

    // Hovered slice per donut, read by the centre label. A doughnut has a hole
    // the size of a tooltip already; floating one over the chart just covers
    // the neighbouring slices.
    donutHover: { revenue: null },

    /** Shared doughnut options: no tooltip, hover reported to the centre. */
    donutOptions(key) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            onHover: (event, elements, chart) => {
                const el = elements[0];

                if (!el) {
                    this.donutHover[key] = null;

                    return;
                }

                const ds = chart.data.datasets[0];
                this.donutHover[key] = {
                    label: chart.data.labels[el.index],
                    value: ds.data[el.index],
                    color: ds.backgroundColor[el.index],
                };
            },
        };
    },

    /** Chart.js reports no hover once the pointer leaves the canvas entirely. */
    clearDonut(key) {
        this.donutHover[key] = null;
    },

    // Statuses a subscription can currently be in. `cancelled`/`expired` are
    // cumulative history and are reported separately rather than plotted
    // alongside these — see partials/status-mix.
    liveStatuses: ['active', 'on-hold', 'pending-cancel', 'pending'],

    liveBookTotal() {
        return this.liveStatusRows().reduce((n, r) => n + r.count, 0);
    },

    /** Live states with a share of the live book, biggest first, zeros dropped. */
    liveStatusRows() {
        const b = this.metrics.subscription_status_breakdown || {};
        const rows = this.liveStatuses
            .filter((st) => (b[st] ?? 0) > 0)
            .map((st) => ({ status: st, label: st.replace('-', ' '), count: b[st], color: this.statusColors[st] || '#cbd5e1' }));
        const total = rows.reduce((n, r) => n + r.count, 0) || 1;

        return rows
            .map((r) => ({ ...r, pct: Math.round((r.count / total) * 1000) / 10 }))
            .sort((a, z) => z.count - a.count);
    },

    endedStatusTotal() {
        const b = this.metrics.subscription_status_breakdown || {};

        return Object.entries(b)
            .filter(([st]) => ! this.liveStatuses.includes(st))
            .reduce((n, [, v]) => n + v, 0);
    },

    /**
     * Charts render at zero size inside a hidden view, so a view that was not
     * on screen when the data arrived shows a blank or clipped canvas. Redraw
     * on the way in.
     */
    renderChartsFor(view) {
        this.$nextTick(() => {
            if (view === 'overview') this.fetchTrend().catch(() => {});
            if (view === 'revenue') this.renderRevenueDonut();
            if (view === 'subscribers' || view === 'retention') this.renderHistoryCharts();
        });
    },

    // --- monthly history charts (growth, churn rate, NRR) ---
    historyRows() {
        return this.churn.rows ?? [];
    },

    historyLabels() {
        return this.historyRows().map((r) => this.cohortMonthLabel(r.month));
    },

    /** The trailing month, when the data stops part-way through it. */
    partialMonthLabel() {
        const rows = this.historyRows();
        const last = rows[rows.length - 1];
        return last?.partial ? this.cohortMonthLabel(last.month) : null;
    },

    /**
     * Dash the final segment of a line when that month is still filling up.
     * The point is real but incomplete; drawn solid it reads as a collapse.
     */
    partialSegment() {
        const rows = this.historyRows();
        const last = rows.length - 1;
        return {
            borderDash: (ctx) => (last >= 0 && rows[last]?.partial && ctx.p1DataIndex === last ? [5, 4] : undefined),
        };
    },

    /** Bars get the same treatment as the dashed segment: faded, not missing. */
    barColors(solid, faded) {
        return this.historyRows().map((r) => (r.partial ? faded : solid));
    },

    /** Shared axis styling for the two percentage charts. */
    percentScales(beginAtZero) {
        return {
            x: { grid: { display: false } },
            y: { beginAtZero, ticks: { callback: (v) => `${v}%` } },
        };
    },

    renderHistoryCharts() {
        this.renderGrowthChart();
        this.renderChurnRateChart();
        this.renderNrrChart();
    },

    /**
     * The growth engine in one picture: sign-ups above the axis, losses below,
     * and the size of the book they add up to as a line on its own scale.
     */
    renderGrowthChart() {
        const ctx = this.$refs.growthCanvas;
        const rows = this.historyRows();
        if (!ctx || !rows.length) return;

        if (this.growthChart) this.growthChart.destroy();
        this.growthChart = new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: this.historyLabels(),
                datasets: [
                    {
                        label: 'Active at month end',
                        type: 'line',
                        data: rows.map((r) => r.active_end),
                        yAxisID: 'level',
                        borderColor: '#334155',
                        backgroundColor: '#334155',
                        borderWidth: 2,
                        tension: 0.3,
                        pointRadius: 2,
                        segment: this.partialSegment(),
                        order: 0,
                    },
                    {
                        label: 'New',
                        data: rows.map((r) => r.new),
                        backgroundColor: this.barColors('#61bac0', 'rgba(97, 186, 192, 0.35)'),
                        borderRadius: 3,
                        order: 1,
                    },
                    {
                        // Below the axis on purpose: the month's gain and loss
                        // read as one shape instead of two columns to subtract.
                        label: 'Churned',
                        data: rows.map((r) => -r.churned),
                        backgroundColor: this.barColors('#d46681', 'rgba(212, 102, 129, 0.35)'),
                        borderRadius: 3,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { boxWidth: 10, usePointStyle: true } },
                    // Churn is plotted negative; reporting it that way in the
                    // tooltip would read as a negative number of people.
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${Math.abs(c.parsed.y)}` } },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, title: { display: true, text: 'New / churned' } },
                    level: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        title: { display: true, text: 'Active' },
                    },
                },
            },
        });
    },

    /** Monthly churn: real losses solid, gross (incl. never-billed) dashed. */
    renderChurnRateChart() {
        const ctx = this.$refs.churnRateCanvas;
        const rows = this.historyRows();
        if (!ctx || !rows.length) return;

        if (this.churnRateChart) this.churnRateChart.destroy();
        this.churnRateChart = new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: this.historyLabels(),
                datasets: [
                    {
                        label: 'Real churn',
                        data: rows.map((r) => r.churn_net),
                        borderColor: '#d46681',
                        backgroundColor: 'rgba(212, 102, 129, 0.15)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        spanGaps: true,
                        segment: this.partialSegment(),
                    },
                    {
                        label: 'Gross',
                        data: rows.map((r) => r.churn_rate),
                        borderColor: '#cbd5e1',
                        borderDash: [4, 3],
                        borderWidth: 1.5,
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        spanGaps: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { boxWidth: 10, usePointStyle: true } },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y}%` } },
                },
                scales: this.percentScales(true),
            },
        });
    },

    /** NRR against the 100% line: below it the book leaks faster than it grows. */
    renderNrrChart() {
        const ctx = this.$refs.nrrCanvas;
        const rows = this.historyRows();
        if (!ctx || !rows.length) return;

        if (this.nrrChart) this.nrrChart.destroy();
        this.nrrChart = new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: this.historyLabels(),
                datasets: [
                    {
                        label: 'Net revenue retention',
                        data: rows.map((r) => r.nrr),
                        borderColor: '#61bac0',
                        backgroundColor: 'rgba(97, 186, 192, 0.16)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        spanGaps: true,
                        segment: this.partialSegment(),
                    },
                    {
                        label: 'Break-even',
                        data: rows.map(() => 100),
                        borderColor: '#94a3b8',
                        borderDash: [4, 3],
                        borderWidth: 1.5,
                        fill: false,
                        pointRadius: 0,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { boxWidth: 10, usePointStyle: true } },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y}%` } },
                },
                // Not zero-based: NRR lives near 100, and a 0-100 axis flattens
                // every move that matters into one straight line.
                scales: this.percentScales(false),
            },
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
            options: this.donutOptions('revenue'),
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
            + `${this.value('churned_in_period')} churned (${this.value('monthly_churn_rate')} of `
            + `${this.value('active_at_period_start')} at the start), ${this.value('total_revenue')} revenue across `
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

    /**
     * Green for movement in the good direction, red for the bad one.
     *
     * Which way is "good" is per metric: revenue rising is good, churn rising
     * is not. Colouring every increase green paints a worsening churn rate as
     * a win, so the direction comes from the metric table in the blade.
     */
    changeBadgeClass(key) {
        const c = this.change(key);
        if (c === null || c === undefined) return 'bg-slate-100 text-slate-500';
        if (c === 0) return 'bg-slate-100 text-slate-500';

        const dir = (config.directions ?? {})[key] ?? 'flat';
        if (dir === 'flat') return 'bg-slate-100 text-slate-600';

        const better = dir === 'good' ? c > 0 : c < 0;
        return better ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700';
    },

    /** A zero is not news; chips dim themselves rather than compete. */
    isZero(key) {
        const v = this.metrics?.[key];
        return v === 0 || v === '0';
    },

    // --- sticky section nav ---
    activeSection: '',

    /** Highlight whichever section heading is nearest the top of the viewport. */
    trackSections() {
        const ids = Array.from(document.querySelectorAll('[data-section]'));
        if (!ids.length) return;

        const pick = () => {
            let current = ids[0]?.dataset.section ?? '';
            for (const el of ids) {
                if (el.getBoundingClientRect().top <= 120) current = el.dataset.section;
            }
            this.activeSection = current;
        };

        pick();
        window.addEventListener('scroll', pick, { passive: true });
    },

    goToSection(id) {
        document.querySelector(`[data-section="${id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
});
