import { http, HttpError } from '../lib/http';

/** Minutes per unit of the repeat interval. */
const UNIT_MINUTES = { min: 1, hour: 60, day: 1440 };

const isFileDrag = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');

/**
 * The send form: account -> catalogs -> text and/or a file, optionally
 * repeating, with a live summary on the side.
 *
 * The summary (unique peers, cooldowns for the chosen account, today's usage)
 * comes from GET send.preview, debounced while the user ticks catalogs.
 * The message preview is rendered from parsed segments with x-text only: the
 * text is never injected as HTML. The form goes as multipart (FormData) with
 * upload progress, since a file can be up to 50 MB.
 *
 *   limits  { text, caption, repeatMinutes, rounds, fileBytes, photoBytes, photoTypes }
 *   maxFile the file limit as text ("50 MB")
 */
export default function sendPage({ phones, catalogs, selectedPhone, admin, urls, t, units, limits, maxFile }) {
    const sendable = phones.filter((p) => p.can.send);
    const preselected = phones.find((p) => p.id === selectedPhone && p.can.send) ?? (sendable.length === 1 ? sendable[0] : null);

    return {
        phones,
        catalogs,
        maxLength: limits.text,
        limits,
        t,
        phoneId: preselected?.id ?? null,
        picking: preselected === null,
        phoneSearch: '',
        phoneScope: 'all', // admin / superadmin: every account they oversee or only their own
        showNotReady: false,
        selected: [],
        search: '',
        catalogScope: 'all',
        message: '',
        preview: { unique_peers: 0, total_peers: 0, cooldowns: {}, sent_today: null, daily_limit: 0 },
        previewLoading: false,
        busy: false,
        progress: null,
        errors: {},
        timer: null,
        controller: null,

        file: null,
        fileUrl: null,
        dragging: false,

        repeat: false,
        every: 1,
        unit: 'hour',
        maxRounds: '',

        init() {
            this.$watch('phoneId', () => this.refresh());
            this.$watch('selected', () => this.refresh());
            this.$watch('message', () => delete this.errors.message);
            this.$watch('every', () => delete this.errors.repeat_every_minutes);
            this.$watch('unit', () => delete this.errors.repeat_every_minutes);
            this.$watch('maxRounds', () => delete this.errors.max_rounds);
            this.refresh(0);
        },

        destroy() {
            if (this.fileUrl) URL.revokeObjectURL(this.fileUrl);
        },

        // ---------------------------------------------------------- accounts

        get phone() {
            return this.phones.find((p) => p.id === this.phoneId) ?? null;
        },

        choose(phone) {
            if (!phone.can.send) return;
            this.phoneId = phone.id;
            this.picking = false;
            this.phoneSearch = '';
            delete this.errors.phone_id;
        },

        openPicker() {
            this.picking = true;
            this.$nextTick(() => this.$refs.phoneSearch?.focus());
        },

        closePicker() {
            if (!this.phone) return;
            this.picking = false;
            this.phoneSearch = '';
        },

        /** Enter in the search box: the first account that can send. */
        pickFirst() {
            const first = this.matchedPhones.find((p) => p.can.send);
            if (first) this.choose(first);
        },

        initial(phone) {
            return (phone?.name || '?').replace('@', '').slice(0, 1).toUpperCase();
        },

        get readyCount() {
            return sendable.length;
        },

        scopeCount(scope) {
            return scope === 'mine' ? this.phones.filter((p) => p.is_mine).length : this.phones.length;
        },

        get scopedPhones() {
            return admin && this.phoneScope === 'mine' ? this.phones.filter((p) => p.is_mine) : this.phones;
        },

        /** Hidden until asked for: accounts that can't send (logged out, revoked ...). */
        get notReadyCount() {
            return this.scopedPhones.filter((p) => !p.can.send).length;
        },

        /** By name, number (any formatting), username or owner. */
        get matchedPhones() {
            const q = this.phoneSearch.trim().toLowerCase();
            const digits = q.replace(/\D/g, '');

            return this.scopedPhones.filter((p) => {
                if (!p.can.send && !this.showNotReady) return false;
                if (!q) return true;
                if (`${p.name} ${p.phone} ${p.username ?? ''} ${p.owner?.name ?? ''}`.toLowerCase().includes(q)) return true;

                return digits.length >= 3 && p.phone.replace(/\D/g, '').includes(digits);
            });
        },

        /** An admin or the superadmin sees accounts grouped by owner, their own first; a user sees one plain list. */
        get phoneGroups() {
            if (!admin) return [{ key: 'all', label: null, phones: this.matchedPhones }];

            const groups = new Map();
            for (const p of this.matchedPhones) {
                const key = p.is_mine ? 'mine' : `u${p.owner?.id ?? 0}`;
                if (!groups.has(key)) groups.set(key, { key, label: p.is_mine ? t.scope_mine : (p.owner?.name ?? '—'), mine: p.is_mine, phones: [] });
                groups.get(key).phones.push(p);
            }

            return [...groups.values()].sort((a, b) => b.mine - a.mine || a.label.localeCompare(b.label));
        },

        phoneRowClass(phone) {
            if (!phone.can.send) return 'cursor-not-allowed opacity-55';

            return this.phoneId === phone.id ? 'cursor-pointer bg-brand-50/60 dark:bg-brand-500/10' : 'cursor-pointer hover:bg-[var(--surface-2)]';
        },

        // ---------------------------------------------------------- catalogs

        get filtered() {
            const q = this.search.trim().toLowerCase();
            const scoped = admin && this.catalogScope === 'mine' ? this.catalogs.filter((c) => c.is_mine) : this.catalogs;
            if (!q) return scoped;

            return scoped.filter((c) => `${c.title} ${c.owner?.name ?? ''}`.toLowerCase().includes(q));
        },

        isSelected(catalog) {
            return this.selected.includes(catalog.id);
        },

        toggle(catalog) {
            this.selected = this.isSelected(catalog) ? this.selected.filter((id) => id !== catalog.id) : [...this.selected, catalog.id];
            delete this.errors.catalog_ids;
        },

        selectAll() {
            const ids = this.filtered.map((c) => c.id);
            this.selected = [...new Set([...this.selected, ...ids])];
            delete this.errors.catalog_ids;
        },

        selectNone() {
            const ids = new Set(this.filtered.map((c) => c.id));
            this.selected = this.selected.filter((id) => !ids.has(id));
        },

        get selectedPeers() {
            return this.catalogs.filter((c) => this.isSelected(c)).reduce((sum, c) => sum + c.peers_count, 0);
        },

        cooldown(catalog) {
            return this.preview.cooldowns?.[catalog.id] ?? 0;
        },

        get blockedSelected() {
            return this.selected.filter((id) => (this.preview.cooldowns?.[id] ?? 0) > 0);
        },

        duration(minutes) {
            const m = Number(minutes);
            if (m >= 1440 && m % 1440 === 0) return units.day.replace(':n', m / 1440);
            if (m >= 60 && m % 60 === 0) return units.hour.replace(':n', m / 60);

            return units.min.replace(':n', m);
        },

        // ----------------------------------------------------------- preview

        refresh(delay = 300) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadPreview(), delay);
        },

        async loadPreview() {
            this.controller?.abort();
            this.controller = new AbortController();
            this.previewLoading = true;
            try {
                this.preview = await http.get(urls.preview, { phone_id: this.phoneId, catalog_ids: this.selected }, { signal: this.controller.signal });
                this.previewLoading = false;
            } catch (e) {
                if (e?.name !== 'AbortError') this.previewLoading = false;
            }
        },

        get duplicates() {
            return Math.max(0, (this.preview.total_peers ?? 0) - (this.preview.unique_peers ?? 0));
        },

        get remaining() {
            if (!this.preview.daily_limit || this.preview.sent_today === null) return null;

            return Math.max(0, this.preview.daily_limit - this.preview.sent_today);
        },

        get usagePercent() {
            const { daily_limit: limit, sent_today: sent } = this.preview;
            return limit ? Math.min(100, Math.round(((sent ?? 0) / limit) * 100)) : 0;
        },

        get plannedPercent() {
            const limit = this.preview.daily_limit;
            if (!limit || !this.selected.length) return 0;

            return Math.min(100 - this.usagePercent, Math.ceil((this.preview.unique_peers / limit) * 100));
        },

        get overLimit() {
            return this.remaining !== null && this.selected.length > 0 && this.preview.unique_peers > this.remaining;
        },

        // ----------------------------------------------------------- message

        get length() {
            return this.message.length;
        },

        get tooLong() {
            return this.length > this.maxLength;
        },

        /** Step 3 is done: some text or a file, and the text fits. */
        get hasContent() {
            return (this.message.trim() !== '' || this.file !== null) && !this.tooLong;
        },

        /** With a file, a text longer than a caption goes as a second message. */
        get captionSplit() {
            return this.file !== null && this.length > limits.caption;
        },

        // -------------------------------------------------------------- file

        pickFile(files) {
            const picked = files?.[0];
            if (this.$refs.file) this.$refs.file.value = ''; // the same file can be picked again
            if (!picked) return;

            if (picked.size > limits.fileBytes) {
                const message = t.file_too_big.replace(':max', maxFile);
                this.errors = { ...this.errors, file: [message] };
                this.$store.toast.error(message);
                return;
            }

            this.removeFile();
            this.file = picked;
            if (picked.type.startsWith('image/')) this.fileUrl = URL.createObjectURL(picked);
        },

        /** Only a dragged file is caught: text dragged inside the textarea behaves as usual. */
        dragOver(event) {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            this.dragging = true;
        },

        drop(event) {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            this.dragging = false;
            this.pickFile(event.dataTransfer.files);
        },

        removeFile() {
            if (this.fileUrl) URL.revokeObjectURL(this.fileUrl);
            this.file = null;
            this.fileUrl = null;
            delete this.errors.file;
        },

        /** How Telegram will get it: a compressed photo or a file. */
        get fileKind() {
            if (!this.file) return null;

            return limits.photoTypes.includes(this.file.type) && this.file.size <= limits.photoBytes ? 'photo' : 'document';
        },

        // ------------------------------------------------------------ repeat

        get unitMax() {
            return Math.floor(limits.repeatMinutes / UNIT_MINUTES[this.unit]);
        },

        get repeatMinutes() {
            const every = Number(this.every);
            return Number.isInteger(every) && every > 0 ? every * UNIT_MINUTES[this.unit] : 0;
        },

        get repeatError() {
            if (!this.repeat) return null;
            const minutes = this.repeatMinutes;
            if (minutes < 1 || minutes > limits.repeatMinutes) {
                return t.repeat_invalid.replace(':max', this.$fmt.minutes(limits.repeatMinutes, units));
            }

            return null;
        },

        /** Max rounds as a number, or null for "until stopped". */
        get rounds() {
            return String(this.maxRounds ?? '').trim() === '' ? null : Number(this.maxRounds);
        },

        get roundsError() {
            if (!this.repeat || this.rounds === null) return null;
            if (!Number.isInteger(this.rounds) || this.rounds < 1 || this.rounds > limits.rounds) {
                return t.repeat_rounds_invalid.replace(':max', this.$fmt.number(limits.rounds));
            }

            return null;
        },

        /** "every 2 h · 5 rounds" or "every 2 h · until stopped". */
        get repeatText() {
            const every = t.every.replace(':time', this.$fmt.minutes(this.repeatMinutes, units));
            const rounds = this.rounds !== null && !this.roundsError ? t.rounds.replace(':n', this.$fmt.number(this.rounds)) : t.until_stopped;

            return `${every} · ${rounds}`;
        },

        /**
         * The text as styled segments: Telegram's HTML subset (b/strong, i/em,
         * u/ins, s/strike/del, code/pre, a href) becomes flags; any other tag is
         * shown as typed. Rendered with x-text, so nothing is ever parsed as HTML.
         */
        get segments() {
            const text = this.message;
            const decode = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
            const map = { b: 'b', strong: 'b', i: 'i', em: 'i', u: 'u', ins: 'u', s: 's', strike: 's', del: 's', code: 'code', pre: 'code', a: 'a', 'tg-spoiler': 'spoiler' };
            const re = /<(\/?)(b|strong|i|em|u|ins|s|strike|del|code|pre|a|tg-spoiler)(\s[^<>]*)?>/gi;
            const stack = [];
            const out = [];
            let last = 0;
            let m;

            const push = (chunk) => {
                if (!chunk) return;
                const flags = {};
                stack.forEach((f) => (flags[f] = true));
                out.push({ text: decode(chunk), ...flags });
            };

            while ((m = re.exec(text)) !== null) {
                push(text.slice(last, m.index));
                last = re.lastIndex;
                const flag = map[m[2].toLowerCase()];
                if (m[1]) {
                    const at = stack.lastIndexOf(flag);
                    if (at !== -1) stack.splice(at, 1);
                } else {
                    stack.push(flag);
                }
            }
            push(text.slice(last));

            return out.map((s, i) => ({ ...s, key: i }));
        },

        segmentClass(s) {
            return [
                s.b && 'font-semibold',
                s.i && 'italic',
                s.u && 'underline',
                s.s && 'line-through',
                s.code && 'rounded bg-black/5 px-1 font-mono text-[12px] dark:bg-white/10',
                s.a && 'text-brand-600 underline decoration-brand-300 dark:text-brand-300',
                s.spoiler && 'rounded bg-gray-300 text-transparent dark:bg-gray-600',
            ]
                .filter(Boolean)
                .join(' ');
        },

        insert(open, close) {
            const el = this.$refs.message;
            const start = el.selectionStart ?? this.message.length;
            const end = el.selectionEnd ?? start;
            const picked = this.message.slice(start, end);
            this.message = this.message.slice(0, start) + open + picked + close + this.message.slice(end);
            this.$nextTick(() => {
                el.focus();
                el.setSelectionRange(start + open.length, start + open.length + picked.length);
            });
        },

        // -------------------------------------------------------------- send

        /** Why the button is disabled, or null. */
        get blocker() {
            if (!this.phone) return t.need_phone;
            if (!this.selected.length) return t.need_catalogs;
            if (!this.message.trim() && !this.file) return t.need_message;
            if (this.tooLong) return t.too_long.replace(':max', this.maxLength);
            if (this.repeatError) return this.repeatError;
            if (this.roundsError) return this.roundsError;
            if (this.blockedSelected.length) return t.cooldown_blocked;
            if (!this.previewLoading && this.preview.unique_peers === 0) return t.need_peers;

            return null;
        },

        get buttonLabel() {
            if (!this.busy) return t.send;

            return this.progress !== null && this.progress < 100 ? t.uploading.replace(':p', this.progress) : t.sending;
        },

        /** The confirm text: who and how many, then the file and the repetition when there are. */
        confirmBody() {
            const lines = [t.confirm_body.replace(':n', this.$fmt.number(this.preview.unique_peers)).replace(':account', `${this.phone.name} (${this.phone.phone})`)];
            if (this.file) {
                lines.push(t.confirm_file.replace(':name', this.file.name).replace(':size', this.$fmt.bytes(this.file.size)));
            }
            if (this.repeat) {
                const every = this.$fmt.minutes(this.repeatMinutes, units);
                lines.push(
                    this.rounds !== null
                        ? t.confirm_repeat_rounds.replace(':every', every).replace(':rounds', this.$fmt.number(this.rounds))
                        : t.confirm_repeat.replace(':every', every),
                );
            }

            return lines.join('\n');
        },

        formData() {
            const form = new FormData();
            form.append('phone_id', this.phoneId);
            this.selected.forEach((id) => form.append('catalog_ids[]', id));
            form.append('message', this.message);
            if (this.file) form.append('file', this.file, this.file.name);
            if (this.repeat) {
                form.append('repeat_every_minutes', this.repeatMinutes);
                if (this.rounds !== null) form.append('max_rounds', this.rounds);
            }

            return form;
        },

        async submit() {
            if (this.blocker || this.busy) return;

            const ok = await this.$store.confirm.ask({
                title: this.repeat ? t.confirm_title_repeat : t.confirm_title,
                body: this.confirmBody(),
                action: t.confirm_action,
                danger: false,
            });
            if (!ok) return;

            this.busy = true;
            this.progress = this.file ? 0 : null;
            this.errors = {};
            try {
                const result = await http.upload(urls.store, this.formData(), {
                    onProgress: (p) => {
                        if (this.file) this.progress = p;
                    },
                });
                this.$store.toast.show(result.message);
                window.location.href = result.redirect;
            } catch (e) {
                this.busy = false;
                this.progress = null;
                if (!(e instanceof HttpError)) throw e;
                // 413: the web server refused the body before the app saw it
                this.errors = e.status === 413 ? { file: [t.file_too_big.replace(':max', maxFile)] } : (e.errors ?? {});
                const first = Object.values(this.errors)[0]?.[0];
                this.$store.toast.error(first ?? e.message);
                this.refresh(0);
            }
        },

        fieldError(name) {
            const key = Object.keys(this.errors).find((k) => k === name || k.startsWith(`${name}.`));
            return key ? this.errors[key][0] : null;
        },
    };
}
