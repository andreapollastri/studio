/**
 * The chat's input, as Claude Code's own: Enter sends (Shift+Enter is a new
 * line), focusing it starts the CLI for the next turn ahead of time, and "/" at the start opens the skills and commands this conversation
 * can start, read once from the bridge through Livewire (Chat::skills). In the
 * list, Enter or a click starts the highlighted one at once, Tab writes it out
 * so a request can follow, Esc closes it.
 *
 * While a turn runs, Enter queues the next message; ⌘/Ctrl+Enter interrupts
 * and sends it as soon as the agent is free (Chat::sendNow).
 */
function chatComposer() {
    let all = null;
    let warmed = false;

    return {
        open: false,
        loading: false,
        problem: '',
        found: [],
        query: '',
        shown: [],
        index: 0,

        /** About to write: Claude Code starts for the next turn now (Chat::warm), once per visit. */
        warm() {
            if (warmed) return;
            warmed = true;
            this.$wire.warm();
        },

        async typed(event) {
            this.warm();
            const match = /^\/(\S*)$/.exec(event.target.value);
            if (!match) {
                this.open = false;
                return;
            }
            this.query = match[1].toLowerCase();
            this.open = true;
            if (all === null && !this.loading) {
                this.loading = true;
                this.problem = '';
                try {
                    const answer = await this.$wire.skills();
                    this.problem = answer?.error ?? '';
                    // kept for this page only when there is something: a failure or an empty list asks again next time
                    const found = answer?.skills ?? [];
                    all = found.length > 0 ? found : null;
                    this.found = found;
                } catch (error) {
                    this.problem = error?.message || 'The skills could not be read.';
                    this.found = [];
                }
                this.loading = false;
            }
            this.filter();
        },

        filter() {
            const q = this.query;
            const rank = (skill) => {
                const name = skill.name.toLowerCase();
                if (name.startsWith(q)) return 0;
                if (name.includes(q)) return 1;
                return (skill.description ?? '').toLowerCase().includes(q) ? 2 : 3;
            };
            this.shown = (all ?? this.found ?? []).map((skill) => [rank(skill), skill]).filter(([r]) => r < 3).sort((a, b) => a[0] - b[0]).map(([, skill]) => skill);
            this.index = 0;
        },

        key(event) {
            if (event.isComposing) return;
            if (this.open && this.shown.length > 0) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    this.index = (this.index + step + this.shown.length) % this.shown.length;
                    this.$refs.menu?.querySelectorAll('[data-skill]')[this.index]?.scrollIntoView({ block: 'nearest' });
                    return;
                }
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    this.run(this.shown[this.index]);
                    return;
                }
                if (event.key === 'Tab') {
                    event.preventDefault();
                    this.write(`/${this.shown[this.index].name} `);
                    return;
                }
            }
            if (this.open && event.key === 'Escape') {
                event.preventDefault();
                this.open = false;
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey && !event.altKey) {
                event.preventDefault();
                // ⌘/Ctrl+Enter while busy: interrupt and send now
                if ((event.metaKey || event.ctrlKey) && this.$root.dataset.busy === '1') {
                    this.sendNow();
                    return;
                }
                this.send();
            }
        },

        /** The skill on its own, started now. */
        run(skill) {
            this.write(`/${skill.name}`);
            this.send();
        },

        write(text) {
            const input = this.$refs.input;
            input.value = text;
            this.$wire.draft = text;
            this.open = false;
            input.focus();
            input.setSelectionRange(text.length, text.length);
        },

        async send() {
            const input = this.$refs.input;
            const text = input?.value ?? '';
            if (text.trim() === '') return;
            // workspace stopped or conversation closed: the submit button says so
            if (this.$root.querySelector('button[type=submit]')?.disabled) return;

            this.$wire.draft = text;
            this.open = false;
            warmed = false;

            // clear at once so a poll cannot restore the old draft over what was just sent
            input.value = '';
            try {
                await this.$wire.send();
            } catch {
                input.value = text;
                this.$wire.draft = text;
            }
        },

        async sendNow() {
            const input = this.$refs.input;
            const text = input?.value ?? '';
            if (text.trim() === '') return;

            this.$wire.draft = text;
            this.open = false;
            warmed = false;
            input.value = '';
            try {
                await this.$wire.sendNow();
            } catch {
                input.value = text;
                this.$wire.draft = text;
            }
        },
    };
}

const register = () => window.Alpine.data('chatComposer', chatComposer);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
