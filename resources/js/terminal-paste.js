/**
 * Paste into a terminal from a phone. The clipboard is read when the browser
 * allows it; otherwise the touch bar opens a field the phone will paste into.
 * One message to the gateway is at most 1 MB, and JSON can escape a character
 * into six bytes, so the text stays well under that.
 */

export const PASTE_LIMIT = 100_000;

/** The text to type, or '' when there is nothing. Never cut through a character. */
export function clipPaste(text) {
    if (typeof text !== 'string' || text === '') return '';
    if (text.length <= PASTE_LIMIT) return text;

    let end = PASTE_LIMIT;
    const cut = text.charCodeAt(end - 1);
    if (cut >= 0xD800 && cut <= 0xDBFF) end -= 1;
    return text.slice(0, end);
}

/**
 * What the clipboard will give, or '' when it will not (permission denied, an
 * empty clipboard, a browser without the API). The caller opens the field then.
 * `clipboard` is injectable so a test can stand in for the phone.
 */
export async function readClipboard(clipboard = globalThis.navigator?.clipboard) {
    try {
        if (!clipboard?.readText) return '';
        const text = await clipboard.readText();
        return typeof text === 'string' ? text : '';
    } catch {
        return '';
    }
}

/**
 * How far the keyboard covers the page, in pixels. A phone that resizes the
 * layout when the keyboard opens reports no inset; one that lays the keyboard
 * over the page (iOS) reports the covered strip.
 */
export function keyboardInset(innerHeight = globalThis.innerHeight, viewport = globalThis.visualViewport) {
    if (!viewport || typeof innerHeight !== 'number' || typeof viewport.height !== 'number') return 0;
    const covered = innerHeight - viewport.height - (viewport.offsetTop || 0);
    return covered > 1 ? Math.round(covered) : 0;
}

/**
 * The paste sheet, over its key bar and above the keyboard. `bar` is the key
 * bar's rectangle (left, width, bottom) in the same coordinates as innerHeight.
 */
export function pasteSheetBox(bar, innerHeight, viewport) {
    const inset = keyboardInset(innerHeight, viewport);
    const aboveBar = Math.max(0, innerHeight - bar.bottom);
    return {
        left: Math.max(8, bar.left + 8),
        width: Math.max(160, bar.width - 16),
        bottom: Math.max(inset, aboveBar),
    };
}
