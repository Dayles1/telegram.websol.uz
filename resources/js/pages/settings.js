import { http } from '../lib/http';

/**
 * Settings page. The form itself is a plain PUT (server validation, flash message);
 * this component only previews what the pacing numbers mean for one account (how
 * many accounts send at once is the number of "sending" queue processes on the
 * server, not a setting) and runs the two JSON actions: send a test alert, run
 * the minute tick now.
 *
 *   urls   { testAlert, sweep }
 *   values current form values (old input first)
 *   t      settings.js strings
 *   configured whether the alert bot is set up in .env
 */
export default function settingsPage({ urls, values, t, configured }) {
    return {
        configured,
        busy: false,
        sweeping: false,
        min: Number(values.send_delay_min),
        max: Number(values.send_delay_max),
        limit: Number(values.daily_limit_per_phone),
        alertsEnabled: Boolean(Number(values.alerts_enabled)),

        get averageDelay() {
            return (Math.max(0, this.min) + Math.max(this.min, this.max)) / 2;
        },

        /** Messages one account sends per hour at this pace (ignoring Telegram's own waits). */
        get perHour() {
            return this.averageDelay > 0 ? Math.floor(3600 / this.averageDelay) : null;
        },

        /** How long one account needs to reach its daily limit. */
        get limitHours() {
            if (!this.limit || !this.perHour) return null;
            return Math.round((this.limit / this.perHour) * 10) / 10;
        },

        get pacingText() {
            if (this.max < this.min) return t.delay_order;
            if (!this.perHour) return t.no_delay;
            let text = t.per_hour.replace(':n', this.$fmt.number(this.perHour));
            if (this.limitHours !== null) text += ' ' + t.limit_hours.replace(':h', this.limitHours);
            return text;
        },

        async testAlert() {
            await this.$act(() => http.post(urls.testAlert));
        },

        async sweep() {
            await this.$act(() => http.post(urls.sweep), { busy: 'sweeping' });
        },
    };
}
