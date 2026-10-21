/**
 * Uploads and moves in the tree of the Files tab. Files and whole folders come
 * from a picker or are dropped from the computer; each keeps its place under
 * the folder it is dropped on, and they go in batches through Livewire's
 * uploads, the server streaming each one on to the workspace. A row of the
 * tree dragged onto a folder moves there.
 */
const ROW = 'application/x-studio-path';
// One batch per request: below PHP's post_max_size (520 MB on a Studio server) and few files at a time;
// a file larger than a batch goes alone.
const BATCH_BYTES = 200 * 1024 * 1024;
const BATCH_FILES = 50;

/** The files under the items of a drop, with their paths; stops after `limit` + 1. */
async function filesOf(items, limit) {
    // the entries must be taken before the first await: the DataTransfer empties after the event
    const roots = [...items].map((item) => item.webkitGetAsEntry?.()).filter(Boolean);
    const out = [];
    const walk = async (entry, prefix) => {
        if (out.length > limit) return;
        if (entry.isFile) {
            out.push({ file: await new Promise((resolve, reject) => entry.file(resolve, reject)), path: prefix + entry.name });
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            for (;;) {
                const children = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
                if (children.length === 0) break;
                for (const child of children) await walk(child, `${prefix}${entry.name}/`);
            }
        }
    };
    for (const root of roots) await walk(root, '');
    return out;
}

function batches(list) {
    const out = [];
    let current = [];
    let bytes = 0;
    for (const item of list) {
        if (current.length > 0 && (current.length >= BATCH_FILES || bytes + item.file.size > BATCH_BYTES)) {
            out.push(current);
            current = [];
            bytes = 0;
        }
        current.push(item);
        bytes += item.file.size;
    }
    if (current.length > 0) out.push(current);
    return out;
}

function studioFileDrop(maxFiles, maxMb) {
    return {
        over: null,
        busy: false,
        status: '',
        progress: 0,
        problem: '',

        /** The computer's file picker; a folder keeps its tree. */
        pick(dir, folder) {
            const input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            if (folder) input.webkitdirectory = true;
            input.addEventListener('change', () => this.upload(dir, [...input.files].map((file) => ({ file, path: file.webkitRelativePath || file.name }))));
            input.click();
        },

        grab(event, path) {
            event.dataTransfer.setData(ROW, path);
            event.dataTransfer.effectAllowed = 'move';
        },

        hover(event, dir) {
            this.over = dir;
            event.dataTransfer.dropEffect = event.dataTransfer.types.includes(ROW) ? 'move' : 'copy';
        },

        leave(event, dir) {
            if (this.over === dir && !event.currentTarget.contains(event.relatedTarget)) this.over = null;
        },

        async drop(event, dir) {
            this.over = null;
            const row = event.dataTransfer.getData(ROW);
            if (row) {
                await this.$wire.moveInto(row, dir);
                return;
            }
            if (event.dataTransfer.items?.length) {
                this.upload(dir, await filesOf(event.dataTransfer.items, maxFiles));
            }
        },

        async upload(dir, list) {
            if (this.busy || list.length === 0) return;
            this.problem = '';
            const tooBig = list.filter((item) => item.file.size > maxMb * 1024 * 1024);
            if (tooBig.length > 0) {
                this.problem = `Larger than ${maxMb} MB, not uploaded: ${tooBig.slice(0, 3).map((item) => item.path).join(', ')}${tooBig.length > 3 ? '…' : ''}`;
                list = list.filter((item) => !tooBig.includes(item));
                if (list.length === 0) return;
            }
            // the server says no to too many files, with the reason
            if (list.length > maxFiles) {
                await this.$wire.prepareUpload(dir, list.map((item) => item.path));
                return;
            }

            this.busy = true;
            let done = 0;
            try {
                for (const batch of batches(list)) {
                    this.status = `${done + batch.length} / ${list.length}`;
                    this.progress = 0;
                    if (!(await this.$wire.prepareUpload(dir, batch.map((item) => item.path)))) break;
                    await new Promise((resolve, reject) => this.$wire.uploadMultiple(
                        'uploads',
                        batch.map((item) => item.file),
                        resolve,
                        () => reject(new Error('The upload failed.')),
                        (event) => { this.progress = event.detail.progress; },
                    ));
                    done += batch.length;
                }
            } catch (error) {
                this.problem = error.message;
            } finally {
                this.busy = false;
            }
        },
    };
}

const register = () => window.Alpine.data('studioFileDrop', studioFileDrop);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
