import { http } from '../lib/http';
import { poll } from '../lib/poll';

const FILTERS = ['search', 'status', 'phone_id', 'from', 'to'];

/**
 * History of sends. Filters live in the URL query string, so /sends?status=active
 * is a shareable link and survives a reload. Polls every 3s while a listed send
 * is running, every 20s otherwise.
 */
export default function sendsPage({ url, t }) {
    return {
        groups: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        loading: true,
        filters: { search: '', status: '', phone_id: '', from: '', to: '' },
        page: 1,
        searchTimer: null,
        abort: null,

        init() {
            const query = new URLSearchParams(window.location.search);
            for (const key of FILTERS) this.filters[key] = query.get(key) ?? '';
            this.page = Math.max(1, Number(query.get('page')) || 1);

            this.poller = poll(() => this.load(), () => (this.anyActive ? 3000 : 20000));
            window.addEventListener('popstate', () => {
                const q = new URLSearchParams(window.location.search);
                for (const key of FILTERS) this.filters[key] = q.get(key) ?? '';
                this.page = Math.max(1, Number(q.get('page')) || 1);
                this.refresh(false);
            });
        },

        destroy() {
            this.poller?.stop();
            clearTimeout(this.searchTimer);
        },

        get anyActive() {
            return this.groups.some((g) => g.active);
        },

        get activeCount() {
            return this.groups.filter((g) => g.active).length;
        },

        get filtered() {
            return FILTERS.some((key) => this.filters[key] !== '');
        },

        async load() {
            this.abort?.abort();
            this.abort = new AbortController();
            const data = await http.get(url, { ...this.filters, page: this.page }, { signal: this.abort.signal });
            this.groups = data.groups;
            this.meta = data.meta;
            this.loading = false;

            // the list shrank under us (filters, deletions): go to the last page that exists
            if (this.page > data.meta.last_page && data.meta.last_page >= 1) {
                this.page = data.meta.last_page;
                this.refresh(false);
            }
        },

        /** Writes the filters into the address bar and reloads the list. */
        refresh(push = true) {
            const params = new URLSearchParams();
            for (const key of FILTERS) if (this.filters[key] !== '') params.set(key, this.filters[key]);
            if (this.page > 1) params.set('page', this.page);
            const qs = params.toString();
            const next = window.location.pathname + (qs ? `?${qs}` : '');
            if (push && next !== window.location.pathname + window.location.search) {
                window.history.replaceState(null, '', next);
            }
            this.loading = true;
            // load() directly, not poller.now(): a tick in flight would be skipped,
            // while load() aborts it and fetches with the new filters.
            this.load().catch((e) => {
                if (e?.name !== 'AbortError') this.loading = false;
            });
        },

        apply() {
            this.page = 1;
            this.refresh();
        },

        searchChanged() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.apply(), 350);
        },

        setStatus(status) {
            this.filters.status = status;
            this.apply();
        },

        reset() {
            for (const key of FILTERS) this.filters[key] = '';
            this.apply();
        },

        go(page) {
            this.page = Math.min(Math.max(1, page), this.meta.last_page);
            this.refresh();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        share(part, g) {
            return g.counts.total ? (g.counts[part] / g.counts.total) * 100 : 0;
        },

        /** A repeating send's interval: "har 2 soat". */
        every(minutes) {
            return t.every.replace(':time', this.$fmt.minutes(minutes, t.units));
        },

        /** Tooltip of the paperclip: name, size, and whether the file is gone. */
        fileTitle(file) {
            if (!file) return '';
            const parts = [file.name, this.$fmt.bytes(file.size)];
            if (file.removed) parts.push(t.file_removed);

            return parts.join(' · ');
        },
    };
}
