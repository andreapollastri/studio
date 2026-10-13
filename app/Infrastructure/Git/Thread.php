<?php

namespace App\Infrastructure\Git;

use Illuminate\Support\Carbon;

/**
 * An issue or a pull request as text to read in the result window: its
 * facts, its description and the conversation under it, oldest first.
 */
final class Thread
{
    /**
     * @param  array<string, mixed>  $facts  label => value; empty values are left out
     * @param  array<int, array{author?: mixed, at?: mixed, body?: mixed}>  $comments
     */
    public static function text(array $facts, mixed $body, array $comments = []): string
    {
        $lines = [];

        foreach ($facts as $label => $value) {
            $value = is_array($value) ? implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value))) : $value;

            if (is_bool($value)) {
                $value = $value ? __('yes') : __('no');
            }

            if ($value !== null && $value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        $text = implode("\n", $lines);
        $body = is_string($body) ? trim($body) : '';
        $text .= "\n\n".($body !== '' ? $body : __('(no description)'));

        foreach ($comments as $comment) {
            $said = is_string($comment['body'] ?? null) ? trim($comment['body']) : '';

            if ($said === '') {
                continue;
            }

            $author = is_scalar($comment['author'] ?? null) ? (string) $comment['author'] : '?';
            $text .= "\n\n──── ".$author.' · '.self::when($comment['at'] ?? null)."\n".$said;
        }

        return $text;
    }

    public static function when(mixed $at): string
    {
        if (! is_string($at) || $at === '') {
            return '';
        }

        try {
            return Carbon::parse($at)->timezone(config('app.timezone'))->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $at;
        }
    }

    /**
     * The changes of a pull request as one unified diff, file after file.
     *
     * @param  list<array{0: string, 1: string|null}>  $files  [name, patch]
     */
    public static function diff(array $files, int $limit = 200_000): string
    {
        $text = '';

        foreach ($files as [$name, $patch]) {
            $text .= '━━━━ '.$name."\n".(is_string($patch) && $patch !== '' ? rtrim($patch) : __('(binary, or too large to show)'))."\n\n";

            if (strlen($text) > $limit) {
                return substr($text, 0, $limit)."\n…".__('cut here: open it on the provider to read the rest.');
            }
        }

        return $text !== '' ? rtrim($text) : __('No changes.');
    }
}
