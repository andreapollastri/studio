/**
 * The chat's input, as Claude Code's own: Enter sends (Shift+Enter is a new
 * line), and "/" at the start opens the skills and commands this conversation
 * can start, read once from the bridge through Livewire (Chat::skills). In the
 * list, Enter or a click starts the highlighted one at once, Tab writes it out
 * so a request can follow, Esc closes it.
 */
function chatComposer() {
    let all = null;

    return {
        open: false,
        loading: false,
        query: '',
        shown: [],
        index: 0,

        async typed(event) {
            const match = /^\/(\S*)$/.exec(event.target.value);
            if (!match) {
                this.open = false;
                return;
            }
            this.query = match[1].toLowerCase();
            this.open = true;
            if (all === null) {
                this.loading = true;
                try {
                    all = (await this.$wire.skills()) ?? [];
                } catch {
                    all = [];
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
            this.shown = (all ?? []).map((skill) => [rank(skill), skill]).filter(([r]) => r < 3).sort((a, b) => a[0] - b[0]).map(([, skill]) => skill);
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

        send() {
            // the button says whether a message can go now (the workspace runs, Claude is not busy)
            if (this.$root.querySelector('button[type=submit]')?.disabled) return;
            if (this.$refs.input.value.trim() === '') return;
            this.$wire.draft = this.$refs.input.value;
            this.open = false;
            this.$wire.send();
        },
    };
}

const register = () => window.Alpine.data('chatComposer', chatComposer);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
