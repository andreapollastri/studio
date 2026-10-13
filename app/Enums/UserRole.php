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

    /**
     * The roles a person may have on a project; an administrator is one everywhere.
     *
     * @return list<self>
     */
    public static function projectRoles(): array
    {
        return [self::Pm, self::Dev, self::Client];
    }

    /** Can this role talk to the agent at all? */
    public function canChat(): bool
    {
        return $this !== self::Client;
    }

    /** The SSH terminal opens sessions from the server to any host: administrators and developers. */
    public function canUseSsh(): bool
    {
        return $this === self::Admin || $this === self::Dev;
    }

    public function permissionMode(): string
    {
        return (string) config("studio.permission_mode.{$this->value}", 'default');
    }
}
