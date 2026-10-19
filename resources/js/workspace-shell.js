/**
 * The Terminal tab's shell: the workspace user's own shell, run by the
 * workspace's bridge on a real terminal and carried by the SSH gateway's
 * WebSocket (ssh-gateway/src/workspace-shell.js). Prompts, passwords, colours,
 * Ctrl-C and full-screen programs work as over SSH. The pane keeps the panel
 * mounted, hidden, so the session survives switching tabs; a new shell starts
 * when the old one ends.
 */
import { controlOf, KEYS } from './ssh';
import { FONT, loadXterm, THEME } from './xterm';

const CLOSE_REASONS = {
    4401: 'The terminal ticket expired: start a new shell.',
    4403: 'This page may not open terminals.',
    4429: 'Too many terminals open at once.',
    4502: 'Studio could not be reached by the terminal service.',
    1006: 'The terminal service cannot be reached. Is the studio-ssh service running?',
};

function workspaceShell() {
    // xterm and the socket stay out of Alpine's reactive proxy
    let term = null;
    let fit = null;
    let ws = null;

    return {
        state: 'idle', // idle · connecting · ready · ended · stopped · error
        error: '',
        exitCode: null,
        ctrl: false,
        queued: [],

        async init() {
            const { Terminal, FitAddon, WebLinksAddon } = await loadXterm();
            term = new Terminal({ cursorBlink: true, fontFamily: FONT, fontSize: 13, lineHeight: 1.15, scrollback: 10000, theme: THEME });
            fit = new FitAddon();
            term.loadAddon(fit);
            term.loadAddon(new WebLinksAddon((event, uri) => window.open(uri, '_blank', 'noopener')));
            term.open(this.$refs.box);
            // A hidden pane has no size: fit when it shows again, and tell the shell.
            new ResizeObserver(() => {
                if (this.$refs.box.offsetWidth > 0 && this.$refs.box.offsetHeight > 0) fit.fit();
            }).observe(this.$refs.box);
            term.onData((data) => {
                if (this.ctrl && data.length === 1) {
                    data = controlOf(data);
                    this.ctrl = false;
                }
                this.send({ type: 'data', data });
            });
            term.onResize(({ cols, rows }) => this.send({ type: 'resize', cols, rows }));
            this.connect();
        },

        async connect() {
            if (this.state === 'connecting' || this.state === 'ready') return;
            this.state = 'connecting';
            this.error = '';
            this.exitCode = null;
            try { fit.fit(); } catch {}

            const info = await this.$wire.shellTicket();
            if (!info?.ticket) {
                this.state = 'stopped';
                return;
            }

            ws = new WebSocket(`${info.url}?ticket=${encodeURIComponent(info.ticket)}&cols=${term.cols}&rows=${term.rows}`);
            ws.onmessage = (event) => {
                let message;
                try { message = JSON.parse(event.data); } catch { return; }
                if (message.type === 'data') {
                    term.write(message.data);
                } else if (message.type === 'status' && message.state === 'ready') {
                    this.state = 'ready';
                    try { fit.fit(); } catch {}
                    this.send({ type: 'resize', cols: term.cols, rows: term.rows });
                    for (const data of this.queued.splice(0)) this.send({ type: 'data', data });
                    term.focus();
                } else if (message.type === 'exit') {
                    this.exitCode = message.code;
                    this.state = 'ended';
                } else if (message.type === 'error') {
                    this.error = message.message;
                    this.state = 'error';
                }
            };
            ws.onclose = (event) => {
                ws = null;
                if (this.state === 'connecting' || this.state === 'ready') {
                    this.error = this.error || CLOSE_REASONS[event.code] || event.reason || 'The terminal closed.';
                    this.state = 'error';
                }
            };
        },

        /** A new shell after the old one ended: the screen starts over. */
        restart() {
            term?.reset();
            this.connect();
        },

        send(message) {
            if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify(message));
        },

        /** A shortcut: typed into the shell and run, as if typed by hand (a new shell first, when there is none). */
        type(command) {
            const data = `${command}\r`;
            if (this.state === 'ready') {
                this.send({ type: 'data', data });
                term.focus();
            } else {
                this.queued.push(data);
                if (this.state !== 'connecting') this.restart();
            }
        },

        key(name) {
            if (name === 'ctrl') {
                this.ctrl = !this.ctrl;
            } else if (KEYS[name] !== undefined) {
                this.send({ type: 'data', data: KEYS[name] });
                this.ctrl = false;
            }
        },
    };
}

const register = () => window.Alpine.data('workspaceShell', workspaceShell);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
