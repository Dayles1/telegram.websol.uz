import { http } from '../lib/http';
import { poll } from '../lib/poll';

/** Home page: today's numbers, live sends, my accounts. Fast polling while something is sending. */
export default function dashboardPage({ url }) {
    return {
        loading: true,
        today: { sent: 0, failed: 0, pending: 0, groups: 0 },
        week: [],
        active: [],
        recent: [],
        phones: [],

        init() {
            this.poller = poll(async () => {
                Object.assign(this, await http.get(url));
                this.loading = false;
            }, () => (this.active.length ? 3000 : 15000));
        },

        destroy() {
            this.poller?.stop();
        },

        get successRate() {
            const done = this.today.sent + this.today.failed;
            return done ? Math.round((this.today.sent / done) * 100) : null;
        },
    };
}
