/**
 * The chat's live side: Claude's text as it is typed, and a refresh when
 * something is stored. The conversation's channel is read here, in the
 * browser: a text delta only grows the text on screen (it used to cost a
 * Livewire round trip that rendered the whole conversation, five times a
 * second), and the stored events ask Livewire for one refresh, folding those
 * that arrive while it runs into the next. Without a websocket, the page asks
 * every few seconds while the agent works.
 */
/** Reverb's socket is up: the conversation's news comes through it. */
const live = () => window.Echo?.connector?.pusher?.connection?.state === 'connected';

/**
 * With the socket up, a turn that went quiet this long while Studio says it works gets one
 * refresh anyway: a result the socket never brought (a bridge restart) would otherwise keep
 * "working…" for good, since only a refresh lets the server see the bridge is idle.
 */
const QUIET_MS = 45000;

function chatStream(uuid) {
    let channel = null;
    let timer = null;
    let refreshing = false;
    let again = false;
    let heard = Date.now();

    const handler = function (event) {
        heard = Date.now();
        const kind = event?.kind ?? '';
        if (kind === 'text_delta') {
            this.text += event.data?.text ?? '';
            this.$dispatch('chat-updated');
            return;
        }
        if (kind === 'status') return;
        // what was typed so far is now a stored message: it leaves once the refresh shows that
        if ((event.data?.kinds ?? []).some((k) => ['text', 'result', 'error'].includes(k))) this.cut = this.text.length;
        this.refresh();
    };

    return {
        shot: null,
        text: '',
        cut: 0,
        /** Websocket up: false means the chat falls back to polling. */
        live: live(),

        init() {
            // no websocket (or it dropped): ask every few seconds while something is under way;
            // with it, ask once in a while when the turn has gone quiet
            timer = setInterval(() => {
                this.live = live();
                if (this.$root.dataset.polling !== '1') {
                    heard = Date.now();
                    return;
                }
                if (!this.live || Date.now() - heard > QUIET_MS) {
                    heard = Date.now();
                    this.refresh();
                }
            }, 4000);
            if (!window.Echo) return;
            this.listener = handler.bind(this);
            channel = window.Echo.private(`conversations.${uuid}`);
            channel.listen('.ConversationUpdated', this.listener);

            const pusher = window.Echo.connector?.pusher?.connection;
            if (pusher?.bind) {
                const sync = () => {
                    this.live = live();
                };
                pusher.bind('state_change', sync);
                this._unsubLive = () => pusher.unbind('state_change', sync);
                sync();
            }
        },

        destroy() {
            clearInterval(timer);
            this._unsubLive?.();
            channel?.stopListening('.ConversationUpdated', this.listener);
        },

        async refresh() {
            if (refreshing) {
                again = true;
                return;
            }
            refreshing = true;
            try {
                do {
                    again = false;
                    const cut = this.cut;
                    await this.$wire.poll();
                    this.text = this.text.slice(cut);
                    this.cut = Math.max(0, this.cut - cut);
                } while (again);
            } finally {
                refreshing = false;
            }
        },
    };
}

const register = () => window.Alpine.data('chatStream', chatStream);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
