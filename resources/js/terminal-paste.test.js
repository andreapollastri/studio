import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PASTE_LIMIT, clipPaste, keyboardInset, pasteSheetBox, readClipboard } from './terminal-paste.js';

test('spaces and a trailing newline are kept: they are part of the paste', () => {
    assert.equal(clipPaste('  ls\n'), '  ls\n');
    assert.equal(clipPaste(' '), ' ');
});

test('nothing to paste is empty, not a throw', () => {
    assert.equal(clipPaste(''), '');
    assert.equal(clipPaste(null), '');
    assert.equal(clipPaste(undefined), '');
});

test('a paste stops under the websocket limit and not in the middle of a character', () => {
    const head = 'a'.repeat(PASTE_LIMIT - 1);
    assert.equal(clipPaste(`${head}😀z`), head);
    assert.equal(clipPaste('x'.repeat(PASTE_LIMIT + 10)).length, PASTE_LIMIT);
});

test('the clipboard is read whole', async () => {
    const clipboard = { readText: async () => 'ssh root@web\n' };
    assert.equal(await readClipboard(clipboard), 'ssh root@web\n');
});

test('the sheet sits on the key bar, and above a keyboard that covers the page', () => {
    const full = { height: 800, offsetTop: 0 };
    assert.equal(keyboardInset(800, full), 0);
    assert.deepEqual(pasteSheetBox({ left: 0, width: 390, bottom: 800 }, 800, full), {
        left: 8, width: 374, bottom: 0,
    });

    const covered = { height: 500, offsetTop: 0 };
    assert.equal(keyboardInset(800, covered), 300);
    assert.equal(pasteSheetBox({ left: 0, width: 390, bottom: 800 }, 800, covered).bottom, 300);

    // a pane that is not the whole screen: the sheet stays in that pane, above its bar
    const pane = pasteSheetBox({ left: 420, width: 400, bottom: 700 }, 800, full);
    assert.deepEqual(pane, { left: 428, width: 384, bottom: 100 });
});

test('a phone that refuses the clipboard leaves the field to take the paste', async () => {
    assert.equal(await readClipboard(undefined), '');
    assert.equal(await readClipboard({}), '');
    assert.equal(await readClipboard({ readText: async () => { throw new Error('denied'); } }), '');
    assert.equal(await readClipboard({ readText: async () => '' }), '');
});
