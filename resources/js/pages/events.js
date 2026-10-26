import { http } from '../lib/http';
import { poll } from '../lib/poll';

/**
 * The event journal (monitoring/events) and one event (monitoring/event).
 *
 * List mode: filters live in the query string (shareable, survive reload), the list
 * refreshes every 10 seconds, a stale response never overwrites a newer filter.
 * Single mode (`event` given): no polling, only resolve.
 *
 *   urls { data, resolve (with __ID__), resolveAll }
 *   t    monitoring.events.js strings
 */
export default function eventsPage({ urls, t, event = null }) {
    const params = new URLSearchParams(window.location.search);

    return {
        // single event
        event,

        // list
        loading: true,
        refreshing: false,
        busy: false,
        events: [],
        counts: {},
        meta: { current_page: 1, last_page: 1, total: 0 },
        filters: {
            level: params.get('level') || 'problems',
            kind: params.get('kind') || '',
            state: params.get('state') || 'open',
            search: params.get('search') || '',
            phone_id: params.get('phone_id') || '',
        },
        page: Number(params.get('page')) || 1,
        seq: 0,

        init() {
            if (this.event) return;
            this.poller = poll(() => this.load(), 10000);
            for (const key of ['level', 'kind', 'state', 'phone_id']) {
                this.$watch(`filters.${key}`, () => {
                    this.page = 1;
                    this.reload();
                });
            }
            this.$watch('filters.search', () => {
                this.page = 1;
                this.reload(350);
            });
        },

        destroy() {
            this.poller?.stop();
            clearTimeout(this.debounce);
        },

        query() {
            return { ...this.filters, page: this.page > 1 ? this.page : null };
        },

        async load() {
            const seq = ++this.seq;
            const query = this.query();
            const data = await http.get(urls.data, query);
            if (seq !== this.seq) return; // a newer request is on its way

            this.events = data.events;
            this.meta = data.meta;
            this.counts = Array.isArray(data.counts) ? {} : data.counts;
            this.loading = false;

            const url = new URL(window.location.href);
            url.search = '';
            for (const [key, value] of Object.entries(query)) {
                if (value !== null && value !== '' && !(key === 'level' && value === 'problems') && !(key === 'state' && value === 'open')) {
                    url.searchParams.set(key, value);
                }
            }
            window.history.replaceState(null, '', url);
        },

        /** Search input waits for the user to stop typing; other filters go at once. */
        reload(delay = 0) {
            clearTimeout(this.debounce);
            this.debounce = setTimeout(async () => {
                this.refreshing = true;
                try {
                    await this.load();
                } catch {
                    // the poller keeps trying; the toolbar shows the spinner until then
                } finally {
                    this.refreshing = false;
                }
            }, delay);
        },

        go(page) {
            this.page = Math.max(1, Math.min(page, this.meta.last_page));
            this.reload();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        get openTotal() {
            return Object.values(this.counts).reduce((sum, n) => sum + Number(n), 0);
        },

        reset() {
            Object.assign(this.filters, { level: 'problems', kind: '', state: 'open', search: '', phone_id: '' });
        },

        get filtered() {
            const f = this.filters;
            return f.level !== 'problems' || f.kind || f.state !== 'open' || f.search || f.phone_id;
        },

        // ------------------------------------------------------------ actions

        async resolve(item) {
            const ok = await this.$act(() => http.post(urls.resolve.replace('__ID__', item.id)), { quiet: true });
            if (!ok) return;
            item.resolved_at = new Date().toISOString();
            this.$store.toast.show(t.resolved);
            if (!this.event) this.reload();
        },

        async resolveAll() {
            const kindLabel = this.filters.kind ? t.kinds[this.filters.kind] : null;
            const yes = await this.$store.confirm.ask({
                title: t.resolve_all_title,
                body: kindLabel ? t.resolve_all_kind_body.replace(':kind', kindLabel) : t.resolve_all_body,
                action: t.resolve_all,
                danger: false,
            });
            if (!yes) return;
            const result = await this.$act(() => http.post(urls.resolveAll, { kind: this.filters.kind || null }));
            if (result) this.reload();
        },
    };
}
