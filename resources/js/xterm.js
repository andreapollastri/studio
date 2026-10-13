/**
 * xterm.js for the pages that show a terminal (SSH, Sign in with Claude),
 * loaded on first use so the rest of Studio stays light.
 */
let xtermPromise = null;

export function loadXterm() {
    if (!xtermPromise) {
        xtermPromise = Promise.all([
            import('@xterm/xterm'),
            import('@xterm/addon-fit'),
            import('@xterm/addon-web-links'),
            import('@xterm/xterm/css/xterm.css'),
        ]).then(([xterm, fit, links]) => ({ Terminal: xterm.Terminal, FitAddon: fit.FitAddon, WebLinksAddon: links.WebLinksAddon }));
    }
    return xtermPromise;
}

export const THEME = {
    background: '#09090b',
    foreground: '#f4f4f5',
    cursor: '#f4f4f5',
    cursorAccent: '#09090b',
    selectionBackground: '#3f3f46',
    black: '#27272a', red: '#f87171', green: '#4ade80', yellow: '#facc15', blue: '#60a5fa', magenta: '#e879f9', cyan: '#22d3ee', white: '#e4e4e7',
    brightBlack: '#71717a', brightRed: '#fca5a5', brightGreen: '#86efac', brightYellow: '#fde047', brightBlue: '#93c5fd', brightMagenta: '#f0abfc', brightCyan: '#67e8f9', brightWhite: '#ffffff',
};

export const FONT = "ui-monospace, 'SF Mono', 'JetBrains Mono', Menlo, Consolas, 'Liberation Mono', monospace";
