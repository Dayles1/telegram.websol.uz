/**
 * Global Alpine stores: toasts, theme, command palette, confirm dialog.
 */
export function registerStores(Alpine) {
    Alpine.store('toast', {
        items: [],
        seq: 0,
        show(message, tone = 'success', timeout = 4500) {
            if (!message) return;
            const id = ++this.seq;
            this.items.push({ id, message, tone });
            if (timeout) setTimeout(() => this.dismiss(id), timeout);
        },
        error(message) {
            this.show(message, 'error', 7000);
        },
        dismiss(id) {
            this.items = this.items.filter((t) => t.id !== id);
        },
    });

    Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try {
                localStorage.setItem('theme', this.dark ? 'dark' : 'light');
            } catch {
                // storage blocked: the choice lasts for this page only
            }
        },
    });

    /**
     * A promise-based confirm dialog: `if (await $store.confirm.ask({...}))`.
     */
    Alpine.store('confirm', {
        open: false,
        title: '',
        body: '',
        action: '',
        danger: true,
        resolve: null,
        ask({ title, body = '', action = '', danger = true }) {
            Object.assign(this, { open: true, title, body, action, danger });
            return new Promise((resolve) => (this.resolve = resolve));
        },
        answer(value) {
            this.open = false;
            this.resolve?.(value);
            this.resolve = null;
        },
    });

    Alpine.store('palette', {
        open: false,
        query: '',
        active: 0,
        items: window.__palette ?? [],
        get results() {
            const q = this.query.trim().toLowerCase();
            return q ? this.items.filter((i) => `${i.label} ${i.group}`.toLowerCase().includes(q)) : this.items;
        },
        show() {
            this.open = true;
            this.query = '';
            this.active = 0;
        },
        move(step) {
            const count = this.results.length;
            if (count) this.active = (this.active + step + count) % count;
        },
        go(item = this.results[this.active]) {
            if (item) window.location.href = item.url;
        },
    });
}
