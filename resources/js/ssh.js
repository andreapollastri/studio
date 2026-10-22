/**
 * The terminals of the SSH page: xterm.js, loaded on first use so the rest of
 * Studio stays light. Livewire owns connections and keys and hands out a
 * one-time ticket per terminal (open()); each terminal is then a WebSocket to
 * the SSH gateway, which speaks JSON both ways (see ssh-gateway/src/session.js).
 * On the SSH page a session can show the file manager of its host instead
 * (view 'files'): a Livewire component per connection, over SFTP (browse()).
 *
 * A session outlives the page: the gateway keeps the shell for a while when the
 * page goes without closing it (another page of Studio, a reload), and gives it
 * back, with what it wrote meanwhile, to a page that shows its resume token and
 * a fresh ticket of the same person (resume()). The page keeps the tokens of
 * its open sessions in localStorage, one list per place (the SSH page, each
 * project's Terminal tab); × ends a session for good.
 */
import { FONT, loadXterm, THEME } from './xterm';

/** Close codes of the gateway that say why without an error message reaching the page. */
const CLOSE_REASONS = {
    4401: 'The session ticket expired: reconnect.',
    4403: 'This page may not open SSH sessions.',
    4429: 'Too many SSH sessions open at once.',
    4409: 'This session is now open on another page.',
    4410: 'The session ended while you were away: open the connection again.',
    4502: 'Studio could not be reached by the SSH gateway.',
    1006: 'The SSH gateway cannot be reached. Is the studio-ssh service running?',
};

/** What the touch key bar sends: the keys a phone's keyboard lacks. */
export const KEYS = {
    esc: '\x1b',
    tab: '\t',
    up: '\x1b[A',
    down: '\x1b[B',
    right: '\x1b[C',
    left: '\x1b[D',
    home: '\x1b[H',
    end: '\x1b[F',
    'ctrl-c': '\x03',
    'ctrl-d': '\x04',
    pipe: '|',
    tilde: '~',
    slash: '/',
    dash: '-',
};

/** Ctrl + a key: @ to _ (letters either case) map to 0x00–0x1f, ? to DEL. */
export function controlOf(char) {
    if (char === '?') return '\x7f';
    const code = char.toUpperCase().charCodeAt(0);
    return code >= 64 && code <= 95 ? String.fromCharCode(code - 64) : char;
}

/** How long a page remembers a session it left: longer than the gateway keeps one. */
const REMEMBER_MS = 12 * 60 * 60 * 1000;

/** @param {string} scope where the sessions are open: 'ssh' (the SSH page) or 'project-<uuid>' (a project's Terminal tab) */
function sshTerminals(scope = 'ssh') {
    // xterm instances, sockets and observers stay out of Alpine's reactive proxies.
    const runtime = new Map();
    let next = 1;
    const storeKey = `studio.ssh.open.${scope}`;
    // sessions this page remembers and could not take back yet (a password to confirm first): kept, not dropped
    let pending = [];
    // the page is going away: its sockets close, the sessions stay remembered
    let leaving = false;

    return {
        sessions: [],
        active: null,
        layout: 'tabs',
        filter: '',
        // the group the list shows: '' all of them, a group's uuid, 'none' the connections in no group
        group: '',
        ctrl: false,

        init() {
            try { this.layout = localStorage.getItem('studio.ssh.layout') === 'grid' ? 'grid' : 'tabs'; } catch {}

            this.onLeave = () => { leaving = true; };
            this.onBack = () => { leaving = false; };
            window.addEventListener('pagehide', this.onLeave);
            window.addEventListener('pageshow', this.onBack);
            this.restore();
        },

        destroy() {
            window.removeEventListener('pagehide', this.onLeave);
            window.removeEventListener('pageshow', this.onBack);
            // another page of Studio: the sockets go, the shells wait on the gateway for this page to come back
            for (const id of [...runtime.keys()]) this.dispose(id);
        },

        /** The sessions this page left: each taken back with a fresh ticket and its resume token. */
        async restore() {
            const saved = this.saved();
            pending = saved;
            for (const item of saved) {
                if (this.sessions.some((s) => s.resume === item.resume)) continue;
                const info = await this.$wire.resume(item.connectionId);
                if (!info?.ticket) continue;
                pending = pending.filter((p) => p.resume !== item.resume);
                await this.add(item.connectionId, info, { title: item.title, resume: item.resume });
            }
        },

        saved() {
            try {
                const items = JSON.parse(localStorage.getItem(storeKey) ?? '[]');
                return Array.isArray(items) ? items.filter((i) => i?.resume && i?.connectionId && Date.now() - (i.at ?? 0) < REMEMBER_MS) : [];
            } catch {
                return [];
            }
        },

        /** The open sessions, as a later page needs them to take them back. */
        remember() {
            const items = [
                ...this.sessions.filter((s) => s.resume).map((s) => ({ connectionId: s.connectionId, title: s.title, resume: s.resume, at: Date.now() })),
                ...pending,
            ];
            try {
                if (items.length) localStorage.setItem(storeKey, JSON.stringify(items));
                else localStorage.removeItem(storeKey);
            } catch {}
        },

        matches(text) {
            const q = this.filter.trim().toLowerCase();
            return q === '' || text.includes(q);
        },

        /** A group of the list under the group filter ('' all, a group's uuid, 'none') and the search: shown with a row that matches, or empty while nothing is searched. */
        section(texts, key) {
            if (this.group !== '' && this.group !== key) return false;
            return this.filter.trim() === '' || texts.some((text) => this.matches(text));
        },

        dot(session) {
            return {
                connecting: 'bg-amber-400 animate-pulse',
                hostkey: 'bg-amber-400',
                ready: 'bg-green-500',
                closed: 'bg-zinc-500',
                error: 'bg-red-500',
            }[session.status];
        },

        async open(connectionId) {
            const info = await this.$wire.open(connectionId);
            if (!info?.ticket) return;

            return this.add(connectionId, info);
        },

        /** A terminal for a ticket: a new session, or (resume) one this page left. */
        async add(connectionId, info, { title = null, resume = null } = {}) {
            const id = next++;
            const same = this.sessions.filter((s) => s.connectionId === connectionId).length;
            this.sessions.push({
                id,
                connectionId,
                title: title ?? (same ? `${info.name} (${same + 1})` : info.name),
                address: info.address,
                status: 'connecting',
                error: null,
                fingerprint: null,
                view: 'terminal',
                resume,
            });
            this.active = id;

            await this.$nextTick();
            const term = await this.mount(id);
            if (term) this.connect(id, info, resume);
            // Below lg the connections sit above the terminals: bring the new one into view.
            if (window.matchMedia('(width < 64rem)').matches) this.$refs.terminals?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return id;
        },

        /** The files of a host: its open session turns to them, or a new one opens on them. */
        async openFiles(connectionId) {
            const open = this.sessions.find((s) => s.connectionId === connectionId);
            const id = open ? open.id : await this.open(connectionId);
            if (!id) return;
            this.active = id;
            await this.showFiles(id);
        },

        filesShown() {
            return this.find(this.active)?.view === 'files';
        },

        activeConnection() {
            return this.find(this.active)?.connectionId ?? null;
        },

        async showFiles(id) {
            const s = this.find(id);
            if (s && (await this.$wire.browse(s.connectionId))) s.view = 'files';
        },

        showTerminal(id) {
            const s = this.find(id);
            if (!s) return;
            s.view = 'terminal';
            this.focus(id);
        },

        /** A key a touch keyboard does not have, from the key bar under the terminal. */
        key(name) {
            if (name === 'ctrl') {
                this.ctrl = !this.ctrl;
            } else if (this.active !== null && KEYS[name] !== undefined) {
                this.send(this.active, { type: 'data', data: KEYS[name] });
                this.ctrl = false;
            }
            runtime.get(this.active)?.term.focus();
        },

        async mount(id) {
            const { Terminal, FitAddon, WebLinksAddon } = await loadXterm();
            const element = document.getElementById(`ssh-term-${id}`);
            if (!element || !this.find(id)) return null;

            const term = new Terminal({
                cursorBlink: true,
                fontFamily: FONT,
                fontSize: 13,
                lineHeight: 1.15,
                scrollback: 10000,
                allowProposedApi: false,
                theme: THEME,
            });
            const fit = new FitAddon();
            term.loadAddon(fit);
            term.loadAddon(new WebLinksAddon((event, uri) => window.open(uri, '_blank', 'noopener')));
            term.open(element);
            fit.fit();

            const observer = new ResizeObserver(() => {
                // A hidden tab has no size: fit it when it shows again.
                if (element.offsetWidth > 0 && element.offsetHeight > 0) fit.fit();
            });
            observer.observe(element);

            term.onData((data) => {
                // Ctrl from the touch key bar: the next letter becomes its control character
                if (this.ctrl && data.length === 1) {
                    data = controlOf(data);
                    this.ctrl = false;
                }
                this.send(id, { type: 'data', data });
            });
            term.onResize(({ cols, rows }) => this.send(id, { type: 'resize', cols, rows }));
            term.focus();

            runtime.set(id, { term, fit, observer, ws: null });
            return term;
        },

        connect(id, info, resume = null) {
            const entry = runtime.get(id);
            const session = this.find(id);
            if (!entry || !session) return;

            const { cols, rows } = entry.term;
            const url = `${info.url}?ticket=${encodeURIComponent(info.ticket)}&cols=${cols}&rows=${rows}${resume ? `&resume=${encodeURIComponent(resume)}` : ''}`;
            const ws = new WebSocket(url);
            entry.ws = ws;
            session.status = 'connecting';
            session.error = null;
            entry.term.write(`\x1b[90m${resume ? 'Back to' : 'Connecting to'} ${info.address}…\x1b[0m\r\n`);

            ws.onmessage = (event) => {
                let message;
                try { message = JSON.parse(event.data); } catch { return; }
                const s = this.find(id);
                if (!s) return;

                if (message.type === 'data') {
                    entry.term.write(message.data);
                } else if (message.type === 'replay') {
                    // what the shell wrote while the page was away, the screen redrawn from it
                    entry.term.reset();
                    entry.term.write(message.data);
                } else if (message.type === 'session') {
                    s.resume = message.resume;
                    this.remember();
                } else if (message.type === 'status') {
                    s.status = message.state === 'ready' ? 'ready' : 'connecting';
                    if (message.state === 'ready') {
                        entry.fit.fit();
                        this.send(id, { type: 'resize', cols: entry.term.cols, rows: entry.term.rows });
                        if (this.active === id) entry.term.focus();
                    }
                } else if (message.type === 'hostkey') {
                    s.status = 'hostkey';
                    s.fingerprint = message.fingerprint;
                } else if (message.type === 'error') {
                    s.status = 'error';
                    s.error = message.message;
                    entry.term.write(`\r\n\x1b[31m${message.message}\x1b[0m\r\n`);
                }
            };

            ws.onclose = (event) => {
                const s = this.find(id);
                if (!s || entry.ws !== ws || leaving) return;
                // taken back on another page, or ended while this one was away
                if (event.code === 4409 || event.code === 4410) {
                    s.status = 'closed';
                    s.error = CLOSE_REASONS[event.code];
                    entry.term.write(`\r\n\x1b[90m${s.error}\x1b[0m\r\n`);
                    if (event.code === 4410) s.resume = null;
                    this.remember();
                    return;
                }
                // the shell ended: nothing to take back
                s.resume = null;
                this.remember();
                if (s.status !== 'error') {
                    const reason = s.status === 'ready' ? null : CLOSE_REASONS[event.code];
                    s.status = reason ? 'error' : 'closed';
                    s.error = reason ?? null;
                    if (reason) entry.term.write(`\r\n\x1b[31m${reason}\x1b[0m\r\n`);
                }
            };
        },

        send(id, message) {
            const ws = runtime.get(id)?.ws;
            if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message));
        },

        answerHostKey(id, accept) {
            const s = this.find(id);
            if (!s) return;
            this.send(id, { type: 'hostkey', accept });
            if (accept) {
                s.status = 'connecting';
                this.$wire.trustHostKey(s.connectionId, s.fingerprint);
            }
        },

        async reconnect(id) {
            const s = this.find(id);
            const entry = runtime.get(id);
            if (!s || !entry) return;

            const info = await this.$wire.open(s.connectionId);
            if (!info?.ticket) return;
            try { entry.ws?.close(); } catch {}
            s.resume = null;
            entry.term.write('\r\n');
            this.connect(id, info);
            entry.term.focus();
        },

        focus(id) {
            this.active = id;
            this.$nextTick(() => {
                const entry = runtime.get(id);
                entry?.fit.fit();
                entry?.term.focus();
            });
        },

        async close(id) {
            const s = this.find(id);
            if (s?.status === 'ready' && !(await window.studioDialog.confirm({
                title: `Close the session on ${s.address}?`,
                body: 'What runs in it stops with it, unless it runs under tmux or screen.',
                confirm: 'Close',
                danger: true,
            }))) return;

            // × ends the shell for good: the gateway does not keep it
            this.send(id, { type: 'close' });
            this.dispose(id);
            const index = this.sessions.findIndex((x) => x.id === id);
            if (index !== -1) this.sessions.splice(index, 1);
            this.remember();
            if (this.active === id) {
                const neighbour = this.sessions[Math.min(index, this.sessions.length - 1)];
                this.active = neighbour?.id ?? null;
                if (neighbour) this.focus(neighbour.id);
            }
        },

        dispose(id) {
            const entry = runtime.get(id);
            if (!entry) return;
            runtime.delete(id);
            // its own closing must not forget the session: the page is leaving it, not ending it
            if (entry.ws) {
                entry.ws.onclose = null;
                entry.ws.onmessage = null;
                try { entry.ws.close(1000); } catch {}
            }
            entry.observer.disconnect();
            entry.term.dispose();
        },

        setLayout(layout) {
            this.layout = layout;
            try { localStorage.setItem('studio.ssh.layout', layout); } catch {}
            this.$nextTick(() => {
                for (const entry of runtime.values()) entry.fit.fit();
                if (this.active) runtime.get(this.active)?.term.focus();
            });
        },

        find(id) {
            return this.sessions.find((s) => s.id === id);
        },
    };
}

const register = () => window.Alpine.data('sshTerminals', sshTerminals);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
