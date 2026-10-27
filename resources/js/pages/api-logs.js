import { http } from '../lib/http';
import { poll } from '../lib/poll';

/**
 * The API request log on monitoring/api: logged calls (POST) with filters, refreshed
 * every 15 seconds; a click opens one call with its request and response bodies.
 * A stale list response never overwrites a newer filter.
 *
 *   urls        { data, show (with __ID__) }
 *   methodTones HTTP method -> badge tone
 */
export default function apiLogsPage({ urls, methodTones }) {
    return {
        methodTones,
        loading: true,
        refreshing: false,
        logs: [],
        endpoints: [],
        users: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        filters: { endpoint: '', user_id: '', status: '', search: '' },
        page: 1,
        seq: 0,

        // detail
        open: false,
        detailLoading: false,
        detail: null,

        init() {
            this.poller = poll(() => this.load(), 15000);
            for (const key of ['endpoint', 'user_id', 'status']) {
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

        async load() {
            const seq = ++this.seq;
            const data = await http.get(urls.data, { ...this.filters, page: this.page > 1 ? this.page : null });
            if (seq !== this.seq) return; // a newer request is on its way

            this.logs = data.logs;
            this.meta = data.meta;
            this.endpoints = data.endpoints;
            this.users = data.users;
            this.loading = false;
        },

        reload(delay = 0) {
            clearTimeout(this.debounce);
            this.debounce = setTimeout(async () => {
                this.refreshing = true;
                try {
                    await this.load();
                } catch {
                    // the poller keeps trying
                } finally {
                    this.refreshing = false;
                }
            }, delay);
        },

        go(page) {
            this.page = Math.max(1, Math.min(page, this.meta.last_page));
            this.reload();
        },

        get filtered() {
            const f = this.filters;
            return f.endpoint || f.user_id || f.status || f.search;
        },

        reset() {
            Object.assign(this.filters, { endpoint: '', user_id: '', status: '', search: '' });
        },

        async show(log) {
            this.detail = { ...log, request_body: null, response_body: null };
            this.open = true;
            const data = await this.$act(() => http.get(urls.show.replace('__ID__', log.id)), { busy: 'detailLoading', quiet: true });
            if (data && this.detail?.id === log.id) this.detail = data;
        },

        /** JSON bodies indented for reading; anything else (a cut body, plain text) as stored. */
        pretty(body) {
            if (body === null || body === undefined) return '';
            try {
                return JSON.stringify(JSON.parse(body), null, 2);
            } catch {
                return body;
            }
        },
    };
}
