import { http } from '../lib/http';
import { number } from '../lib/format';

/**
 * Delivery analytics over a period (AnalyticsController::data): KPI tiles with the
 * change against the previous period of the same length, the volume chart, why
 * messages fail, and sortable per-user / per-account tables.
 * Filters live in the query string so a view can be shared.
 *
 *   urls { data, events }
 *   t    monitoring.analytics.js strings
 */
export default function analyticsPage({ urls, t }) {
    const params = new URLSearchParams(window.location.search);
    const empty = { sent: 0, failed: 0, pending: 0, canceled: 0, groups: 0, accounts: 0 };

    return {
        loading: true,
        refreshing: false,
        failed: false,
        period: params.get('period') || '7d',
        userId: params.get('user_id') || '',
        phoneId: params.get('phone_id') || '',
        data: { from: null, to: null, totals: empty, previous: empty, series: [], errors: [], users: [], phones: [] },
        /** Every account seen in any response, so the account filter keeps its options. */
        knownPhones: {},
        sort: {
            users: { key: 'sent', dir: -1 },
            phones: { key: 'sent', dir: -1 },
        },
        seq: 0,

        init() {
            this.load();
            this.$watch('period', () => this.load());
            this.$watch('userId', () => {
                this.phoneId = '';
                this.load();
            });
            this.$watch('phoneId', () => this.load());
        },

        async load() {
            const seq = ++this.seq;
            const query = { period: this.period, user_id: this.userId, phone_id: this.phoneId };
            this.refreshing = true;
            try {
                const data = await http.get(urls.data, query);
                if (seq !== this.seq) return;
                this.data = data;
                for (const p of data.phones) this.knownPhones[p.phone_id] = { phone: p.phone, owner: p.owner };
                this.failed = false;
            } catch {
                if (seq === this.seq) this.failed = true;
            } finally {
                if (seq === this.seq) {
                    this.loading = false;
                    this.refreshing = false;
                }
            }

            const url = new URL(window.location.href);
            url.search = '';
            for (const [key, value] of Object.entries(query)) {
                if (value && !(key === 'period' && value === '7d')) url.searchParams.set(key, value);
            }
            window.history.replaceState(null, '', url);
        },

        // ---------------------------------------------------------------- KPI

        get totals() {
            return this.data.totals;
        },

        get previous() {
            return this.data.previous;
        },

        rate(totals) {
            const done = totals.sent + totals.failed;
            return done ? (totals.sent / done) * 100 : null;
        },

        get kpis() {
            const now = this.totals;
            const before = this.previous;
            const rateNow = this.rate(now);
            const rateBefore = this.rate(before);

            return [
                { key: 'sent', label: t.kpi.sent, value: number(now.sent), delta: this.change(now.sent, before.sent), good: 1 },
                { key: 'failed', label: t.kpi.failed, value: number(now.failed), delta: this.change(now.failed, before.failed), good: -1 },
                {
                    key: 'rate',
                    label: t.kpi.rate,
                    value: rateNow === null ? '—' : `${rateNow.toFixed(1)}%`,
                    delta: rateNow === null || rateBefore === null ? null : { value: rateNow - rateBefore, text: `${rateNow - rateBefore >= 0 ? '+' : ''}${(rateNow - rateBefore).toFixed(1)} ${t.pp}` },
                    good: 1,
                },
                { key: 'groups', label: t.kpi.groups, value: number(now.groups), delta: this.change(now.groups, before.groups), good: 1 },
                { key: 'accounts', label: t.kpi.accounts, value: number(now.accounts), delta: this.change(now.accounts, before.accounts), good: 0 },
            ];
        },

        /** Relative change against the previous period, or null when there is nothing to compare with. */
        change(now, before) {
            if (!before) return now ? { value: 1, text: t.new } : null;
            const pct = ((now - before) / before) * 100;
            return { value: pct, text: `${pct >= 0 ? '+' : ''}${Math.round(pct)}%` };
        },

        /** Tone of a delta: green when it moves the good way, red the bad way, grey when neutral. */
        deltaTone(kpi) {
            if (!kpi.delta || Math.abs(kpi.delta.value) < 0.05 || kpi.good === 0) return 'text-gray-500';
            return kpi.delta.value * kpi.good > 0 ? 'text-success-600' : 'text-error-600';
        },

        // -------------------------------------------------------------- chart

        get hourly() {
            return (this.data.series[0]?.bucket ?? '').length > 10;
        },

        get peak() {
            return this.data.series.reduce((best, p) => (p.sent + p.failed > (best?.sent ?? 0) + (best?.failed ?? 0) ? p : best), null);
        },

        // ------------------------------------------------------------- errors

        get errorsTotal() {
            return Math.max(this.totals.failed, this.data.errors.reduce((sum, e) => sum + e.count, 0));
        },

        get errorsMax() {
            return Math.max(1, ...this.data.errors.map((e) => e.count));
        },

        // ------------------------------------------------------------- tables

        sortBy(table, key) {
            const s = this.sort[table];
            if (s.key === key) s.dir = -s.dir;
            else Object.assign(s, { key, dir: ['name', 'phone', 'owner'].includes(key) ? 1 : -1 });
        },

        sorted(table) {
            const { key, dir } = this.sort[table];
            return this.data[table]
                .map((row) => ({ ...row, rate: this.rate(row) }))
                .sort((a, b) => {
                    const x = a[key] ?? -1;
                    const y = b[key] ?? -1;
                    return (typeof x === 'string' ? x.localeCompare(y) : x - y) * dir;
                });
        },

        arrow(table, key) {
            const s = this.sort[table];
            return s.key === key ? (s.dir > 0 ? '↑' : '↓') : '';
        },

        rateTone(rate) {
            if (rate === null) return 'c-muted';
            if (rate >= 90) return 'text-success-600';
            if (rate >= 70) return 'text-warning-600';
            return 'text-error-600';
        },

        get phoneOptions() {
            return Object.entries(this.knownPhones)
                .map(([id, p]) => ({ id, ...p }))
                .sort((a, b) => a.phone.localeCompare(b.phone));
        },

        pickUser(row) {
            this.userId = String(row.user_id);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        pickPhone(row) {
            this.phoneId = String(row.phone_id);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        eventsUrl(row) {
            return `${urls.events}?phone_id=${row.phone_id}&state=all`;
        },
    };
}
