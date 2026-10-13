<?php

namespace App\Databases;

use App\Models\DatabaseConnection;
use InvalidArgumentException;

/**
 * Connections as links, the way TablePlus writes and reads them, or a Laravel .env:
 *
 *   mysql://user:password@host:3306/database
 *   mariadb+ssh://ssh_user:ssh_password@ssh_host:22/user:password@127.0.0.1:3306/database
 *   postgres://user:password@host/database?sslmode=require
 *   sqlite+ssh://deploy@web1/~/app/database/database.sqlite   (or //var/www/app.sqlite)
 *   redis://:password@host:6379/0   rediss://… (TLS)   valkey+ssh://deploy@web1/:password@127.0.0.1/0
 *
 * with ?name=…&env=production&tls=true. Passwords with / @ ? # : in them are written
 * percent-encoded; an @ in a password is still read right as the last one before the host.
 *
 *   DB_CONNECTION=pgsql  DB_HOST=…  DB_PORT=…  DB_DATABASE=…  DB_USERNAME=…  DB_PASSWORD=…
 *   REDIS_HOST=…  REDIS_PORT=…  REDIS_PASSWORD=…  REDIS_DB=…  (or DB_URL / REDIS_URL)
 */
final class ConnectionString
{
    private const SCHEMES = [
        'mysql' => ['mysql', false], 'mariadb' => ['mariadb', false],
        'postgres' => ['pgsql', false], 'postgresql' => ['pgsql', false], 'pgsql' => ['pgsql', false], 'psql' => ['pgsql', false],
        'sqlite' => ['sqlite', false], 'sqlite3' => ['sqlite', false],
        'redis' => ['redis', false], 'rediss' => ['redis', true], 'valkey' => ['valkey', false], 'valkeys' => ['valkey', true],
    ];

    /**
     * @return array{driver: string, host: string|null, port: int|null, username: string|null, password: string|null, database: string|null, tls: bool, name: string|null, environment: string|null, ssh: array{host: string, port: int, username: string, password: string|null}|null}
     *
     * @throws InvalidArgumentException when it is neither a link nor a .env
     */
    public static function parse(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^[A-Z_]+\s*=/m', $text) && ! preg_match('~^[a-z0-9]+(\+ssh)?://~i', $text)) {
            return self::env($text);
        }

        if (! preg_match('~^([a-z0-9]+)(\+ssh)?://(.*)$~is', $text, $m)) {
            throw new InvalidArgumentException(__('Paste a link like mysql://user:password@host/database, or the DB_ lines of a .env.'));
        }

        $scheme = strtolower($m[1]);
        if (! isset(self::SCHEMES[$scheme])) {
            throw new InvalidArgumentException(__('Unknown kind of database: :scheme.', ['scheme' => $scheme]));
        }

        [$driver, $tls] = self::SCHEMES[$scheme];
        $rest = $m[3];
        [$rest, $query] = self::splitQuery($rest);
        $ssh = null;

        if ($m[2] !== '') {
            $firstAt = strpos($rest, '@');
            $firstSlash = strpos($rest, '/');
            $cut = $firstAt !== false && ($firstSlash === false || $firstAt < $firstSlash) ? strpos($rest, '/', $firstAt) : $firstSlash;
            $authority = $cut === false ? $rest : substr($rest, 0, $cut);
            $rest = $cut === false ? '' : substr($rest, $cut + 1);
            $parts = self::authority($authority);

            if ($parts['host'] === '' || $parts['user'] === null) {
                throw new InvalidArgumentException(__('The SSH part needs a user and a host: ssh_user@ssh_host.'));
            }

            $ssh = ['host' => $parts['host'], 'port' => $parts['port'] ?? 22, 'username' => $parts['user'], 'password' => $parts['password']];
        }

        if ($driver === 'sqlite') {
            $path = rawurldecode($rest);
            if ($ssh === null) {
                // sqlite:///var/db.sqlite: what follows the empty host is the path
                $path = str_starts_with($path, '/') || str_starts_with($path, '~') ? $path : '/'.$path;
            } elseif (! str_starts_with($path, '/') && ! str_starts_with($path, '~')) {
                $path = '/'.$path;
            }

            if (trim($path, '/') === '') {
                throw new InvalidArgumentException(__('The link has no path to the SQLite file.'));
            }

            return self::result($driver, null, null, null, null, $path, false, $query, $ssh);
        }

        $lastAt = strrpos($rest, '@');
        $userinfo = $lastAt === false ? null : substr($rest, 0, $lastAt);
        $location = $lastAt === false ? $rest : substr($rest, $lastAt + 1);
        $slash = strpos($location, '/');
        $hostPort = $slash === false ? $location : substr($location, 0, $slash);
        $database = $slash === false ? null : rawurldecode(trim(substr($location, $slash + 1), '/'));
        $parts = self::authority(($userinfo !== null ? $userinfo.'@' : '').$hostPort);

        if ($parts['host'] === '' && $ssh === null) {
            throw new InvalidArgumentException(__('The link has no host.'));
        }

        $tls = $tls || in_array(strtolower((string) ($query['tls'] ?? $query['ssl'] ?? $query['sslmode'] ?? $query['tlsmode'] ?? '')), ['1', 'true', 'yes', 'require', 'required', 'verify-ca', 'verify-full', 'verify_identity', 'preferred'], true);
        $database = $query['database'] ?? $query['db'] ?? ($database === '' ? null : $database);

        return self::result($driver, $parts['host'] !== '' ? $parts['host'] : '127.0.0.1', $parts['port'], $parts['user'], $parts['password'], $database, $tls, $query, $ssh);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array{host: string, port: int, username: string, password: string|null}|null  $ssh
     * @return array{driver: string, host: string|null, port: int|null, username: string|null, password: string|null, database: string|null, tls: bool, name: string|null, environment: string|null, ssh: array{host: string, port: int, username: string, password: string|null}|null}
     */
    private static function result(string $driver, ?string $host, ?int $port, ?string $user, ?string $password, ?string $database, bool $tls, array $query, ?array $ssh): array
    {
        $env = strtolower((string) ($query['env'] ?? $query['environment'] ?? ''));
        $environment = match ($env) {
            'local' => 'local',
            'dev', 'development' => 'development',
            'test', 'testing' => 'testing',
            'staging', 'stage' => 'staging',
            'prod', 'production', 'live' => 'production',
            default => null,
        };

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'username' => $user === '' ? null : $user,
            'password' => $password === '' ? null : $password,
            'database' => $database === '' ? null : $database,
            'tls' => $tls,
            'name' => isset($query['name']) && trim($query['name']) !== '' ? mb_substr(trim($query['name']), 0, 60) : null,
            'environment' => $environment,
            'ssh' => $ssh,
        ];
    }

    /**
     * user:password@host:port, each part percent-decoded; [::1]:port for IPv6.
     *
     * @return array{user: string|null, password: string|null, host: string, port: int|null}
     */
    private static function authority(string $authority): array
    {
        $at = strrpos($authority, '@');
        $userinfo = $at === false ? null : substr($authority, 0, $at);
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        $user = null;
        $password = null;

        if ($userinfo !== null) {
            $colon = strpos($userinfo, ':');
            $user = rawurldecode($colon === false ? $userinfo : substr($userinfo, 0, $colon));
            $password = $colon === false ? null : rawurldecode(substr($userinfo, $colon + 1));
        }

        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::(\d+))?$/', $hostPort, $m)) {
            return ['user' => $user, 'password' => $password, 'host' => $m[1], 'port' => isset($m[2]) ? (int) $m[2] : null];
        }

        $colon = strrpos($hostPort, ':');
        $port = $colon !== false && ctype_digit(substr($hostPort, $colon + 1)) ? (int) substr($hostPort, $colon + 1) : null;

        return ['user' => $user, 'password' => $password, 'host' => rawurldecode($port !== null ? substr($hostPort, 0, (int) $colon) : $hostPort), 'port' => $port];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function splitQuery(string $rest): array
    {
        $lastAt = strrpos($rest, '@');
        $question = strpos($rest, '?', $lastAt === false ? 0 : $lastAt);

        if ($question === false) {
            return [$rest, []];
        }

        parse_str(substr($rest, $question + 1), $query);

        $options = [];
        foreach ($query as $key => $value) {
            $options[strtolower((string) $key)] = is_string($value) ? $value : '';
        }

        return [substr($rest, 0, $question), $options];
    }

    /**
     * @return array{driver: string, host: string|null, port: int|null, username: string|null, password: string|null, database: string|null, tls: bool, name: string|null, environment: string|null, ssh: array{host: string, port: int, username: string, password: string|null}|null}
     */
    private static function env(string $text): array
    {
        $vars = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $value = $m[2];
                if (preg_match('/^"(.*)"$/s', $value, $q) || preg_match("/^'(.*)'$/s", $value, $q)) {
                    $value = $q[1];
                } else {
                    $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
                }
                $vars[$m[1]] = $value;
            }
        }

        foreach (['DB_URL', 'DATABASE_URL', 'REDIS_URL'] as $url) {
            if (filled($vars[$url] ?? null)) {
                return self::parse($vars[$url]);
            }
        }

        $connection = strtolower($vars['DB_CONNECTION'] ?? '');

        if ($connection !== '') {
            if (! isset(self::SCHEMES[$connection])) {
                throw new InvalidArgumentException(__('Unknown kind of database: :scheme.', ['scheme' => $connection]));
            }

            $driver = self::SCHEMES[$connection][0];

            return self::result(
                $driver,
                $driver === 'sqlite' ? null : ($vars['DB_HOST'] ?? '127.0.0.1'),
                isset($vars['DB_PORT']) && ctype_digit($vars['DB_PORT']) ? (int) $vars['DB_PORT'] : null,
                $vars['DB_USERNAME'] ?? null,
                $vars['DB_PASSWORD'] ?? null,
                $vars['DB_DATABASE'] ?? null,
                false,
                ['env' => $vars['APP_ENV'] ?? '', 'name' => $vars['APP_NAME'] ?? ''],
                null,
            );
        }

        if (isset($vars['REDIS_HOST'])) {
            return self::result(
                'redis',
                $vars['REDIS_HOST'],
                isset($vars['REDIS_PORT']) && ctype_digit($vars['REDIS_PORT']) ? (int) $vars['REDIS_PORT'] : null,
                filled($vars['REDIS_USERNAME'] ?? null) ? $vars['REDIS_USERNAME'] : null,
                ($vars['REDIS_PASSWORD'] ?? '') === 'null' ? null : ($vars['REDIS_PASSWORD'] ?? null),
                $vars['REDIS_DB'] ?? null,
                strtolower($vars['REDIS_SCHEME'] ?? '') === 'tls',
                ['env' => $vars['APP_ENV'] ?? '', 'name' => filled($vars['APP_NAME'] ?? null) ? $vars['APP_NAME'].' Redis' : ''],
                null,
            );
        }

        throw new InvalidArgumentException(__('No DB_CONNECTION or REDIS_HOST in what was pasted.'));
    }

    /**
     * The link of a connection, TablePlus's way; the passwords only when asked for (the owner's).
     */
    public static function build(DatabaseConnection $connection, bool $secrets = false): string
    {
        $scheme = match ($connection->driver) {
            Driver::Pgsql => 'postgresql',
            Driver::Redis => $connection->tls ? 'rediss' : 'redis',
            Driver::Valkey => $connection->tls ? 'valkeys' : 'valkey',
            default => $connection->driver->value,
        };
        $ssh = $connection->sshConnection;
        $link = $scheme.($ssh !== null ? '+ssh' : '').'://';

        if ($ssh !== null) {
            $password = $secrets && $ssh->auth === 'password' && filled($ssh->password) ? ':'.rawurlencode((string) $ssh->password) : '';
            $link .= rawurlencode($ssh->username).$password.'@'.self::host($ssh->host).($ssh->port !== 22 ? ':'.$ssh->port : '').'/';
        }

        if ($connection->driver === Driver::Sqlite) {
            $path = (string) $connection->database;
            $link .= $ssh === null ? '/'.ltrim($path, '/') : (str_starts_with($path, '/') ? '/'.$path : $path);
        } else {
            $user = filled($connection->username) ? rawurlencode((string) $connection->username) : '';
            $password = $secrets && filled($connection->password) ? ':'.rawurlencode((string) $connection->password) : '';
            $link .= ($user !== '' || $password !== '' ? $user.$password.'@' : '').self::host((string) $connection->host)
                .($connection->port !== null && $connection->port !== $connection->driver->defaultPort() ? ':'.$connection->port : '')
                .(filled($connection->database) ? '/'.rawurlencode((string) $connection->database) : '');
        }

        $query = ['name' => $connection->name, 'env' => $connection->environment->value];
        if ($connection->tls && $connection->driver->isSql()) {
            $query['tls'] = 'true';
        }

        return $link.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function host(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
    }
}
