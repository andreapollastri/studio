<?php

namespace App\Databases;

/**
 * The engines Tools → Databases speaks: four SQL ones through PDO (SQLite on another host
 * through its own sqlite3 over SSH), Redis and Valkey through RESP.
 */
enum Driver: string
{
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Pgsql = 'pgsql';
    case Sqlite = 'sqlite';
    case Redis = 'redis';
    case Valkey = 'valkey';

    public function label(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL',
            self::Mariadb => 'MariaDB',
            self::Pgsql => 'PostgreSQL',
            self::Sqlite => 'SQLite',
            self::Redis => 'Redis',
            self::Valkey => 'Valkey',
        };
    }

    /** mysql, pgsql, sqlite or redis: what the dialects, the dumps and the pages switch on. */
    public function family(): string
    {
        return match ($this) {
            self::Mysql, self::Mariadb => 'mysql',
            self::Pgsql => 'pgsql',
            self::Sqlite => 'sqlite',
            self::Redis, self::Valkey => 'redis',
        };
    }

    public function isSql(): bool
    {
        return $this->family() !== 'redis';
    }

    public function isRedis(): bool
    {
        return $this->family() === 'redis';
    }

    public function defaultPort(): ?int
    {
        return match ($this->family()) {
            'mysql' => 3306,
            'pgsql' => 5432,
            'redis' => 6379,
            default => null,
        };
    }

    /** Two letters on the connection's tile. */
    public function short(): string
    {
        return match ($this) {
            self::Mysql => 'My',
            self::Mariadb => 'Ma',
            self::Pgsql => 'Pg',
            self::Sqlite => 'Sq',
            self::Redis => 'Rd',
            self::Valkey => 'Vk',
        };
    }

    /** Tailwind classes of the tile. */
    public function tint(): string
    {
        return match ($this) {
            self::Mysql => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200',
            self::Mariadb => 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-200',
            self::Pgsql => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200',
            self::Sqlite => 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
            self::Redis => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
            self::Valkey => 'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200',
        };
    }
}
