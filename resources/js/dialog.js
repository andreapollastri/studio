/**
 * Studio's own confirm and prompt, in place of the browser's: a modal that looks like the rest of the
 * app (resources/views/partials/dialog.blade.php), awaited as a promise.
 *
 *   if (await studioDialog.confirm({ message: 'Delete the rows?', confirm: 'Delete', danger: true })) …
 *   const name = await studioDialog.prompt({ title: 'New name', value: old });   // null when cancelled
 *
 * Every wire:confirm goes through it too: Livewire calls el.__livewire_confirm(action, instead) when the
 * element's action fires, and the hook below replaces Livewire's (window.confirm) with this modal. The
 * message's question is the title and the rest the text; the trigger's own label names the button (else
 * the question's verb), and a red trigger (or a message about deleting) makes it a red one.
 */
const DESTRUCTIVE = /\b(delete|remove|forget|discard|wipe|destroy|drop|truncate|revoke|cannot be undone)\b/i;

function store() {
    return window.Alpine.store('dialog');
}

/** "Delete X? This cannot be undone." → title "Delete X?", body "This cannot be undone." */
function split(message) {
    const text = String(message ?? '').trim();
    const end = text.indexOf('?');
    if (end === -1 || end === text.length - 1) return { title: text, body: '' };
    return { title: text.slice(0, end + 1), body: text.slice(end + 1).trim() };
}

/** The label of the button or menu item that asked, when it is a short one. */
function labelOf(trigger) {
    if (!trigger) return '';
    const text = (trigger.innerText ?? '').replace(/\s+/g, ' ').trim();
    const label = text || trigger.getAttribute('aria-label') || trigger.getAttribute('title') || '';
    return label.length > 0 && label.length <= 32 ? label : '';
}

/** "Remove globex.test?" → "Remove": the verb of the question, when it is one a button can say. */
const VERBS = /^(Sign out|Go back|Take it off|Remove|Delete|Stop|Forget|Deploy|Dump|Write|Update|Force|Accept|Commit|Clear|Unlock|Discard|Drop|Close|Restore|Make|Rename|Run|Merge|Reset|Restart|Revoke|Archive|Publish|Push|Pull|Revert|Abort)\b/;

function verbOf(question) {
    const found = VERBS.exec(String(question ?? '').trim());
    return found ? found[1] : '';
}

function looksRed(trigger) {
    return Boolean(trigger) && /(^|\s)(bg-red-|data-active:text-red-|text-red-)/.test(trigger.className ?? '');
}

function open(options) {
    const dialog = store();
    // one at a time: a second ask settles the first as cancelled
    dialog.settle(null);
    return new Promise((resolve) => {
        Object.assign(dialog, { ...options, resolve, shown: true });
    });
}

window.studioDialog = {
    /** @returns {Promise<boolean>} */
    async confirm({ message = '', title = null, body = null, confirm = null, cancel = null, danger = null, trigger = null } = {}) {
        const parts = split(message);
        const red = danger ?? (looksRed(trigger) || DESTRUCTIVE.test(message || title || ''));
        const answer = await open({
            kind: 'confirm',
            title: title ?? parts.title ?? '',
            body: body ?? parts.body ?? '',
            confirm: confirm ?? (labelOf(trigger) || verbOf(title ?? parts.title) || 'Confirm'),
            cancel: cancel ?? 'Cancel',
            danger: red,
            value: '',
            label: '',
            placeholder: '',
        });
        return answer === true;
    },

    /** @returns {Promise<string|null>} what was typed, or null when cancelled */
    async prompt({ title = '', body = '', label = '', value = '', placeholder = '', confirm = 'Save', cancel = 'Cancel' } = {}) {
        const answer = await open({ kind: 'prompt', title, body, label, value: value ?? '', placeholder, confirm, cancel, danger: false });
        return typeof answer === 'string' ? answer : null;
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.store('dialog', {
        shown: false,
        kind: 'confirm',
        title: '',
        body: '',
        label: '',
        placeholder: '',
        value: '',
        confirm: 'Confirm',
        cancel: 'Cancel',
        danger: false,
        resolve: null,

        accept() {
            this.settle(this.kind === 'prompt' ? this.value : true);
        },

        dismiss() {
            this.settle(this.kind === 'prompt' ? null : false);
        },

        settle(answer) {
            const resolve = this.resolve;
            this.resolve = null;
            this.shown = false;
            if (resolve) resolve(answer);
        },
    });
});

// wire:confirm="…" opens the modal instead of the browser's confirm (wire:confirm.prompt keeps Livewire's)
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) return;
        const message = (directive.expression || 'Are you sure?').replaceAll('\\n', '\n');
        el.__livewire_confirm = (action) => {
            window.studioDialog.confirm({ message, trigger: el }).then((ok) => {
                if (ok) action();
            });
        };
    });
});
