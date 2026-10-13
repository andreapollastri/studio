<?php

namespace App\Databases;

/**
 * The tag on a connection, as TablePlus colours them: what kind of data is behind it. A
 * production connection opens with its changes locked until the person unlocks them, and
 * asks before every statement that writes.
 */
enum Environment: string
{
    case Local = 'local';
    case Development = 'development';
    case Testing = 'testing';
    case Staging = 'staging';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Local => __('Local'),
            self::Development => __('Development'),
            self::Testing => __('Testing'),
            self::Staging => __('Staging'),
            self::Production => __('Production'),
        };
    }

    /** A flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Local => 'zinc',
            self::Development => 'green',
            self::Testing => 'sky',
            self::Staging => 'amber',
            self::Production => 'red',
        };
    }

    /** The bar across the top of the client. */
    public function bar(): string
    {
        return match ($this) {
            self::Local => 'bg-zinc-400',
            self::Development => 'bg-green-500',
            self::Testing => 'bg-sky-500',
            self::Staging => 'bg-amber-500',
            self::Production => 'bg-red-600',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
