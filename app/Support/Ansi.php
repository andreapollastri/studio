<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Turns the raw output of a terminal run into HTML for the dark pane. The
 * bridge asks git, Composer, artisan and the test tools for colours; SGR codes
 * become styled spans, everything is escaped, cursor movement, line erasing
 * and OSC sequences are dropped, and a carriage return rewrites its line the
 * way a progress bar expects.
 */
final class Ansi
{
    private const TOKEN = '/(\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[()#][0-9A-Za-z]|\e[@-Z\\\\-_]|\r|\n|\x08)/';

    /** Foreground for codes 30–37 and 90–97: black is for text on a coloured background, bright black the grey of dot leaders. */
    private const FG = [
        '#27272a', '#f87171', '#4ade80', '#facc15', '#60a5fa', '#e879f9', '#22d3ee', '#e4e4e7',
        '#71717a', '#fca5a5', '#86efac', '#fde047', '#93c5fd', '#f0abfc', '#67e8f9', '#ffffff',
    ];

    /** Background for codes 40–47 and 100–107, dark enough for white text on top. */
    private const BG = [
        '#27272a', '#dc2626', '#16a34a', '#ca8a04', '#2563eb', '#c026d3', '#0891b2', '#d4d4d8',
        '#52525b', '#ef4444', '#22c55e', '#eab308', '#3b82f6', '#d946ef', '#06b6d4', '#f4f4f5',
    ];

    private const DEFAULT_FG = '#f4f4f5';

    private const DEFAULT_BG = '#09090b';

    /** @var array{bold: bool, dim: bool, italic: bool, underline: bool, inverse: bool, strike: bool, fg: ?string, bg: ?string} */
    private const RESET = ['bold' => false, 'dim' => false, 'italic' => false, 'underline' => false, 'inverse' => false, 'strike' => false, 'fg' => null, 'bg' => null];

    public static function toHtml(?string $text): HtmlString
    {
        $text = str_replace("\r\n", "\n", (string) $text);
        // A run still going may end halfway through a sequence; the next poll completes it.
        $text = (string) preg_replace('/\e(\[[0-?]*[ -\/]*|\][^\a\e]*)?$/', '', $text);

        $style = self::RESET;
        $html = '';
        /** @var list<array{0: array<string, mixed>, 1: string}> $line */
        $line = [];

        foreach (preg_split(self::TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === "\n") {
                $html .= self::render($line)."\n";
                $line = [];
            } elseif ($token === "\r") {
                $line = [];
            } elseif ($token === "\x08") {
                self::backspace($line);
            } elseif ($token[0] === "\e") {
                if (str_starts_with($token, "\e[") && str_ends_with($token, 'm')) {
                    $style = self::apply($style, substr($token, 2, -1));
                }
            } else {
                $plain = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $token);
                if ($plain !== '') {
                    $line[] = [$style, $plain];
                }
            }
        }

        return new HtmlString($html.self::render($line));
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function apply(array $style, string $params): array
    {
        $codes = $params === '' ? [0] : array_map('intval', preg_split('/[;:]/', $params) ?: [0]);

        for ($i = 0; $i < count($codes); $i++) {
            $code = $codes[$i];

            match (true) {
                $code === 0 => $style = self::RESET,
                $code === 1 => $style['bold'] = true,
                $code === 2 => $style['dim'] = true,
                $code === 3 => $style['italic'] = true,
                $code === 4 => $style['underline'] = true,
                $code === 7 => $style['inverse'] = true,
                $code === 9 => $style['strike'] = true,
                $code === 22 => [$style['bold'], $style['dim']] = [false, false],
                $code === 23 => $style['italic'] = false,
                $code === 24 => $style['underline'] = false,
                $code === 27 => $style['inverse'] = false,
                $code === 29 => $style['strike'] = false,
                $code >= 30 && $code <= 37 => $style['fg'] = self::FG[$code - 30],
                $code >= 90 && $code <= 97 => $style['fg'] = self::FG[$code - 82],
                $code === 39 => $style['fg'] = null,
                $code >= 40 && $code <= 47 => $style['bg'] = self::BG[$code - 40],
                $code >= 100 && $code <= 107 => $style['bg'] = self::BG[$code - 92],
                $code === 49 => $style['bg'] = null,
                default => null,
            };

            if ($code === 38 || $code === 48) {
                [$color, $used] = self::extended(array_slice($codes, $i + 1), $code === 48);
                $i += $used;
                if ($color !== null) {
                    $style[$code === 38 ? 'fg' : 'bg'] = $color;
                }
            }
        }

        return $style;
    }

    /**
     * The colour after a 38 or 48: `5;n` from the 256 palette or `2;r;g;b`.
     *
     * @param  list<int>  $rest
     * @return array{0: ?string, 1: int} the colour and how many codes it consumed
     */
    private static function extended(array $rest, bool $background): array
    {
        if (($rest[0] ?? null) === 5 && isset($rest[1])) {
            return [self::palette($rest[1], $background), 2];
        }

        if (($rest[0] ?? null) === 2 && isset($rest[3])) {
            return [sprintf('#%02x%02x%02x', min(255, $rest[1]), min(255, $rest[2]), min(255, $rest[3])), 4];
        }

        return [null, min(1, count($rest))];
    }

    private static function palette(int $n, bool $background): ?string
    {
        if ($n < 16) {
            return ($background ? self::BG : self::FG)[$n];
        }

        if ($n < 232) {
            $n -= 16;
            $level = fn (int $v) => $v === 0 ? 0 : 55 + $v * 40;

            return sprintf('#%02x%02x%02x', $level(intdiv($n, 36)), $level(intdiv($n % 36, 6)), $level($n % 6));
        }

        if ($n < 256) {
            $gray = 8 + ($n - 232) * 10;

            return sprintf('#%02x%02x%02x', $gray, $gray, $gray);
        }

        return null;
    }

    /** @param  list<array{0: array<string, mixed>, 1: string}>  $line */
    private static function backspace(array &$line): void
    {
        if ($line === []) {
            return;
        }

        $last = array_key_last($line);
        $line[$last][1] = mb_substr($line[$last][1], 0, -1);
        if ($line[$last][1] === '') {
            array_pop($line);
        }
    }

    /** @param  list<array{0: array<string, mixed>, 1: string}>  $line */
    private static function render(array $line): string
    {
        $html = '';
        $previous = null;
        $buffer = '';

        foreach ($line as [$style, $text]) {
            if ($style !== $previous && $buffer !== '') {
                $html .= self::span($previous, $buffer);
                $buffer = '';
            }
            $previous = $style;
            $buffer .= $text;
        }

        return $buffer === '' ? $html : $html.self::span($previous, $buffer);
    }

    /** @param  array<string, mixed>  $style */
    private static function span(array $style, string $text): string
    {
        $text = e($text);
        [$fg, $bg] = $style['inverse']
            ? [$style['bg'] ?? self::DEFAULT_BG, $style['fg'] ?? self::DEFAULT_FG]
            : [$style['fg'], $style['bg']];

        $css = array_filter([
            $fg !== null ? "color:{$fg}" : null,
            $bg !== null ? "background-color:{$bg}" : null,
            $style['bold'] ? 'font-weight:700' : null,
            $style['dim'] ? 'opacity:.6' : null,
            $style['italic'] ? 'font-style:italic' : null,
            $style['underline'] || $style['strike'] ? 'text-decoration:'.trim(($style['underline'] ? 'underline ' : '').($style['strike'] ? 'line-through' : '')) : null,
        ]);

        return $css === [] ? $text : '<span style="'.implode(';', $css).'">'.$text.'</span>';
    }
}
