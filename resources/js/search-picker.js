/**
 * <x-search-picker>: a search field whose list comes from a Livewire method
 * as you type, and that hands the chosen id to another Livewire method.
 */
function searchPicker({ search, select, selected = null, keep = true }) {
    return {
        query: selected?.label ?? '',
        selected,
        results: [],
        open: false,
        loading: false,
        highlight: 0,
        seq: 0,

        openList() {
            this.open = true;
            if (this.selected && this.query === this.selected.label) this.query = '';
            this.fetch();
        },

        async fetch() {
            const seq = ++this.seq;
            this.loading = true;
            const results = await this.$wire[search](this.query);
            if (seq !== this.seq) return;
            this.results = Array.isArray(results) ? results : [];
            this.highlight = 0;
            this.loading = false;
        },

        move(step) {
            if (!this.open) return this.openList();
            const count = this.results.length;
            if (count) this.highlight = (this.highlight + step + count) % count;
        },

        pick(result) {
            if (!result) return;
            this.$wire[select](result.id);
            this.selected = keep ? result : null;
            this.query = keep ? result.label : '';
            this.open = false;
            this.results = [];
        },

        clear() {
            this.$wire[select](null);
            this.selected = null;
            this.query = '';
        },

        close() {
            this.open = false;
            if (this.selected) this.query = this.selected.label;
        },
    };
}

const register = () => window.Alpine.data('searchPicker', searchPicker);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
