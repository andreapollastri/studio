<?php

namespace App\Databases\Redis;

use App\Databases\DatabaseException;

/**
 * A Redis or Valkey database for the client: its keys page by page (SCAN, never KEYS), each
 * key's type, TTL and value read by its type (so many items at most), and the changes the
 * page makes. The console's commands are told apart: those that only read, and those that
 * a production connection asks about first.
 */
class Keyspace
{
    public const READ_COMMANDS = [
        'GET', 'MGET', 'STRLEN', 'GETRANGE', 'EXISTS', 'TYPE', 'TTL', 'PTTL', 'EXPIRETIME', 'PEXPIRETIME', 'SCAN', 'KEYS', 'RANDOMKEY', 'DBSIZE', 'INFO', 'PING', 'ECHO', 'TIME',
        'HGET', 'HMGET', 'HGETALL', 'HKEYS', 'HVALS', 'HLEN', 'HEXISTS', 'HSCAN', 'HSTRLEN', 'HRANDFIELD',
        'LRANGE', 'LLEN', 'LINDEX', 'LPOS', 'SMEMBERS', 'SISMEMBER', 'SMISMEMBER', 'SCARD', 'SSCAN', 'SRANDMEMBER', 'SINTER', 'SUNION', 'SDIFF', 'SINTERCARD',
        'ZRANGE', 'ZREVRANGE', 'ZRANGEBYSCORE', 'ZREVRANGEBYSCORE', 'ZRANGEBYLEX', 'ZCARD', 'ZSCORE', 'ZMSCORE', 'ZRANK', 'ZREVRANK', 'ZCOUNT', 'ZLEXCOUNT', 'ZSCAN', 'ZRANDMEMBER',
        'XRANGE', 'XREVRANGE', 'XLEN', 'XINFO', 'XPENDING', 'PFCOUNT', 'GETBIT', 'BITCOUNT', 'BITPOS', 'GEOPOS', 'GEODIST', 'GEOSEARCH', 'GEOHASH',
        'OBJECT', 'MEMORY', 'DUMP', 'LASTSAVE', 'ROLE', 'COMMAND', 'LOLWUT', 'JSON.GET', 'JSON.TYPE', 'JSON.MGET', 'SELECT',
    ];

    /** What empties or reconfigures a server: asked about on every connection, refused to a reader. */
    public const DANGEROUS = ['FLUSHDB', 'FLUSHALL', 'SHUTDOWN', 'CONFIG', 'DEBUG', 'SWAPDB', 'MIGRATE', 'REPLICAOF', 'SLAVEOF', 'CLUSTER', 'FAILOVER', 'SCRIPT', 'FUNCTION', 'MODULE', 'ACL', 'BGREWRITEAOF', 'BGSAVE', 'SAVE', 'CLIENT', 'MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'SYNC', 'PSYNC', 'WAIT', 'BLPOP', 'BRPOP', 'BLMOVE', 'BZPOPMIN', 'BZPOPMAX', 'XREAD', 'XREADGROUP'];

    /** Commands the console never runs: they hold the connection forever. */
    public const BLOCKED = ['MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'SYNC', 'PSYNC'];

    public const ITEMS = 500;

    public function __construct(public readonly RespClient $client) {}

    public static function isRead(string $command): bool
    {
        $command = strtoupper($command);

        if (in_array($command, ['OBJECT', 'MEMORY', 'XINFO', 'COMMAND'], true)) {
            return true;
        }

        return in_array($command, self::READ_COMMANDS, true);
    }

    /**
     * A page of keys: SCAN goes on from $cursor until it found about $count keys or went round.
     *
     * @return array{cursor: string, keys: list<array{key: string, type: string, ttl: int}>}
     */
    public function scan(string $cursor, string $pattern, int $count = 200, ?string $type = null): array
    {
        $found = [];
        $rounds = 0;

        do {
            $arguments = ['SCAN', $cursor, 'MATCH', $pattern !== '' ? $pattern : '*', 'COUNT', 500];
            if ($type !== null && $type !== '') {
                array_push($arguments, 'TYPE', $type);
            }
            $reply = $this->client->command(...$arguments);
            $cursor = (string) ($reply[0] ?? '0');
            array_push($found, ...array_map('strval', (array) ($reply[1] ?? [])));
            $rounds++;
        } while ($cursor !== '0' && count($found) < $count && $rounds < 50);

        $found = array_values(array_unique($found));
        sort($found);
        $replies = $found === [] ? [] : $this->client->pipeline(array_merge(...array_map(fn (string $key) => [['TYPE', $key], ['PTTL', $key]], $found)));
        $keys = [];

        foreach ($found as $i => $key) {
            $keys[] = ['key' => $key, 'type' => is_string($replies[$i * 2] ?? null) ? $replies[$i * 2] : 'unknown', 'ttl' => is_int($replies[$i * 2 + 1] ?? null) ? $replies[$i * 2 + 1] : -1];
        }

        return ['cursor' => $cursor, 'keys' => $keys];
    }

    /**
     * Everything the key's page shows: type, TTL, length, memory, and its items.
     *
     * @return array{key: string, type: string, ttl: int, length: int|null, memory: int|null, encoding: string|null, value: mixed, truncated: bool}|null
     */
    public function describe(string $key): ?array
    {
        [$type, $ttl, $encoding, $memory] = $this->client->pipeline([['TYPE', $key], ['PTTL', $key], ['OBJECT', 'ENCODING', $key], ['MEMORY', 'USAGE', $key]]);

        if ($type === 'none' || ! is_string($type)) {
            return null;
        }

        [$value, $length] = match ($type) {
            'string' => [$this->client->command('GETRANGE', $key, 0, 1024 * 1024 - 1), $this->client->command('STRLEN', $key)],
            'hash' => [$this->pairs($this->scanItems('HSCAN', $key)), $this->client->command('HLEN', $key)],
            'list' => [$this->client->command('LRANGE', $key, 0, self::ITEMS - 1), $this->client->command('LLEN', $key)],
            'set' => [$this->scanItems('SSCAN', $key), $this->client->command('SCARD', $key)],
            'zset' => [$this->pairs((array) $this->client->command('ZRANGE', $key, 0, self::ITEMS - 1, 'WITHSCORES')), $this->client->command('ZCARD', $key)],
            'stream' => [$this->entries((array) $this->client->command('XREVRANGE', $key, '+', '-', 'COUNT', 100)), $this->client->command('XLEN', $key)],
            default => [null, null],
        };

        $length = is_int($length) ? $length : null;
        $count = is_array($value) ? count($value) : (is_string($value) ? strlen($value) : 0);

        return [
            'key' => $key,
            'type' => $type,
            'ttl' => is_int($ttl) ? $ttl : -1,
            'length' => $length,
            'memory' => is_int($memory) ? $memory : null,
            'encoding' => is_string($encoding) ? $encoding : null,
            'value' => $value,
            'truncated' => $length !== null && $count < $length,
        ];
    }

    /** @return list<string> */
    private function scanItems(string $command, string $key): array
    {
        $items = [];
        $cursor = '0';

        do {
            $reply = $this->client->command($command, $key, $cursor, 'COUNT', 200);
            $cursor = (string) ($reply[0] ?? '0');
            array_push($items, ...array_map('strval', (array) ($reply[1] ?? [])));
        } while ($cursor !== '0' && count($items) < self::ITEMS * ($command === 'HSCAN' ? 2 : 1));

        return $items;
    }

    /**
     * @param  array<mixed>  $flat
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $flat): array
    {
        $flat = array_values($flat);
        $pairs = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $pairs[] = [(string) $flat[$i], (string) $flat[$i + 1]];
        }

        return $pairs;
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<array{id: string, fields: list<array{0: string, 1: string}>}>
     */
    private function entries(array $entries): array
    {
        return array_values(array_map(fn ($entry) => ['id' => (string) ($entry[0] ?? ''), 'fields' => $this->pairs((array) ($entry[1] ?? []))], $entries));
    }

    /** @return array<int, int> database number => keys, from INFO keyspace */
    public function databases(): array
    {
        $info = (string) $this->client->command('INFO', 'keyspace');
        $counts = [];

        if (preg_match_all('/^db(\d+):keys=(\d+)/m', $info, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $counts[(int) $match[1]] = (int) $match[2];
            }
        }

        return $counts;
    }

    /** @return array<string, array<string, string>> section => key => value */
    public function info(): array
    {
        $sections = [];
        $section = 'server';

        foreach (preg_split('/\r?\n/', (string) $this->client->command('INFO')) ?: [] as $line) {
            if (str_starts_with($line, '# ')) {
                $section = strtolower(trim(substr($line, 2)));
            } elseif (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $sections[$section][$key] = $value;
            }
        }

        return $sections;
    }

    /** A new key of a type, with its first value. */
    public function create(string $key, string $type, string $value, string $field = '', float $score = 0): void
    {
        if ((int) $this->client->command('EXISTS', $key) > 0) {
            throw new DatabaseException(__('The key :key exists already.', ['key' => $key]));
        }

        match ($type) {
            'string' => $this->client->command('SET', $key, $value),
            'hash' => $this->client->command('HSET', $key, $field !== '' ? $field : 'field', $value),
            'list' => $this->client->command('RPUSH', $key, $value),
            'set' => $this->client->command('SADD', $key, $value),
            'zset' => $this->client->command('ZADD', $key, $score, $value),
            'stream' => $this->client->command('XADD', $key, '*', $field !== '' ? $field : 'field', $value),
            default => throw new DatabaseException(__('Unknown type :type.', ['type' => $type])),
        };
    }

    public function expire(string $key, ?int $seconds): void
    {
        $seconds === null || $seconds <= 0 ? $this->client->command('PERSIST', $key) : $this->client->command('EXPIRE', $key, $seconds);
    }

    /**
     * Values for the JSON export of a key: what import() puts back.
     *
     * @return array{key: string, type: string, ttl: int|null, value: mixed}|null
     */
    public function export(string $key): ?array
    {
        [$type, $ttl] = $this->client->pipeline([['TYPE', $key], ['PTTL', $key]]);

        $value = match ($type) {
            'string' => $this->client->command('GET', $key),
            'hash' => (function () use ($key) {
                $flat = (array) $this->client->command('HGETALL', $key);
                $out = [];
                for ($i = 0; $i + 1 < count($flat); $i += 2) {
                    $out[(string) $flat[$i]] = $flat[$i + 1];
                }

                return $out;
            })(),
            'list' => $this->client->command('LRANGE', $key, 0, -1),
            'set' => $this->client->command('SMEMBERS', $key),
            'zset' => array_map(fn (array $p) => ['member' => $p[0], 'score' => (float) $p[1]], $this->pairs((array) $this->client->command('ZRANGE', $key, 0, -1, 'WITHSCORES'))),
            'stream' => array_map(fn (array $e) => ['id' => $e['id'], 'fields' => array_combine(array_column($e['fields'], 0), array_column($e['fields'], 1))], $this->entries((array) $this->client->command('XRANGE', $key, '-', '+'))),
            default => null,
        };

        if (! is_string($type) || $value === null) {
            return null;
        }

        return ['key' => $key, 'type' => $type, 'ttl' => is_int($ttl) && $ttl > 0 ? $ttl : null, 'value' => self::encodeValue($value)];
    }

    /** @param  array{key: string, type: string, ttl?: int|null, value: mixed}  $item */
    public function import(array $item, bool $replace): bool
    {
        $key = (string) $item['key'];
        $value = self::decodeValue($item['value'] ?? null);

        if ((int) $this->client->command('EXISTS', $key) > 0) {
            if (! $replace) {
                return false;
            }
            $this->client->command('DEL', $key);
        }

        switch ($item['type']) {
            case 'string':
                $this->client->command('SET', $key, (string) $value);
                break;
            case 'hash':
                $args = [];
                foreach ((array) $value as $field => $v) {
                    array_push($args, (string) $field, (string) $v);
                }
                $args !== [] && $this->client->command('HSET', $key, ...$args);
                break;
            case 'list':
                foreach (array_chunk((array) $value, 500) as $chunk) {
                    $this->client->command('RPUSH', $key, ...array_map('strval', $chunk));
                }
                break;
            case 'set':
                foreach (array_chunk((array) $value, 500) as $chunk) {
                    $this->client->command('SADD', $key, ...array_map('strval', $chunk));
                }
                break;
            case 'zset':
                foreach (array_chunk((array) $value, 500) as $chunk) {
                    $args = [];
                    foreach ($chunk as $pair) {
                        array_push($args, (float) ($pair['score'] ?? 0), (string) ($pair['member'] ?? ''));
                    }
                    $this->client->command('ZADD', $key, ...$args);
                }
                break;
            case 'stream':
                foreach ((array) $value as $entry) {
                    $args = [];
                    foreach ((array) ($entry['fields'] ?? []) as $field => $v) {
                        array_push($args, (string) $field, (string) $v);
                    }
                    $args !== [] && $this->client->command('XADD', $key, (string) ($entry['id'] ?? '*'), ...$args);
                }
                break;
            default:
                return false;
        }

        if (! empty($item['ttl'])) {
            $this->client->command('PEXPIRE', $key, (int) $item['ttl']);
        }

        return true;
    }

    /** Binary values travel in JSON as {"base64": "…"}. */
    private static function encodeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::encodeValue(...), $value);
        }

        return is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? ['base64' => base64_encode($value)] : $value;
    }

    private static function decodeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_keys($value) === ['base64'] ? base64_decode((string) $value['base64']) : array_map(self::decodeValue(...), $value);
        }

        return $value;
    }
}
