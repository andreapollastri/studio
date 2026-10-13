<?php

namespace App\Databases\Redis;

use App\Databases\DatabaseException;

/**
 * Redis and Valkey spoken directly (RESP2), over TCP, TLS or the Unix socket of a tunnel:
 * no extension needed. Replies come back as PHP values: a status or a bulk string as a
 * string, an integer as an int, nil as null, an array as a list; an error is an exception.
 */
class RespClient
{
    /** @param  resource  $stream */
    public function __construct(private $stream) {}

    public static function connect(string $address, float $timeout = 10, bool $tls = false): self
    {
        $context = stream_context_create($tls ? ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true]] : []);
        $stream = @stream_socket_client($address, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new DatabaseException(__('Could not reach :address: :reason', ['address' => preg_replace('~^unix://.*~', __('the tunnel'), $address), 'reason' => $error ?: $errno]));
        }

        stream_set_timeout($stream, (int) config('studio.databases.timeout', 120));

        return new self($stream);
    }

    /** One command, its reply. */
    public function command(string|int|float ...$arguments): mixed
    {
        $this->write(array_values($arguments));

        return $this->read();
    }

    /**
     * Many commands at once, their replies in order; an error reply stays in its place.
     *
     * @param  list<list<string|int|float>>  $commands
     * @return list<mixed>
     */
    public function pipeline(array $commands): array
    {
        $payload = '';
        foreach ($commands as $arguments) {
            $payload .= self::encode($arguments);
        }
        $this->send($payload);

        $replies = [];
        foreach ($commands as $_) {
            try {
                $replies[] = $this->read();
            } catch (RedisError $e) {
                $replies[] = $e;
            }
        }

        return $replies;
    }

    /** @param  list<string|int|float>  $arguments */
    public static function encode(array $arguments): string
    {
        $out = '*'.count($arguments)."\r\n";
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            $out .= '$'.strlen($argument)."\r\n".$argument."\r\n";
        }

        return $out;
    }

    /** @param  list<string|int|float>  $arguments */
    private function write(array $arguments): void
    {
        $this->send(self::encode($arguments));
    }

    private function send(string $payload): void
    {
        while ($payload !== '') {
            $written = @fwrite($this->stream, $payload);
            if ($written === false || $written === 0) {
                throw new DatabaseException(__('The connection to the server was lost.'));
            }
            $payload = substr($payload, $written);
        }
    }

    private function read(): mixed
    {
        $line = $this->line();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);

        switch ($type) {
            case '+':
                return $rest;
            case '-':
                throw new RedisError($rest);
            case ':':
                return (int) $rest;
            case '$':
                $length = (int) $rest;
                if ($length < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = fread($this->stream, max(1, $length + 2 - strlen($data)));
                    if ($chunk === false || $chunk === '') {
                        throw new DatabaseException(__('The connection to the server was lost.'));
                    }
                    $data .= $chunk;
                }

                return substr($data, 0, $length);
            case '*':
                $count = (int) $rest;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    try {
                        $items[] = $this->read();
                    } catch (RedisError $e) {
                        $items[] = $e;
                    }
                }

                return $items;
            default:
                throw new DatabaseException(__('The server answered something that is not Redis: :line', ['line' => mb_substr($line, 0, 80)]));
        }
    }

    private function line(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new DatabaseException($meta['timed_out'] ? __('The server did not answer in time.') : __('The connection to the server was lost.'));
        }

        return rtrim($line, "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    /**
     * A command line as redis-cli reads it: words, "double" and 'single' quoted strings with
     * their escapes.
     *
     * @return list<string>
     */
    public static function parse(string $line): array
    {
        $words = [];
        $i = 0;
        $length = strlen($line);

        while ($i < $length) {
            while ($i < $length && ctype_space($line[$i])) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }

            $word = '';
            $quote = in_array($line[$i], ['"', "'"], true) ? $line[$i++] : null;

            while ($i < $length) {
                $c = $line[$i];

                if ($quote === null && ctype_space($c)) {
                    break;
                }

                if ($quote !== null && $c === $quote) {
                    $i++;
                    $quote = null;

                    break;
                }

                if ($quote === '"' && $c === '\\' && $i + 1 < $length) {
                    $next = $line[$i + 1];
                    if ($next === 'x' && $i + 3 < $length && ctype_xdigit(substr($line, $i + 2, 2))) {
                        $word .= (string) hex2bin(substr($line, $i + 2, 2));
                        $i += 4;

                        continue;
                    }
                    $word .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $next,
                    };
                    $i += 2;

                    continue;
                }

                $word .= $c;
                $i++;
            }

            $words[] = $word;
        }

        return $words;
    }
}
