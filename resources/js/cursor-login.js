/**
 * "Sign in to Cursor" on Settings → Accounts: the official `agent login` of
 * the unmodified Cursor CLI, running as the person in their own home on the
 * server, shown in a terminal. The person opens Cursor's sign-in page and
 * approves there; the CLI keeps the login in the folder of that subscription
 * (~/.cursor, ~/.cursor-<slug>). Studio is the terminal and nothing more: no
 * field of its own takes a code, nothing of the exchange is kept.
 */
import { FONT, loadXterm, THEME } from './xterm';

const LINK = /https:\/\/[^\s"'<>\x1b\x07]*cursor\.com\/[^\s"'<>\x1b\x07]+/;
const ESCAPES = /\x1b\[[0-?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)|\x1b[@-Z\\-_]/g;

function cursorLogin() {
    let term = null;
    let fit = null;
    let ws = null;
    let screen = '';
    let watchdog = null;
    let runs = 0;

    return {
        state: 'idle',
        step: null,
        boxId: 'cursor-login-terminal',
        error: null,
        link: null,
        exitCode: null,
        account: null,

        async start(account = undefined) {
            if (account !== undefined) this.account = account;
            this.reset();
            this.state = 'connecting';
            this.step = 'ticket';
            const run = ++runs;
            watchdog = setTimeout(() => {
                if (run !== runs || this.state !== 'connecting') return;
                this.fail({
                    ticket: 'Studio did not answer: reload the page and try again.',
                    terminal: 'The terminal could not be loaded: reload the page and try again.',
                    socket: 'The sign-in service did not answer. Is the studio-ssh service running on the server?',
                    cursor: 'Cursor did not start on the server. Check that it is installed (Administration → System).',
                }[this.step] ?? 'The sign-in did not start.');
            }, 20000);

            try {
                const info = await this.$wire.cursorLoginTicket(this.account);
                if (run !== runs) return;
                if (!info?.ticket) {
                    this.state = 'idle';
                    this.$flux?.modal('cursor-login').close();
                    return;
                }

                this.step = 'terminal';
                const { Terminal, FitAddon, WebLinksAddon } = await loadXterm();
                const box = document.getElementById(this.boxId);
                if (!box) throw new Error('terminal box missing');
                box.replaceChildren();
                term = new Terminal({ cursorBlink: true, fontFamily: FONT, fontSize: 13, lineHeight: 1.15, scrollback: 2000, theme: THEME });
                fit = new FitAddon();
                term.loadAddon(fit);
                term.loadAddon(new WebLinksAddon((event, uri) => window.open(uri, '_blank', 'noopener')));
                term.parser.registerOscHandler(52, (data) => {
                    const encoded = data.split(';').pop();
                    try {
                        const text = new TextDecoder().decode(Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0)));
                        const found = text.match(LINK);
                        if (found) this.link = found[0];
                    } catch {}
                    return true;
                });
                term.open(box);
                await new Promise((resolve) => requestAnimationFrame(() => resolve()));
                try { fit.fit(); } catch {}
                term.onData((data) => this.send(data));

                this.step = 'socket';
                const url = `${info.url}?ticket=${encodeURIComponent(info.ticket)}&cols=${term.cols}&rows=${term.rows}`;
                ws = new WebSocket(url);
                ws.onopen = () => { if (run === runs) this.step = 'cursor'; };
                ws.onmessage = (event) => {
                    let message;
                    try { message = JSON.parse(event.data); } catch { return; }

                    if (message.type === 'data') {
                        term.write(message.data);
                        this.spotLink(message.data);
                        if (this.state === 'connecting') this.state = 'running';
                    } else if (message.type === 'status') {
                        this.state = 'running';
                        clearTimeout(watchdog);
                        term.focus();
                    } else if (message.type === 'error') {
                        this.fail(message.message);
                    } else if (message.type === 'exit') {
                        this.exitCode = message.code;
                        if (message.code === 0) {
                            this.state = 'done';
                        } else {
                            this.fail(this.error || 'The sign-in did not complete.');
                        }
                        this.$wire.refreshCursor(this.account);
                    }
                };
                ws.onerror = () => {};
                ws.onclose = (event) => {
                    if (run !== runs) return;
                    if (this.state === 'connecting' || this.state === 'running') {
                        this.fail(this.error || (event.code === 1006
                            ? 'The sign-in service cannot be reached. Is the studio-ssh service running on the server?'
                            : (event.reason || 'The sign-in stopped.')));
                    }
                };
            } catch (error) {
                console.error('[cursor-login]', error);
                if (run === runs) this.fail('The sign-in could not start: ' + (error?.message ?? error));
            }
        },

        fail(message) {
            clearTimeout(watchdog);
            this.state = 'error';
            this.error = message;
        },

        spotLink(chunk) {
            screen = (screen + chunk).slice(-8000);
            if (this.link) return;
            const found = screen.replace(ESCAPES, '').match(LINK);
            if (found && found[0].length > 40) this.link = found[0];
        },

        focusTerminal() {
            term?.focus();
        },

        send(data) {
            if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'data', data }));
        },

        reset() {
            clearTimeout(watchdog);
            try { ws?.close(1000); } catch {}
            try { term?.dispose(); } catch {}
            ws = null; term = null; fit = null; screen = '';
            this.error = null; this.link = null; this.exitCode = null;
        },

        stop() {
            runs++;
            this.reset();
            if (this.state !== 'done') this.state = 'idle';
        },

        destroy() {
            this.stop();
        },
    };
}

const register = () => window.Alpine.data('cursorLogin', cursorLogin);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
