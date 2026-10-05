/**
 * The editor behind the Files tab: Monaco, the editor of VS Code, loaded on
 * first use so the rest of Studio stays light. Livewire owns the tree and the
 * disk; this Alpine component owns the open tabs and their unsaved text.
 */
const LANGUAGES = {
    php: 'php', js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript', ts: 'typescript', tsx: 'typescript',
    json: 'json', lock: 'json', css: 'css', scss: 'scss', less: 'less', html: 'html', htm: 'html', vue: 'html', xml: 'xml', svg: 'xml',
    md: 'markdown', yml: 'yaml', yaml: 'yaml', sh: 'shell', bash: 'shell', sql: 'sql', env: 'ini', ini: 'ini', toml: 'ini', txt: 'plaintext',
    py: 'python', rb: 'ruby', go: 'go', rs: 'rust', java: 'java', kt: 'kotlin', swift: 'swift', c: 'c', h: 'c', cpp: 'cpp', cs: 'csharp', graphql: 'graphql',
};

export function languageFor(path) {
    const name = String(path).split('/').pop().toLowerCase();
    if (name === 'dockerfile') return 'dockerfile';
    if (name.startsWith('.env')) return 'ini';
    if (name.endsWith('.blade.php')) return 'php';
    const ext = name.includes('.') ? name.split('.').pop() : '';
    return LANGUAGES[ext] ?? 'plaintext';
}

let monacoPromise = null;

/** Monaco 0.57 finds its workers itself through import.meta.url, which Vite bundles. */
function loadMonaco() {
    if (!monacoPromise) monacoPromise = import('monaco-editor');
    return monacoPromise;
}

const isDark = () => document.documentElement.classList.contains('dark');

/** UTF-8 text as base64, so the content reaches the server untouched by any input trimming. */
function toBase64(text) {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return btoa(binary);
}

function studioEditor() {
    // Monaco objects stay out of Alpine's reactive proxy: only tabs, active, saving and message are reactive.
    let monaco = null;
    let editor = null;
    const models = new Map();

    return {
        tabs: [],
        active: null,
        tree: true,
        saving: false,
        message: '',

        async init() {
            monaco = await loadMonaco();
            editor = monaco.editor.create(this.$refs.editor, {
                automaticLayout: true,
                theme: isDark() ? 'vs-dark' : 'vs',
                fontSize: 13,
                minimap: { enabled: false },
                scrollBeyondLastLine: false,
                tabSize: 4,
                renderWhitespace: 'selection',
            });
            editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => this.save());
            editor.onDidChangeModelContent(() => {
                const tab = this.current();
                if (tab && !tab.dirty) tab.dirty = true;
            });
            new MutationObserver(() => monaco.editor.setTheme(isDark() ? 'vs-dark' : 'vs')).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            window.addEventListener('beforeunload', (event) => {
                if (this.tabs.some((tab) => tab.dirty)) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            if (this.active && models.has(this.active)) this.show(this.active);
        },

        current() {
            return this.tabs.find((tab) => tab.path === this.active) ?? null;
        },

        async openFile({ path, content, hash }) {
            await loadMonaco();
            let tab = this.tabs.find((candidate) => candidate.path === path);
            if (!tab) {
                models.set(path, monaco.editor.createModel(content, languageFor(path), monaco.Uri.file('/' + path)));
                tab = { path, hash, dirty: false };
                this.tabs.push(tab);
            } else if (!tab.dirty && tab.hash !== hash) {
                models.get(path).setValue(content);
                tab.hash = hash;
            }
            this.show(path);
        },

        show(path) {
            this.active = path;
            this.message = '';
            if (editor && models.has(path)) {
                editor.setModel(models.get(path));
                editor.focus();
            }
        },

        close(path) {
            const tab = this.tabs.find((candidate) => candidate.path === path);
            if (!tab) return;
            if (tab.dirty && !window.confirm(`Discard the changes to ${path}?`)) return;
            this.drop(path);
        },

        drop(path) {
            models.get(path)?.dispose();
            models.delete(path);
            this.tabs = this.tabs.filter((tab) => tab.path !== path);
            if (this.active !== path) return;
            const next = this.tabs[this.tabs.length - 1];
            if (next) {
                this.show(next.path);
            } else {
                this.active = null;
                editor?.setModel(null);
            }
        },

        async save() {
            const tab = this.current();
            if (!tab || this.saving || !models.has(tab.path)) return;
            this.saving = true;
            this.message = '';
            try {
                const result = await this.$wire.save(tab.path, toBase64(models.get(tab.path).getValue()), tab.hash);
                if (result && result.ok) {
                    tab.hash = result.hash;
                    tab.dirty = false;
                    this.message = 'Saved';
                    setTimeout(() => { if (this.message === 'Saved') this.message = ''; }, 2000);
                } else {
                    this.message = (result && result.error) || 'Could not save.';
                }
            } catch (error) {
                this.message = (error && error.message) || 'Could not save.';
            } finally {
                this.saving = false;
            }
        },

        renamed({ oldPath: from, newPath: to }) {
            for (const tab of [...this.tabs]) {
                if (tab.path !== from && !tab.path.startsWith(from + '/')) continue;
                const path = to + tab.path.slice(from.length);
                const old = models.get(tab.path);
                const fresh = monaco.editor.createModel(old.getValue(), languageFor(path), monaco.Uri.file('/' + path));
                models.delete(tab.path);
                models.set(path, fresh);
                const wasActive = this.active === tab.path;
                tab.path = path;
                if (wasActive) {
                    this.active = path;
                    editor.setModel(fresh);
                }
                old.dispose();
            }
        },

        removed({ path }) {
            for (const tab of [...this.tabs]) {
                if (tab.path === path || tab.path.startsWith(path + '/')) this.drop(tab.path);
            }
        },
    };
}

const register = () => window.Alpine.data('studioEditor', studioEditor);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
