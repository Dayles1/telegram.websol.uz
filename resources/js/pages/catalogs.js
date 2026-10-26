import { http } from '../lib/http';

/**
 * Catalogs list: tabs (mine / global / others for a superadmin), search,
 * pagination, an expandable peer list per row, delete, copy to me and
 * (superadmin) give copies to users.
 */
export default function catalogsPage({ urls, t, isAdmin }) {
    const url = (name, id) => urls[name].replace('__ID__', id);

    return {
        isAdmin,
        urls,
        t,
        tab: 'mine',
        search: '',
        page: 1,
        catalogs: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        counts: { mine: 0, global: 0, others: null },
        loading: true,
        busy: false,
        open: {},
        controller: null,
        searchTimer: null,

        // give to users
        give: false,
        giveCatalog: null,
        users: null,
        userSearch: '',
        picked: [],

        init() {
            const params = new URLSearchParams(window.location.search);
            if (['mine', 'global', 'others'].includes(params.get('tab'))) this.tab = params.get('tab');
            if (this.tab === 'others' && !isAdmin) this.tab = 'mine';
            this.search = params.get('search') ?? '';
            this.$watch('search', () => {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.go(1), 300);
            });
            this.load();
        },

        get tabs() {
            const tabs = [
                { key: 'mine', label: t.tab_mine },
                { key: 'global', label: t.tab_global },
            ];
            if (isAdmin) tabs.push({ key: 'others', label: t.tab_others });

            return tabs;
        },

        async load() {
            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;
            try {
                const data = await http.get(urls.data, { tab: this.tab, search: this.search.trim(), page: this.page }, { signal: this.controller.signal });
                this.catalogs = data.catalogs;
                this.meta = data.meta;
                this.counts = data.counts;
                this.loading = false;
                this.syncUrl();
            } catch (e) {
                if (e?.name === 'AbortError') return;
                this.loading = false;
                this.$store.toast.error(e.message);
            }
        },

        syncUrl() {
            const params = new URLSearchParams();
            if (this.tab !== 'mine') params.set('tab', this.tab);
            if (this.search.trim()) params.set('search', this.search.trim());
            if (this.page > 1) params.set('page', this.page);
            const qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? `?${qs}` : ''));
        },

        switchTab(key) {
            if (this.tab === key) return;
            this.tab = key;
            this.open = {};
            this.go(1);
        },

        go(page) {
            this.page = Math.max(1, page);
            this.load();
        },

        toggle(catalog) {
            this.open[catalog.id] = !this.open[catalog.id];
        },

        duration(minutes) {
            const m = Number(minutes);
            if (m >= 1440 && m % 1440 === 0) return t.unit_day.replace(':n', m / 1440);
            if (m >= 60 && m % 60 === 0) return t.unit_hour.replace(':n', m / 60);

            return t.unit_min.replace(':n', m);
        },

        get emptyTitle() {
            if (this.search.trim()) return t.empty_search;

            return { mine: t.empty_mine_title, global: t.empty_global_title, others: t.empty_others_title }[this.tab];
        },

        get emptyText() {
            if (this.search.trim()) return '';

            return { mine: t.empty_mine_text, global: t.empty_global_text, others: '' }[this.tab];
        },

        // ----------------------------------------------------------- actions

        async remove(catalog) {
            const ok = await this.$store.confirm.ask({ title: t.delete_title, body: t.delete_body.replace(':title', catalog.title), action: t.delete });
            if (ok && (await this.$act(() => http.delete(url('destroy', catalog.id))))) {
                if (this.catalogs.length === 1 && this.page > 1) this.page--;
                this.load();
            }
        },

        async copy(catalog) {
            if (await this.$act(() => http.post(url('copy', catalog.id)))) this.load();
        },

        async openGive(catalog) {
            Object.assign(this, { give: true, giveCatalog: catalog, picked: [], userSearch: '' });
            if (this.users === null) {
                try {
                    this.users = await http.get(urls.users);
                } catch (e) {
                    this.users = [];
                    this.$store.toast.error(e.message);
                }
            }
            this.$nextTick(() => this.$refs.userSearch?.focus());
        },

        get filteredUsers() {
            const q = this.userSearch.trim().toLowerCase();
            const list = this.users ?? [];

            return q ? list.filter((u) => `${u.name} ${u.email}`.toLowerCase().includes(q)) : list;
        },

        pick(user) {
            this.picked = this.picked.includes(user.id) ? this.picked.filter((id) => id !== user.id) : [...this.picked, user.id];
        },

        pickAll() {
            this.picked = [...new Set([...this.picked, ...this.filteredUsers.map((u) => u.id)])];
        },

        async submitGive() {
            if (!this.picked.length) return;
            const result = await this.$act(() => http.post(url('distribute', this.giveCatalog.id), { user_ids: this.picked }));
            if (result) {
                this.give = false;
                this.load();
            }
        },
    };
}
