import { http } from '../lib/http';
import { poll } from '../lib/poll';

/**
 * Monitoring overview: one snapshot of the whole system (App\Monitoring\SystemOverview),
 * refreshed every 3 seconds. A one-second clock drives "updated Xs ago", job run times
 * and flood countdowns without extra requests.
 *
 *   urls   { data, send (with __ID__), event (with __ID__), phones }
 *   t      monitoring.js strings (units)
 *   errors errors.codes map (error_key -> text with :time / :seconds)
 *   phones account id -> phone number, for worker rows
 */
export default function monitoringPage({ urls, t, errors, phones }) {
    return {
        loading: true,
        stale: false,
        updatedAt: 0,
        now: Date.now(),

        processes: {
            tick: { alive: false, at: null, queued: 0 },
            workers: [],
            backoff: [],
            queue: { alive: false, last_beat: null, waiting: 0, delayed: 0, reserved: 0, oldest_wait_seconds: null, failed: 0 },
            alerts_configured: false,
        },
        sending: { groups: [], queued_messages: 0, in_flight: 0, active_groups: 0 },
        today: { sent: 0, failed: 0, pending: 0, canceled: 0, groups: 0, accounts: 0 },
        last_24h: [],
        accounts: { by_status: {}, attention: [], daily_limit: 0 },
        problems: { open: 0, critical: 0, latest: [] },

        init() {
            this.poller = poll(() => this.load(), 3000);
            this.clock = setInterval(() => (this.now = Date.now()), 1000);
        },

        destroy() {
            this.poller?.stop();
            clearInterval(this.clock);
        },

        async load() {
            try {
                const data = await http.get(urls.data);
                this.processes = data.processes;
                this.sending = data.sending;
                this.today = data.today;
                this.last_24h = data.last_24h;
                this.accounts = data.accounts;
                this.problems = data.problems;
                this.updatedAt = Date.now();
                this.stale = false;
            } catch (e) {
                this.stale = true;
                throw e;
            } finally {
                this.loading = false;
            }
        },

        refresh() {
            this.poller.now();
        },

        // ------------------------------------------------------------ derived

        /** The minute tick (cron): the safety net of the send queue. */
        get tick() {
            return this.processes.tick;
        },

        /** Send jobs running now: { account_id, pid, started_at (unix) }. */
        get workers() {
            return this.processes.workers ?? [];
        },

        /** Accounts whose send job keeps failing: { account_id, failures }. */
        get backoff() {
            return this.processes.backoff ?? [];
        },

        get queue() {
            return this.processes.queue;
        },

        /** Seconds since the last successful refresh. */
        get ago() {
            return this.updatedAt ? Math.max(0, Math.round((this.now - this.updatedAt) / 1000)) : null;
        },

        get down() {
            return !this.loading && (!this.tick.alive || !this.queue.alive);
        },

        get successRate() {
            const done = this.today.sent + this.today.failed;
            return done ? Math.round((this.today.sent / done) * 100) : null;
        },

        get statusList() {
            return Object.entries(this.accounts.by_status ?? {})
                .map(([status, count]) => ({ status, count }))
                .sort((a, b) => b.count - a.count);
        },

        get accountsTotal() {
            return this.statusList.reduce((sum, s) => sum + s.count, 0);
        },

        // ------------------------------------------------------------ helpers

        /** 93784 -> "1 d 2 h", 125 -> "2 m 5 s". */
        span(total) {
            const s = Math.max(0, Math.floor(total ?? 0));
            const d = Math.floor(s / 86400);
            const h = Math.floor((s % 86400) / 3600);
            const m = Math.floor((s % 3600) / 60);
            const u = t.units;
            if (d) return `${d} ${u.d} ${h} ${u.h}`;
            if (h) return `${h} ${u.h} ${m} ${u.m}`;
            if (m) return `${m} ${u.m} ${s % 60} ${u.s}`;
            return `${s % 60} ${u.s}`;
        },

        /** Seconds elapsed since an ISO date or a unix timestamp. */
        since(value) {
            if (!value) return 0;
            const ms = typeof value === 'number' ? value * 1000 : Date.parse(value);
            return (this.now - ms) / 1000;
        },

        /** Seconds left until an ISO date or a unix timestamp. */
        until(value) {
            return -this.since(value);
        },

        phoneOf(accountId) {
            return phones[accountId] ?? `#${accountId}`;
        },

        /** Human text for an error key; "flood_wait_120" -> "... 2 m 0 s". Unknown keys stay as they are. */
        errorText(key) {
            if (!key) return '';
            let base = String(key).toLowerCase();
            let seconds = null;
            const m = base.match(/^(.*)_(\d+)$/);
            if (m) [base, seconds] = [m[1], Number(m[2])];
            const text = errors[base];
            if (!text) return key;
            return text.replace(':time', seconds !== null ? this.span(seconds) : '?').replace(':seconds', seconds ?? '?');
        },

        sendUrl(id) {
            return urls.send.replace('__ID__', id);
        },

        eventUrl(id) {
            return urls.event.replace('__ID__', id);
        },

        usage(account) {
            const limit = this.accounts.daily_limit;
            return limit ? Math.min(100, Math.round((account.sent_today / limit) * 100)) : 0;
        },
    };
}
