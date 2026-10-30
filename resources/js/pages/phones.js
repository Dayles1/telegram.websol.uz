import { http, HttpError } from '../lib/http';
import { poll } from '../lib/poll';

/**
 * Telegram accounts page and the connect wizard.
 *
 * The wizard follows the account row's status (see App\Enums\AccountStatus):
 *   phone step -> requesting_code -> code step (code_sent / code_invalid)
 *   -> verifying -> password step (need_password / password_invalid) -> authorized
 * While a step is in flight the page polls every 1.5s; otherwise every 15s.
 * An admin picks the owner on the phone step (`owners`): an employee's phone is
 * connected for them, the code comes from the employee.
 */
export default function phonesPage({ urls, t, canSeeAll, me, owners }) {
    return {
        phones: [],
        sentToday: {},
        dailyLimit: 0,
        loading: true,
        showAll: false,
        canSeeAll,
        search: '',
        busy: false,
        t,

        // wizard
        wizard: false,
        step: 'phone', // phone | code | password | done
        me,
        ownerId: me,
        pickingOwner: false,
        ownerSearch: '',
        phoneInput: '',
        code: '',
        password: '',
        account: null,
        error: null,
        resendAt: 0,
        now: Date.now(),

        init() {
            this.poller = poll(() => this.load(), () => (this.anyInFlight ? 1500 : 15000));
            this.clock = setInterval(() => (this.now = Date.now()), 1000);
            // #connect opens the wizard; ?owner=ID (the users page) picks whose account it is
            if (window.location.hash === '#connect') {
                this.openWizard(null, Number(new URLSearchParams(window.location.search).get('owner')) || null);
                history.replaceState(null, '', window.location.pathname);
            }
        },

        destroy() {
            this.poller?.stop();
            clearInterval(this.clock);
        },

        get anyInFlight() {
            return this.phones.some((p) => p.in_flight) || (this.wizard && this.account?.in_flight);
        },

        /** By name, @username, owner, or the digits of the number ("90 123" finds +99890123...). */
        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.phones;
            const digits = q.replace(/\D+/g, '');

            return this.phones.filter(
                (p) =>
                    `${p.name} ${p.username ?? ''} ${p.owner?.name ?? ''}`.toLowerCase().includes(q) ||
                    (digits.length >= 2 && p.phone.replace(/\D+/g, '').includes(digits)),
            );
        },

        get mine() {
            return this.filtered.filter((p) => p.is_mine);
        },

        get others() {
            return this.filtered.filter((p) => !p.is_mine);
        },

        get owner() {
            return owners.find((o) => o.id === this.ownerId) ?? null;
        },

        get ownerName() {
            return this.owner?.name ?? this.account?.owner?.name ?? '';
        },

        get filteredOwners() {
            const q = this.ownerSearch.trim().toLowerCase();

            return q ? owners.filter((o) => `${o.name} ${o.email}`.toLowerCase().includes(q)) : owners;
        },

        ownerLabel(owner) {
            if (!owner) return '';
            return owner.id === me ? t.owner_me.replace(':name', owner.name) : owner.name;
        },

        get resendIn() {
            return Math.max(0, Math.ceil((this.resendAt - this.now) / 1000));
        },

        async load() {
            const data = await http.get(urls.data, { all: this.showAll ? 1 : null });
            this.phones = data.phones;
            this.sentToday = data.sent_today;
            this.dailyLimit = data.daily_limit;
            this.loading = false;

            if (this.wizard && this.account) {
                const fresh = this.phones.find((p) => p.id === this.account.id);
                if (fresh) this.follow(fresh);
            }
        },

        toggleAll() {
            this.showAll = !this.showAll;
            this.loading = true;
            this.poller.now();
        },

        /** An admin searching for an employee's number: search everyone's accounts. */
        searchAll() {
            if (this.canSeeAll && !this.showAll && this.search.trim()) {
                this.showAll = true;
                this.poller.now();
            }
        },

        // ------------------------------------------------------------- wizard

        /** An admin starts by choosing the owner, unless one is given (the users page). */
        openWizard(phone = null, ownerId = null) {
            const preset = owners.some((o) => o.id === ownerId);
            Object.assign(this, { wizard: true, step: 'phone', code: '', password: '', error: null, account: null, phoneInput: phone ?? '' });
            Object.assign(this, { ownerId: preset ? ownerId : me, pickingOwner: false });
            if (owners.length > 1 && !preset) this.pickOwner();
            else this.$nextTick(() => this.$refs.phoneInput?.focus());
        },

        pickOwner() {
            if (this.account) return;
            this.pickingOwner = true;
            this.ownerSearch = '';
            // on a phone the list stays in view; the keyboard opens only when they tap the search
            if (!window.matchMedia('(pointer: coarse)').matches) this.$nextTick(() => this.$refs.ownerSearch?.focus());
        },

        chooseOwner(owner) {
            this.ownerId = owner.id;
            this.pickingOwner = false;
            this.$nextTick(() => this.$refs.phoneInput?.focus());
        },

        resume(account) {
            this.wizard = true;
            this.account = account;
            this.ownerId = account.owner?.id ?? me;
            this.pickingOwner = false;
            this.phoneInput = account.phone;
            this.error = null;
            this.follow(account);
        },

        /** Moves the wizard to the step the account's status asks for. */
        follow(account) {
            const before = this.account?.status;
            this.account = account;

            if (account.status === 'authorized') {
                this.step = 'done';
                if (before && before !== 'authorized') this.$store.toast.show(t.connected);
            } else if (account.awaits === 'code') {
                if (this.step !== 'code') {
                    this.step = 'code';
                    this.resendAt = Date.now() + 60000;
                    this.$nextTick(() => this.$refs.codeInput?.focus());
                }
                this.error = account.status === 'code_invalid' ? account.last_error_text : null;
            } else if (account.awaits === 'password') {
                if (this.step !== 'password') {
                    this.step = 'password';
                    this.$nextTick(() => this.$refs.passwordInput?.focus());
                }
                this.error = account.status === 'password_invalid' || account.last_error ? account.last_error_text : null;
            } else if (account.status === 'failed') {
                this.step = 'phone';
                this.error = account.last_error_text ?? t.failed;
            }
        },

        async submitPhone() {
            this.error = null;
            const account = await this.send(() => http.post(urls.store, { phone: this.phoneInput, user_id: this.ownerId }));
            if (account) {
                this.account = account.data;
                // someone else's account is listed only with "all": the wizard follows it through the list
                if (this.ownerId !== me) this.showAll = true;
                this.poller.now();
            }
        },

        async submitCode() {
            this.error = null;
            const account = await this.send(() => http.post(urls.code.replace('__ID__', this.account.id), { code: this.code }));
            if (account) {
                this.account = account.data;
                this.code = '';
                this.poller.now();
            }
        },

        async submitPassword() {
            this.error = null;
            const account = await this.send(() => http.post(urls.password.replace('__ID__', this.account.id), { password: this.password }));
            this.password = '';
            if (account) {
                this.account = account.data;
                this.poller.now();
            }
        },

        async resend() {
            if (this.resendIn > 0 || !this.account) return;
            this.step = 'phone';
            const account = await this.send(() => http.post(urls.relogin.replace('__ID__', this.account.id)));
            if (account) {
                this.account = account.data;
                this.poller.now();
            }
        },

        async cancelWizard() {
            if (this.account && this.account.status !== 'authorized' && !this.account.in_flight) {
                await this.send(() => http.post(urls.cancel.replace('__ID__', this.account.id)));
            }
            this.wizard = false;
            this.poller.now();
        },

        /** Runs a request, puts a failure into the wizard's error line. */
        async send(fn) {
            this.busy = true;
            try {
                return await fn();
            } catch (e) {
                this.error = e instanceof HttpError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t.failed;
                return null;
            } finally {
                this.busy = false;
            }
        },

        // ------------------------------------------------------------ actions

        async check(phone) {
            await this.$act(() => http.post(urls.check.replace('__ID__', phone.id)));
        },

        async relogin(phone) {
            const result = await this.$act(() => http.post(urls.relogin.replace('__ID__', phone.id)), { quiet: true });
            if (result) this.resume(result.data);
        },

        async logout(phone) {
            const ok = await this.$store.confirm.ask({ title: t.logout_title, body: t.logout_body.replace(':phone', phone.phone), action: t.logout });
            if (ok && (await this.$act(() => http.post(urls.logout.replace('__ID__', phone.id))))) this.poller.now();
        },

        async remove(phone) {
            const ok = await this.$store.confirm.ask({ title: t.remove_title, body: t.remove_body.replace(':phone', phone.phone), action: t.remove });
            if (ok && (await this.$act(() => http.delete(urls.destroy.replace('__ID__', phone.id))))) this.poller.now();
        },

        usage(phone) {
            const sent = this.sentToday[phone.id] ?? 0;
            return this.dailyLimit ? Math.min(100, Math.round((sent / this.dailyLimit) * 100)) : 0;
        },
    };
}
