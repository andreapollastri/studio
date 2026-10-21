<?php

namespace App\Livewire\Tools;

use App\Databases\DatabaseException;
use App\Databases\Redis\Keyspace;
use App\Databases\Redis\RedisError;
use App\Databases\Redis\RespClient;
use App\Livewire\Concerns\UsesDatabaseConnection;
use App\Models\DatabaseConnection;
use App\Models\DatabaseQuery;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Redis and Valkey: the keys of a database page by page (SCAN with a pattern and a type),
 * each key's value by its type to read and change, its TTL; a console that speaks like
 * redis-cli; the server's INFO; keys to and from JSON. A person who may only read gets
 * the commands that read; on production the changes are locked until unlocked.
 */
#[Layout('layouts::app')]
class RedisClient extends Component
{
    use UsesDatabaseConnection;
    use WithFileUploads;

    #[Locked]
    public DatabaseConnection $connection;

    /** Inside the project's Db tab (App\Livewire\Studio\PaneDatabase): sized to the pane, no way back to the list. */
    #[Locked]
    public bool $embedded = false;

    #[Url]
    public int $db = 0;

    #[Url]
    public ?string $key = null;

    /** keys, console, info, transfers */
    #[Url]
    public string $tab = 'keys';

    public string $pattern = '';

    public string $type = '';

    /** @var list<array{key: string, type: string, ttl: int}> */
    public array $keys = [];

    public string $cursor = '0';

    public bool $scanned = false;

    // ---- Editing the open key
    public string $stringValue = '';

    public string $field = '';

    public string $fieldValue = '';

    public string $score = '0';

    public ?int $ttl = null;

    /** The key whose string is in the editor: it is read again only for another key. */
    public ?string $loadedKey = null;

    // ---- A new key
    public string $newKey = '';

    public string $newType = 'string';

    public string $newField = '';

    public string $newValue = '';

    public string $newScore = '0';

    // ---- The console
    public string $command = '';

    /** @var list<array{command: string, reply: string, error: bool}> */
    public array $console = [];

    // ---- Imports and exports
    public string $exportPattern = '';

    public bool $exportGzip = false;

    public ?TemporaryUploadedFile $importFile = null;

    public bool $importReplace = false;

    private ?RespClient $client = null;

    public function mount(DatabaseConnection $connection): void
    {
        $this->connection = $connection;
        $this->guard();
        abort_unless($connection->driver->isRedis(), 404);
        $this->db = $this->db ?: (int) ($connection->database ?: 0);
        $this->tab = in_array($this->tab, ['keys', 'console', 'info', 'transfers'], true) ? $this->tab : 'keys';
    }

    private function keyspace(): Keyspace
    {
        return new Keyspace($this->client ??= $this->connector()->redis($this->connection, auth()->user(), $this->db));
    }

    public function dehydrate(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function refresh(): void
    {
        $this->loadedKey = null;
        $this->scan(true);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['keys', 'console', 'info', 'transfers'], true) ? $tab : 'keys';
    }

    public function useDatabase(int $db): void
    {
        $this->db = max(0, $db);
        $this->key = null;
        $this->scan(true);
    }

    public function updatedPattern(): void
    {
        $this->scan(true);
    }

    public function updatedType(): void
    {
        $this->scan(true);
    }

    /** The next page of keys, or the first one again. */
    public function scan(bool $fresh = false): void
    {
        try {
            $page = $this->keyspace()->scan($fresh ? '0' : $this->cursor, $this->pattern, 200, $this->type !== '' ? $this->type : null);
            $this->keys = $fresh ? $page['keys'] : array_values(collect([...$this->keys, ...$page['keys']])->unique('key')->sortBy('key')->all());
            $this->keys = array_slice($this->keys, 0, 5000);
            $this->cursor = $page['cursor'];
            $this->scanned = true;
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function openKey(string $key): void
    {
        $this->key = $key;
        $this->tab = 'keys';
        $this->reset(['field', 'fieldValue', 'score']);
    }

    /* ------------------------------------------------------------------ changes */

    private function change(callable $change, string $done): void
    {
        try {
            $this->ensureWritable();
            $change($this->keyspace()->client);
            Log::info('redis change', ['user' => auth()->user()->email, 'connection' => $this->connection->name, 'db' => $this->db, 'key' => $this->key, 'action' => $done]);
            Flux::toast(variant: 'success', text: $done);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function saveString(): void
    {
        $this->change(function (RespClient $c) {
            $ttl = (int) $c->command('PTTL', (string) $this->key);
            $ttl > 0 ? $c->command('SET', (string) $this->key, $this->stringValue, 'PX', $ttl) : $c->command('SET', (string) $this->key, $this->stringValue);
        }, __('Saved.'));
        $this->loadedKey = null;
    }

    public function setField(): void
    {
        $this->validate(['field' => ['required', 'string']]);
        $this->change(fn (RespClient $c) => $c->command('HSET', (string) $this->key, $this->field, $this->fieldValue), __('Saved.'));
        $this->reset(['field', 'fieldValue']);
    }

    public function deleteField(string $field): void
    {
        $this->change(fn (RespClient $c) => $c->command('HDEL', (string) $this->key, $field), __('Field removed.'));
    }

    public function pushItem(string $side = 'right'): void
    {
        $this->change(fn (RespClient $c) => $c->command($side === 'left' ? 'LPUSH' : 'RPUSH', (string) $this->key, $this->fieldValue), __('Added.'));
        $this->fieldValue = '';
    }

    public function setItem(int $index, string $value): void
    {
        $this->change(fn (RespClient $c) => $c->command('LSET', (string) $this->key, $index, $value), __('Saved.'));
    }

    public function removeItem(string $value): void
    {
        $this->change(fn (RespClient $c) => $c->command('LREM', (string) $this->key, 1, $value), __('Removed.'));
    }

    public function addMember(): void
    {
        $this->change(fn (RespClient $c) => $c->command('SADD', (string) $this->key, $this->fieldValue), __('Added.'));
        $this->fieldValue = '';
    }

    public function removeMember(string $member): void
    {
        $this->change(fn (RespClient $c) => $c->command('SREM', (string) $this->key, $member), __('Removed.'));
    }

    public function addScored(): void
    {
        $this->validate(['score' => ['required', 'numeric']]);
        $this->change(fn (RespClient $c) => $c->command('ZADD', (string) $this->key, (float) $this->score, $this->fieldValue), __('Saved.'));
        $this->fieldValue = '';
    }

    public function removeScored(string $member): void
    {
        $this->change(fn (RespClient $c) => $c->command('ZREM', (string) $this->key, $member), __('Removed.'));
    }

    public function addEntry(): void
    {
        $this->validate(['field' => ['required', 'string']]);
        $this->change(fn (RespClient $c) => $c->command('XADD', (string) $this->key, '*', $this->field, $this->fieldValue), __('Added.'));
        $this->reset(['field', 'fieldValue']);
    }

    public function saveTtl(): void
    {
        $this->validate(['ttl' => ['nullable', 'integer', 'min:0']]);
        $this->change(fn () => $this->keyspace()->expire((string) $this->key, $this->ttl), $this->ttl ? __('It expires in :seconds s.', ['seconds' => $this->ttl]) : __('It no longer expires.'));
    }

    public function renameKey(string $to): void
    {
        if (trim($to) === '' || $to === $this->key) {
            return;
        }

        $this->change(fn (RespClient $c) => (int) $c->command('RENAMENX', (string) $this->key, $to) === 1 ? null : throw new DatabaseException(__('The key :key exists already.', ['key' => $to])), __('Renamed.'));
        $this->key = $to;
        $this->scan(true);
    }

    public function deleteKey(): void
    {
        $this->change(fn (RespClient $c) => $c->command('UNLINK', (string) $this->key), __('Key deleted.'));
        $this->keys = array_values(array_filter($this->keys, fn (array $k) => $k['key'] !== $this->key));
        $this->key = null;
    }

    public function startNewKey(): void
    {
        $this->reset(['newKey', 'newType', 'newField', 'newValue', 'newScore']);
        Flux::modal('redis-key')->show();
    }

    public function createKey(): void
    {
        $this->validate([
            'newKey' => ['required', 'string', 'max:1024'],
            'newType' => ['required', Rule::in(['string', 'hash', 'list', 'set', 'zset', 'stream'])],
            'newScore' => ['nullable', 'numeric'],
        ]);

        try {
            $this->ensureWritable();
            $this->keyspace()->create($this->newKey, $this->newType, $this->newValue, $this->newField, (float) $this->newScore);
            Flux::modal('redis-key')->close();
            $this->key = $this->newKey;
            $this->scan(true);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ console */

    /** @return array{confirm?: string}|null */
    public function run(bool $confirmed = false): ?array
    {
        $words = RespClient::parse($this->command);

        if ($words === []) {
            return null;
        }

        $name = strtoupper($words[0]);
        $read = Keyspace::isRead($name);
        $entry = ['command' => $this->command, 'reply' => '', 'error' => true];

        if (in_array($name, Keyspace::BLOCKED, true)) {
            $entry['reply'] = __(':command holds the connection open: it does not run here.', ['command' => $name]);
        } elseif (! $read && ! $this->canWrite()) {
            $entry['reply'] = __('You may only read this connection: :command changes data.', ['command' => $name]);
        } elseif (! $read && $this->isLocked()) {
            $entry['reply'] = __('This is a production server: unlock changes first (the lock at the top).');
        } elseif (! $confirmed && (in_array($name, Keyspace::DANGEROUS, true) || (! $read && $this->connection->environment->isProduction()))) {
            return ['confirm' => __('Run :command on :name (:environment)?', ['command' => $name, 'name' => $this->connection->name, 'environment' => $this->connection->environment->label()])];
        } else {
            $started = hrtime(true);
            $error = null;

            try {
                $reply = $this->keyspace()->client->command(...$words);
                $entry = ['command' => $this->command, 'reply' => self::format($reply), 'error' => false];

                if ($name === 'SELECT' && isset($words[1])) {
                    $this->db = (int) $words[1];
                }
            } catch (RedisError $e) {
                $entry['reply'] = '(error) '.$e->getMessage();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $entry['reply'] = DatabaseException::from($e)->getMessage();
                $error = $entry['reply'];
            }

            DatabaseQuery::record($this->connection, auth()->user(), (string) $this->db, $this->command, $error, (int) round((hrtime(true) - $started) / 1e6));

            if (! $read) {
                Log::info('redis command', ['user' => auth()->user()->email, 'connection' => $this->connection->name, 'db' => $this->db, 'command' => $name]);
            }
        }

        $this->console = array_slice([...$this->console, $entry], -100);
        $this->command = '';

        return null;
    }

    public function clearConsole(): void
    {
        $this->console = [];
    }

    /** A reply as redis-cli prints it. */
    public static function format(mixed $reply, int $depth = 0): string
    {
        $pad = str_repeat('   ', $depth);

        return match (true) {
            $reply === null => '(nil)',
            is_int($reply) => '(integer) '.$reply,
            $reply instanceof RedisError => '(error) '.$reply->getMessage(),
            is_array($reply) => $reply === [] ? '(empty array)' : implode("\n", array_map(fn ($item, $i) => ($i === 0 && $depth > 0 ? '' : $pad).($i + 1).') '.ltrim(self::format($item, $depth + 1)), $reply, array_keys($reply))),
            default => str_contains((string) $reply, "\n") ? (string) $reply : '"'.addcslashes((string) $reply, "\"\\\0..\37").'"',
        };
    }

    /* ------------------------------------------------------------------ imports and exports */

    public function startExport(): void
    {
        $this->queueTransfer('export', 'json', ['database' => $this->db, 'pattern' => $this->exportPattern, 'gzip' => $this->exportGzip]);
        Flux::toast(variant: 'success', text: __('Export started.'));
    }

    public function startImport(): void
    {
        try {
            $this->ensureWritable();
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        $this->validate(['importFile' => ['required', 'file', 'max:'.((int) config('studio.databases.max_import_mb', 512) * 1024)]]);
        $this->queueTransfer('import', 'json', ['database' => $this->db, 'replace' => $this->importReplace], $this->importFile);
        $this->reset(['importFile', 'importReplace']);
        Flux::toast(variant: 'success', text: __('Import started.'));
    }

    public function render(): View
    {
        $error = null;
        $detail = null;
        $databases = [];
        $info = [];

        try {
            if (! $this->scanned) {
                $this->scan(true);
            }

            $databases = $this->keyspace()->databases();

            if ($this->tab === 'keys' && $this->key !== null) {
                $detail = $this->keyspace()->describe($this->key);
                if ($detail !== null) {
                    $this->ttl = $detail['ttl'] > 0 ? (int) ceil($detail['ttl'] / 1000) : null;
                    if ($detail['type'] === 'string' && $this->loadedKey !== $this->key) {
                        $this->stringValue = (string) $detail['value'];
                        $this->loadedKey = $this->key;
                    }
                }
            }

            if ($this->tab === 'info') {
                $info = $this->keyspace()->info();
            }
        } catch (Throwable $e) {
            $error = DatabaseException::from($e)->getMessage();
        }

        return view('livewire.tools.redis-client', [
            'error' => $error,
            'detail' => $detail,
            'databases' => $databases,
            'info' => $info,
            'canWrite' => $this->canWrite(),
            'locked' => $this->isLocked(),
            'transfers' => $this->tab === 'transfers' ? $this->transfersList() : collect(),
        ])->title($this->connection->name.' · '.__('Databases'));
    }
}
