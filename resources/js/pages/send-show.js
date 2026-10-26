import { http } from '../lib/http';
import { poll } from '../lib/poll';

/** How many failed messages the error breakdown reads (pages of 50). */
const FAILED_PAGES = 20;

const seconds = (from, to = Date.now()) => (from ? Math.max(0, (new Date(to).getTime() - new Date(from).getTime()) / 1000) : null);

/**
 * One send in detail. Polls every 2s while the send runs; when it is a round of
 * a repeating send that is still active, every 10s (the next round's countdown,
 * the loop's status); stops when neither runs. The error breakdown and the
 * "copy failed peers" list come from a separate read of the failed messages,
 * refreshed when the failed count moves.
 *
 *   captionMax longer texts went as a separate message after the file
 */
export default function sendShowPage({ urls, t, captionMax }) {
    return {
        t,
        captionMax,
        group: null,
        messages: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        editableCatalogs: [],
        loading: true,
        listLoading: false,
        error: false,
        busy: false,

        status: '',
        search: '',
        page: 1,
        searchTimer: null,
        abort: null,

        textOpen: false,
        now: Date.now(),

        failedPeers: [],
        errorGroups: [],
        failedFor: null,
        failedTruncated: false,

        removed: {},
        removeModal: false,
        removeTarget: null,
        removeCatalog: null,

        init() {
            this.poller = poll(() => this.load(), () => (!this.group || this.group.active ? 2000 : 10000));
            this.clock = setInterval(() => (this.now = Date.now()), 1000);
        },

        destroy() {
            this.poller?.stop();
            clearInterval(this.clock);
            clearTimeout(this.searchTimer);
        },

        // ------------------------------------------------------------ loading

        async load() {
            this.abort?.abort();
            this.abort = new AbortController();
            let data;
            try {
                data = await http.get(urls.progress, { status: this.status, search: this.search.trim(), page: this.page }, { signal: this.abort.signal });
            } catch (e) {
                if (e?.name !== 'AbortError' && !this.group) this.error = true;
                throw e;
            }

            const wasActive = this.group?.active;
            this.group = data.group;
            this.messages = data.messages;
            this.meta = data.meta;
            this.editableCatalogs = data.editable_catalogs;
            this.loading = false;
            this.listLoading = false;
            this.error = false;

            if (wasActive && !data.group.active) {
                this.$store.toast.show(`${t.finished}: ${data.group.status_label}`, data.group.tone === 'error' ? 'error' : 'success');
            }
            if (!data.group.active && !data.group.loop?.active) this.poller?.stop();
            if (this.failedFor !== data.group.counts.failed) this.loadFailed(data.group.counts.failed);
        },

        reload() {
            this.listLoading = true;
            this.load().catch(() => (this.listLoading = false));
        },

        /** Reads the failed messages (up to FAILED_PAGES pages) for the breakdown and the copy list. */
        async loadFailed(count) {
            this.failedFor = count;
            if (!count) {
                this.failedPeers = [];
                this.errorGroups = [];
                this.failedTruncated = false;
                return;
            }

            const rows = [];
            let page = 1;
            let last = 1;
            try {
                do {
                    const data = await http.get(urls.progress, { status: 'failed', page });
                    rows.push(...data.messages);
                    last = data.meta.last_page;
                    page++;
                } while (page <= last && page <= FAILED_PAGES);
            } catch {
                this.failedFor = null; // try again on the next poll
                return;
            }

            const byText = new Map();
            for (const m of rows) {
                const key = m.error_text || m.error_key || '—';
                const entry = byText.get(key) ?? { text: key, key: m.error_key, count: 0 };
                entry.count++;
                byText.set(key, entry);
            }
            this.errorGroups = [...byText.values()].sort((a, b) => b.count - a.count);
            this.failedPeers = [...new Set(rows.map((m) => m.peer))];
            this.failedTruncated = last > FAILED_PAGES;
        },

        // ------------------------------------------------------------ filters

        setStatus(status) {
            this.status = status;
            this.page = 1;
            this.reload();
        },

        searchChanged() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => {
                this.page = 1;
                this.reload();
            }, 300);
        },

        go(page) {
            this.page = Math.min(Math.max(1, page), this.meta.last_page);
            this.reload();
        },

        // ------------------------------------------------------------ figures

        get counts() {
            const c = this.group?.counts ?? { total: 0, sent: 0, failed: 0, pending: 0, sending: 0, canceled: 0 };
            // counts.pending = not finished (pending + sending); "queued" matches the pending tab's rows
            return { ...c, queued: c.pending - (c.sending ?? 0) };
        },

        share(part) {
            return this.counts.total ? (this.counts[part] / this.counts.total) * 100 : 0;
        },

        get done() {
            return this.counts.sent + this.counts.failed + this.counts.canceled;
        },

        get duration() {
            if (!this.group?.started_at) return null;
            return seconds(this.group.started_at, this.group.finished_at ?? this.now);
        },

        /** Messages per minute since the send started. */
        get rate() {
            const elapsed = this.duration;
            const processed = this.counts.sent + this.counts.failed;
            if (!elapsed || elapsed < 5 || !processed) return null;
            return (processed / elapsed) * 60;
        },

        get eta() {
            if (this.group?.status !== 'sending' || !this.rate || !this.counts.pending) return null;
            return (this.counts.pending / this.rate) * 60;
        },

        get longText() {
            const text = this.group?.text ?? '';
            return text.length > 280 || text.split('\n').length > 6;
        },

        // ------------------------------------------------------------ repeating send

        /** "har 1 soat 30 daq" */
        every(minutes) {
            return t.every.replace(':time', this.$fmt.minutes(minutes, t.units));
        },

        /** "3 / 10", or "3" when it repeats until stopped. */
        get loopRounds() {
            const loop = this.group?.loop;
            if (!loop) return '';
            if (!loop.max_rounds) return this.$fmt.number(loop.rounds_done);

            return t.rounds_of.replace(':done', this.$fmt.number(loop.rounds_done)).replace(':max', this.$fmt.number(loop.max_rounds));
        },

        /** Countdown to the next round; overdue rounds wait for the minute tick. */
        get nextRound() {
            const at = this.group?.loop?.next_run_at;
            if (!at) return '';
            const left = (new Date(at).getTime() - this.now) / 1000;
            if (left <= 0) return t.loop_due;

            return left < 86400 ? this.$fmt.clock(left) : this.$fmt.relative(at);
        },

        // ------------------------------------------------------------ actions

        async stop() {
            const body = this.group?.loop?.active ? t.stop_body_loop : t.stop_body;
            const ok = await this.$store.confirm.ask({ title: t.stop_title, body, action: t.stop });
            if (ok && (await this.$act(() => http.post(urls.cancel)))) this.load().catch(() => {});
        },

        async stopLoop() {
            const url = this.group?.loop?.stop_url;
            if (!url) return;
            const ok = await this.$store.confirm.ask({ title: t.loop_stop_title, body: t.loop_stop_body, action: t.loop_stop });
            if (ok && (await this.$act(() => http.post(url)))) this.load().catch(() => {});
        },

        async retry() {
            const ok = await this.$store.confirm.ask({
                title: t.retry_title,
                body: t.retry_body.replace(':count', this.$fmt.number(this.counts.failed)),
                action: t.retry,
                danger: false,
            });
            if (!ok) return;
            const result = await this.$act(() => http.post(urls.retry));
            if (result?.redirect) window.location.href = result.redirect;
        },

        copyFailed() {
            if (!this.failedPeers.length) {
                this.$store.toast.show(t.nothing_failed, 'neutral');
                return;
            }
            navigator.clipboard
                .writeText(this.failedPeers.join('\n'))
                .then(() => this.$store.toast.show(t.copied_failed.replace(':count', this.failedPeers.length), 'neutral', 2500))
                .catch(() => this.$store.toast.error(window.__t?.copy_failed ?? 'Copy failed'));
        },

        isRemoved(peer) {
            return Object.keys(this.removed).some((key) => key.endsWith(`|${peer}`));
        },

        /** One editable catalog: confirm and remove; several: choose in a dialog first. */
        async askRemove(message) {
            if (this.editableCatalogs.length === 1) {
                const catalog = this.editableCatalogs[0];
                const ok = await this.$store.confirm.ask({
                    title: t.remove_title,
                    body: t.remove_body.replace(':peer', message.peer).replace(':catalog', catalog.title),
                    action: t.remove,
                });
                if (ok) await this.removePeer(catalog, message.peer);
                return;
            }
            this.removeTarget = message;
            this.removeCatalog = this.editableCatalogs[0]?.id ?? null;
            this.removeModal = true;
        },

        async confirmRemove() {
            const catalog = this.editableCatalogs.find((c) => c.id === Number(this.removeCatalog));
            if (!catalog || !this.removeTarget) return;
            if (await this.removePeer(catalog, this.removeTarget.peer)) this.removeModal = false;
        },

        async removePeer(catalog, peer) {
            const result = await this.$act(() => http.post(urls.removePeer.replace('__ID__', catalog.id), { peer }));
            if (result) this.removed = { ...this.removed, [`${catalog.id}|${peer}`]: true };
            return result;
        },
    };
}
