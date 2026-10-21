/**
 * The terminals of the SSH page: xterm.js, loaded on first use so the rest of
 * Studio stays light. Livewire owns connections and keys and hands out a
 * one-time ticket per terminal (open()); each terminal is then a WebSocket to
 * the SSH gateway, which speaks JSON both ways (see ssh-gateway/src/session.js).
 * On the SSH page a session can show the file manager of its host instead
 * (view 'files'): a Livewire component per connection, over SFTP (browse()).
 */
import { FONT, loadXterm, THEME } from './xterm';

/** Close codes of the gateway that say why without an error message reaching the page. */
const CLOSE_REASONS = {
    4401: 'The session ticket expired: reconnect.',
    4403: 'This page may not open SSH sessions.',
    4429: 'Too many SSH sessions open at once.',
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

function sshTerminals() {
    // xterm instances, sockets and observers stay out of Alpine's reactive proxies.
    const runtime = new Map();
    let next = 1;

    return {
        sessions: [],
        active: null,
        layout: 'tabs',
        filter: '',
        ctrl: false,

        init() {
            try { this.layout = localStorage.getItem('studio.ssh.layout') === 'grid' ? 'grid' : 'tabs'; } catch {}

            this.onUnload = (event) => {
                if (this.sessions.some((s) => s.status === 'ready')) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.onUnload);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.onUnload);
            for (const id of [...runtime.keys()]) this.dispose(id);
        },

        matches(text) {
            const q = this.filter.trim().toLowerCase();
            return q === '' || text.includes(q);
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

            const id = next++;
            const same = this.sessions.filter((s) => s.connectionId === connectionId).length;
            this.sessions.push({
                id,
                connectionId,
                title: same ? `${info.name} (${same + 1})` : info.name,
                address: info.address,
                status: 'connecting',
                error: null,
                fingerprint: null,
                view: 'terminal',
            });
            this.active = id;

            await this.$nextTick();
            const term = await this.mount(id);
            if (term) this.connect(id, info);
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

        connect(id, info) {
            const entry = runtime.get(id);
            const session = this.find(id);
            if (!entry || !session) return;

            const { cols, rows } = entry.term;
            const url = `${info.url}?ticket=${encodeURIComponent(info.ticket)}&cols=${cols}&rows=${rows}`;
            const ws = new WebSocket(url);
            entry.ws = ws;
            session.status = 'connecting';
            session.error = null;
            entry.term.write(`\x1b[90mConnecting to ${info.address}…\x1b[0m\r\n`);

            ws.onmessage = (event) => {
                let message;
                try { message = JSON.parse(event.data); } catch { return; }
                const s = this.find(id);
                if (!s) return;

                if (message.type === 'data') {
                    entry.term.write(message.data);
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
                if (!s || entry.ws !== ws) return;
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

            this.dispose(id);
            const index = this.sessions.findIndex((x) => x.id === id);
            if (index !== -1) this.sessions.splice(index, 1);
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
            try { entry.ws?.close(1000); } catch {}
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
