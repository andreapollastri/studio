<?php

namespace App\Console\Concerns;

/**
 * The text a command was given. Laravel types every option and argument as
 * anything the console can parse (an array for the repeatable ones, a bool for
 * the flags); the ones these commands read take a single value, so they are
 * read as text: null when the option or argument was left out or empty.
 */
trait ReadsTextInput
{
    protected function textOption(string $key): ?string
    {
        return $this->asText($this->option($key));
    }

    protected function textArgument(string $key): ?string
    {
        return $this->asText($this->argument($key));
    }

    private function asText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
