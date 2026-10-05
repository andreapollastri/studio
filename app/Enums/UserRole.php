<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Pm = 'pm';
    case Dev = 'dev';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Pm => __('PM'),
            self::Dev => __('Developer'),
            self::Client => __('Client'),
        };
    }

    /** Can this role talk to the agent at all? */
    public function canChat(): bool
    {
        return $this !== self::Client;
    }

    public function permissionMode(): string
    {
        return (string) config("studio.permission_mode.{$this->value}", 'default');
    }
}
