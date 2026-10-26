/**
 * Catalog create/edit form. The form posts normally (server validation and
 * old() values work as usual); this component adds a live preview of how the
 * pasted peers will be saved. The normalization mirrors App\Sending\Peers.
 */

// PHP trim($peer, " \t\n\r\0\x0B/")
const TRIM = /^[ \t\n\r\0\x0B/]+|[ \t\n\r\0\x0B/]+$/g;
const LINK = /^(?:https?:\/\/)?(?:www\.)?(?:t|telegram)\.me\/([A-Za-z][A-Za-z0-9_]{3,31})$/i;
const USERNAME = /^[A-Za-z][A-Za-z0-9_]{3,31}$/;

/** Same as Peers::normalize(). */
export function normalizePeer(raw) {
    const peer = String(raw).replace(TRIM, '');
    if (peer === '') return '';

    const link = peer.match(LINK);
    if (link) return `@${link[1]}`;

    const bare = peer.replace(/^@+/, '');
    if (USERNAME.test(bare) && !peer.includes('/')) return `@${bare}`;

    return peer;
}

/** Looks like something Telegram can resolve: @username, a numeric id, an invite link. */
function looksValid(peer) {
    return (
        /^@[A-Za-z][A-Za-z0-9_]{3,31}$/.test(peer) ||
        /^-?\d{5,20}$/.test(peer) ||
        /^(?:https?:\/\/)?(?:www\.)?(?:t|telegram)\.me\/(?:\+|joinchat\/)[\w-]{5,}$/i.test(peer)
    );
}

/** Same as Peers::parse(), plus counters for the preview. */
export function parsePeers(text) {
    const seen = new Map();
    let lines = 0;
    for (const item of String(text).split(/[\r\n,;]+/)) {
        const peer = normalizePeer(item);
        if (peer === '') continue;
        lines++;
        const key = peer.startsWith('@') ? peer.toLowerCase() : peer;
        if (!seen.has(key)) seen.set(key, peer);
    }
    const peers = [...seen.values()];

    return { peers, lines, duplicates: lines - peers.length, invalid: peers.filter((p) => !looksValid(p)) };
}

export default function catalogFormPage({ title, peersText, isGlobal, timer, presets }) {
    return {
        title,
        peersText,
        isGlobal: !!isGlobal,
        timer: timer === null || timer === undefined ? '' : String(timer),
        presets,
        parsed: parsePeers(peersText),
        initial: null,
        submitting: false,
        parseTimer: null,

        init() {
            this.initial = this.snapshot();
            this.$watch('peersText', () => {
                clearTimeout(this.parseTimer);
                const delay = this.peersText.length > 20000 ? 250 : 60;
                this.parseTimer = setTimeout(() => (this.parsed = parsePeers(this.peersText)), delay);
            });
            this.onUnload = (e) => {
                if (this.dirty && !this.submitting) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.onUnload);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.onUnload);
        },

        snapshot() {
            return JSON.stringify([this.title, this.peersText, this.isGlobal, this.timer]);
        },

        get dirty() {
            return this.initial !== null && this.snapshot() !== this.initial;
        },

        get chips() {
            return this.parsed.peers.slice(0, 50);
        },

        isInvalid(peer) {
            return this.parsed.invalid.includes(peer);
        },

        /** Rewrites the textarea as the list that will be saved. */
        tidy() {
            this.peersText = this.parsed.peers.join('\n');
        },

        setTimer(value) {
            this.timer = value === null ? '' : String(value);
        },

        isPreset(value) {
            return value === null ? this.timer === '' || this.timer === '0' : this.timer === String(value);
        },
    };
}
