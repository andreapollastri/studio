<?php

namespace App\Databases;

use App\Databases\Links\Link;
use App\Databases\Links\PdoLink;
use App\Databases\Links\SqliteShellLink;
use App\Databases\Redis\RespClient;
use App\Models\DatabaseConnection;
use App\Models\User;
use PDO;
use PDOException;

/**
 * Opens a connection for one person: checks they still reach it, opens the tunnel when it
 * goes through SSH, and hands back a link (SQL) or a client (Redis) that speaks to it. The
 * database's own password never leaves this class.
 */
class Connector
{
    public function __construct(protected Tunnels $tunnels) {}

    public function tunnels(): Tunnels
    {
        return $this->tunnels;
    }

    /** A SQL link on this database (null: the connection's own). */
    public function sql(DatabaseConnection $connection, User $user, ?string $database = null): Link
    {
        $this->authorize($connection, $user);
        $driver = $connection->driver;

        if (! $driver->isSql()) {
            throw new DatabaseException(__(':driver is not a SQL database.', ['driver' => $driver->label()]));
        }

        if ($driver === Driver::Sqlite) {
            return $this->sqlite($connection, $user);
        }

        $endpoint = $this->endpoint($connection, $user);
        $database = $database ?? (filled($connection->database) ? (string) $connection->database : null);
        $timeout = (int) config('studio.databases.connect_timeout', 10);

        if ($driver->family() === 'mysql') {
            $dsn = isset($endpoint['socket'])
                ? 'mysql:unix_socket='.$endpoint['socket']
                : 'mysql:host='.$endpoint['host'].';port='.$endpoint['port'];
            $dsn .= ($database !== null ? ';dbname='.$database : '').';charset=utf8mb4';
            $options = [PDO::ATTR_TIMEOUT => $timeout];

            if ($connection->tls && ! isset($endpoint['socket'])) {
                $options[self::mysqlAttribute('ATTR_SSL_CA')] = (string) (openssl_get_cert_locations()['default_cert_file'] ?? '/etc/ssl/certs/ca-certificates.crt');
                $options[self::mysqlAttribute('ATTR_SSL_VERIFY_SERVER_CERT')] = false;
            }
        } else {
            $dsn = 'pgsql:host='.($endpoint['socket'] ?? $endpoint['host']).';port='.$endpoint['port'].';dbname='.($database ?? 'postgres')
                .';connect_timeout='.$timeout.';application_name=studio'
                .(isset($endpoint['socket']) ? '' : ';sslmode='.($connection->tls ? 'require' : 'prefer'));
            $options = [];
        }

        try {
            $pdo = new PDO($dsn, $connection->username, $connection->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                ...$options,
            ]);
        } catch (PDOException $e) {
            throw DatabaseException::from($e);
        }

        $statementTimeout = (int) config('studio.databases.timeout', 120);

        try {
            match (true) {
                $driver === Driver::Mariadb => $pdo->exec('SET SESSION max_statement_time = '.$statementTimeout),
                $driver === Driver::Mysql => $pdo->exec('SET SESSION max_execution_time = '.($statementTimeout * 1000)),
                default => $pdo->exec("SET statement_timeout = '".$statementTimeout."s'"),
            };
        } catch (PDOException) {
            // a MySQL that is a MariaDB, or the other way round: no time limit then
        }

        $this->touch($connection, $user);

        return new PdoLink($pdo, $driver);
    }

    private function sqlite(DatabaseConnection $connection, User $user): Link
    {
        $path = (string) $connection->database;

        if ($connection->isLocalFile()) {
            if (! $connection->user->isAdmin()) {
                throw new DatabaseException(__('A SQLite file on this server is for administrators: :owner no longer is one.', ['owner' => $connection->user->name]));
            }

            if (! is_file($path)) {
                throw new DatabaseException(__('There is no file :path on this server.', ['path' => $path]));
            }

            try {
                $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM]);
                $pdo->exec('PRAGMA busy_timeout = 5000');
            } catch (PDOException $e) {
                throw DatabaseException::from($e);
            }

            $this->touch($connection, $user);

            return new PdoLink($pdo, Driver::Sqlite);
        }

        $ssh = $connection->sshConnection ?? throw new DatabaseException(__('The SSH connection where the file lives is gone.'));
        $this->touch($connection, $user);

        return new SqliteShellLink($this->tunnels, $ssh, $connection->tunnelUser($user), $path);
    }

    /** A Redis or Valkey client, on this database number (null: the connection's own). */
    public function redis(DatabaseConnection $connection, User $user, ?int $database = null): RespClient
    {
        $this->authorize($connection, $user);
        $endpoint = $this->endpoint($connection, $user);
        $address = isset($endpoint['socket'])
            ? 'unix://'.$endpoint['socket']
            : ($connection->tls ? 'tls://' : 'tcp://').(str_contains($endpoint['host'], ':') ? '['.$endpoint['host'].']' : $endpoint['host']).':'.$endpoint['port'];

        $client = RespClient::connect($address, (float) config('studio.databases.connect_timeout', 10), $connection->tls && ! isset($endpoint['socket']));

        if (filled($connection->password)) {
            filled($connection->username) && $connection->username !== 'default'
                ? $client->command('AUTH', (string) $connection->username, (string) $connection->password)
                : $client->command('AUTH', (string) $connection->password);
        }

        $database ??= (int) ($connection->database ?: 0);
        if ($database !== 0) {
            $client->command('SELECT', $database);
        }

        $this->touch($connection, $user);

        return $client;
    }

    /**
     * Where to connect: the host and port as they are, or the local end of the tunnel.
     *
     * @return array{host: string, port: int, socket: string|null}
     */
    public function endpoint(DatabaseConnection $connection, User $user): array
    {
        $port = $connection->port ?? (int) $connection->driver->defaultPort();
        $host = (string) ($connection->host ?: '127.0.0.1');

        if ($connection->ssh_connection_id === null) {
            return ['host' => $host, 'port' => $port, 'socket' => null];
        }

        $ssh = $connection->sshConnection ?? throw new DatabaseException(__('The SSH connection of the tunnel is gone: edit the connection.'));
        $socket = $this->tunnels->forward($ssh, $connection->tunnelUser($user), $host, $port, $connection->driver === Driver::Pgsql);

        return ['host' => '127.0.0.1', 'port' => $port, 'socket' => $socket];
    }

    public function authorize(DatabaseConnection $connection, User $user): void
    {
        if ($connection->accessFor($user) === null) {
            throw new DatabaseException(__('This connection is no longer shared with you.'));
        }
    }

    private function touch(DatabaseConnection $connection, User $user): void
    {
        if ($connection->exists && $connection->isOwnedBy($user) && ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute()))) {
            $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    /** Pdo\Mysql's constants on PHP 8.4 and later, PDO's before. */
    private static function mysqlAttribute(string $name): int
    {
        return defined('Pdo\\Mysql::'.$name) ? (int) constant('Pdo\\Mysql::'.$name) : (int) constant('PDO::MYSQL_'.$name);
    }
}
