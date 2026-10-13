<?php

namespace App\Livewire\Tools;

use App\Databases\ConnectionString;
use App\Databases\Connector;
use App\Databases\DatabaseException;
use App\Databases\Dialects\Dialect;
use App\Databases\Driver;
use App\Databases\Environment;
use App\Databases\Redis\Keyspace;
use App\Enums\UserRole;
use App\Livewire\Concerns\SearchesDirectory;
use App\Models\DatabaseConnection;
use App\Models\DatabaseGroup;
use App\Models\DatabasePreference;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshKey;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Tools → Databases: every connection a person reaches, theirs and those shared with them
 * through a project, in groups of their own with colours, favourites first. A connection
 * is made from the form or from a link (TablePlus's mysql+ssh://… and the like, or the DB_
 * lines of a .env); a tunnel goes through one of the SSH page's connections, or a new one
 * the link brings. Opening one leads to the client (SQL) or the key browser (Redis).
 *
 * @property-read BaseCollection<int, array{connection: DatabaseConnection, mine: bool, favorite: bool, group: DatabaseGroup|null, access: string}> $connections
 * @property-read Collection<int, DatabaseGroup> $groups
 * @property-read BaseCollection<int, SshConnection> $sshConnections
 * @property-read Collection<int, SshKey> $sshKeys
 */
#[Layout('layouts::app')]
#[Title('Databases')]
class Databases extends Component
{
    use SearchesDirectory;

    public string $search = '';

    /** uuid of the connection in the form; null: a new one */
    public ?string $editing = null;

    public string $name = '';

    public string $driver = 'mysql';

    public string $environment = 'development';

    public string $host = '127.0.0.1';

    public int|string|null $port = null;

    public string $username = '';

    public string $password = '';

    public bool $password_clear = false;

    public string $database = '';

    public bool $tls = false;

    /** none, ssh (an SSH connection of the SSH page), new (one the link brought), local (SQLite here) */
    public string $via = 'none';

    /** uuid of the SSH connection */
    public ?string $ssh = null;

    public string $tunnel_as = 'owner';

    // ---- A new SSH connection, from a pasted link
    public string $ssh_host = '';

    public int|string $ssh_port = 22;

    public string $ssh_username = '';

    public string $ssh_password = '';

    /** uuid of a key of the person, when the link had no SSH password */
    public ?string $ssh_key = null;

    public ?string $ssh_host_key = null;

    /** uuid of the project; null: personal */
    public ?string $project = null;

    /** @var array<string, string> role => none|read|write */
    public array $access = ['client' => 'none', 'pm' => 'none', 'dev' => 'none'];

    /** uuid of the group of the person */
    public ?string $group = null;

    public string $link = '';

    /** What the last test said, and the host keys it found to accept. */
    public ?string $testResult = null;

    public bool $testOk = false;

    /** @var list<string> */
    public array $fingerprints = [];

    // ---- A group in its dialog
    public ?string $groupEditing = null;

    public string $group_name = '';

    public string $group_color = 'zinc';

    public function mount(): void
    {
        abort_unless($this->canCreate() || $this->connections->isNotEmpty(), 403);
    }

    public function canCreate(): bool
    {
        return auth()->user()->canUseSsh();
    }

    /** @return BaseCollection<int, array{connection: DatabaseConnection, mine: bool, favorite: bool, group: DatabaseGroup|null, access: string}> */
    #[Computed]
    public function connections(): BaseCollection
    {
        $user = auth()->user();
        $preferences = $user->databasePreferences()->with('group')->get()->keyBy('database_connection_id');

        return DatabaseConnection::query()
            ->visibleTo($user)
            ->with(['user', 'project', 'sshConnection'])
            ->get()
            ->filter(fn (DatabaseConnection $connection) => $connection->accessFor($user) !== null)
            ->map(function (DatabaseConnection $connection) use ($user, $preferences) {
                $preference = $preferences->get($connection->id);

                return [
                    'connection' => $connection,
                    'mine' => $connection->isOwnedBy($user),
                    'favorite' => (bool) $preference?->favorite,
                    'group' => $preference?->group,
                    'access' => (string) $connection->accessFor($user),
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower($row['connection']->name))
            ->values();
    }

    /** @return Collection<int, DatabaseGroup> */
    #[Computed]
    public function groups(): Collection
    {
        return auth()->user()->databaseGroups()->get();
    }

    /**
     * The SSH connections a tunnel may go through: those this person reaches with a key or a password of their own.
     *
     * @return BaseCollection<int, SshConnection>
     */
    #[Computed]
    public function sshConnections(): BaseCollection
    {
        $user = auth()->user();

        if (! $user->canUseSsh()) {
            return collect();
        }

        return SshConnection::query()->visibleTo($user)->with(['key', 'credentials' => fn ($q) => $q->where('user_id', $user->id)->with('key')])
            ->orderBy('name')->get()->filter(fn (SshConnection $c) => $c->canConnect($user))->values();
    }

    /** @return Collection<int, SshKey> the keys a new SSH connection may use */
    #[Computed]
    public function sshKeys(): Collection
    {
        return SshKey::query()->usableBy(auth()->user(), null)->orderByRaw('user_id is null')->orderBy('name')->get();
    }

    /* ------------------------------------------------------------------ the form */

    public function newConnection(?string $group = null): void
    {
        abort_unless($this->canCreate(), 403);
        $this->resetForm();
        $this->group = $group !== null && $this->groups->firstWhere('uuid', $group) ? $group : null;

        Flux::modal('database-connection')->show();
    }

    public function editConnection(string $id): void
    {
        $connection = $this->own($id);
        $this->resetForm();

        $this->editing = $connection->uuid;
        $this->name = $connection->name;
        $this->driver = $connection->driver->value;
        $this->environment = $connection->environment->value;
        $this->host = (string) $connection->host;
        $this->port = $connection->port;
        $this->username = (string) $connection->username;
        $this->database = (string) $connection->database;
        $this->tls = $connection->tls;
        $this->via = $connection->sshConnection !== null ? 'ssh' : ($connection->isLocalFile() ? 'local' : 'none');
        $this->ssh = $connection->sshConnection?->uuid;
        $this->tunnel_as = $connection->tunnel_as;
        $this->project = $connection->project?->uuid;
        $this->access = $connection->accessForForm();
        $this->group = $this->preference($connection)?->group?->uuid;

        Flux::modal('database-connection')->show();
    }

    /** A copy of one of this person's connections, password included: theirs to copy. */
    public function duplicateConnection(string $id): void
    {
        $connection = $this->own($id);
        $copy = $connection->replicate(['uuid', 'last_used_at']);
        $copy->name = $this->freeName($connection->name.' '.__('copy'));
        $copy->save();

        if (($preference = $this->preference($connection)) !== null) {
            DatabasePreference::query()->create(['user_id' => auth()->id(), 'database_connection_id' => $copy->id, 'database_group_id' => $preference->database_group_id]);
        }

        unset($this->connections);
        $this->editConnection($copy->uuid);
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'name', 'driver', 'environment', 'host', 'port', 'username', 'password', 'password_clear', 'database', 'tls', 'via', 'ssh', 'tunnel_as',
            'ssh_host', 'ssh_port', 'ssh_username', 'ssh_password', 'ssh_key', 'ssh_host_key', 'project', 'access', 'group', 'link', 'testResult', 'testOk', 'fingerprints']);
        $this->resetValidation();
    }

    public function updatedDriver(): void
    {
        $driver = Driver::tryFrom($this->driver) ?? Driver::Mysql;

        if ($driver === Driver::Sqlite) {
            $this->via = $this->via === 'none' ? ($this->sshConnections->isNotEmpty() ? 'ssh' : (auth()->user()->isAdmin() ? 'local' : 'ssh')) : $this->via;
        } elseif ($this->via === 'local') {
            $this->via = 'none';
        }

        $this->port = null;
        $this->testResult = null;
    }

    public function pickProject(?string $id): void
    {
        $this->project = $id !== null && auth()->user()->accessibleProjects()->where('uuid', $id)->exists() ? $id : null;
    }

    /**
     * Fill the form from a link or a .env. The SSH part finds the SSH connection with the same
     * host, port and user, or becomes a new one, saved with the database's connection.
     */
    public function applyLink(): void
    {
        try {
            $parsed = ConnectionString::parse($this->link);
        } catch (InvalidArgumentException $e) {
            $this->addError('link', $e->getMessage());

            return;
        }

        $this->resetValidation();
        $this->driver = $parsed['driver'];
        $this->host = (string) ($parsed['host'] ?? '');
        $this->port = $parsed['port'];
        $this->username = (string) $parsed['username'];
        $this->password = (string) $parsed['password'];
        $this->password_clear = false;
        $this->database = (string) $parsed['database'];
        $this->tls = $parsed['tls'];
        $this->environment = $parsed['environment'] ?? $this->environment;
        $this->name = $parsed['name'] ?? ($this->name !== '' ? $this->name : $this->suggestName($parsed));
        $this->testResult = null;
        $this->fingerprints = [];

        if ($parsed['ssh'] === null) {
            $this->via = $parsed['driver'] === 'sqlite' ? 'local' : 'none';
        } else {
            $ssh = $parsed['ssh'];
            $existing = $this->sshConnections->first(fn (SshConnection $c) => strcasecmp($c->host, $ssh['host']) === 0 && $c->port === $ssh['port'] && $c->username === $ssh['username']);

            if ($existing !== null) {
                $this->via = 'ssh';
                $this->ssh = $existing->uuid;
            } else {
                $this->via = 'new';
                $this->ssh_host = $ssh['host'];
                $this->ssh_port = $ssh['port'];
                $this->ssh_username = $ssh['username'];
                $this->ssh_password = (string) $ssh['password'];
                $this->ssh_key = $ssh['password'] === null ? $this->sshKeys->first()?->uuid : null;
                $this->ssh_host_key = null;
            }
        }

        $this->link = '';
        Flux::toast(text: $this->via === 'new'
            ? __('Filled in from the link: the SSH host :host becomes a new SSH connection when you save. Test it to accept its host key.', ['host' => $this->ssh_host])
            : __('Filled in from the link.'));
    }

    /** @param  array{driver: string, host: string|null, database: string|null, ssh: array{host: string}|null}  $parsed */
    private function suggestName(array $parsed): string
    {
        $base = $parsed['driver'] === 'sqlite' ? basename((string) $parsed['database']) : ($parsed['database'] ?: ($parsed['ssh']['host'] ?? $parsed['host']));

        return $this->freeName(mb_substr((string) ($base ?: Driver::from($parsed['driver'])->label()), 0, 50));
    }

    private function freeName(string $name): string
    {
        $taken = auth()->user()->databaseConnections()->pluck('name')->all();
        $candidate = mb_substr($name, 0, 60);

        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = mb_substr($name, 0, 55).' '.$i;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionRules(?DatabaseConnection $existing): array
    {
        $user = auth()->user();
        $driver = Driver::tryFrom($this->driver);
        $sqlite = $driver === Driver::Sqlite;

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('database_connections', 'name')->where('user_id', $user->id)->ignore($existing?->id)],
            'driver' => ['required', Rule::enum(Driver::class)],
            'environment' => ['required', Rule::enum(Environment::class)],
            'host' => [$sqlite ? 'nullable' : 'required', 'string', 'max:255', 'regex:/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9_]([A-Za-z0-9._-]*[A-Za-z0-9])?|[0-9a-fA-F:]+)$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:1000'],
            'database' => $sqlite
                ? ['required', 'string', 'max:1024', 'regex:/^(\/|~\/)[^\x00]+$/']
                : ($driver?->isRedis() ? ['nullable', 'integer', 'between:0,1023'] : ['nullable', 'string', 'max:255']),
            'tls' => ['boolean'],
            'via' => ['required', Rule::in($sqlite ? ['ssh', 'new', ...($user->isAdmin() ? ['local'] : [])] : ['none', 'ssh', 'new'])],
            'ssh' => ['required_if:via,ssh', 'nullable', Rule::in($this->sshConnections->pluck('uuid')->all())],
            'tunnel_as' => ['required', Rule::in(['owner', 'member'])],
            'ssh_host' => ['required_if:via,new', 'nullable', 'string', 'max:255', 'regex:/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?|[0-9a-fA-F:]+)$/'],
            'ssh_port' => ['required_if:via,new', 'nullable', 'integer', 'between:1,65535'],
            'ssh_username' => ['required_if:via,new', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._][A-Za-z0-9._@-]*$/'],
            'ssh_password' => ['nullable', 'string', 'max:500'],
            'ssh_key' => ['nullable', Rule::in($this->sshKeys->pluck('uuid')->all())],
            'project' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if ($value !== null && ! $user->accessibleProjects()->where('uuid', $value)->exists()) {
                    $fail(__('Choose one of your projects.'));
                }
            }],
            'access.*' => ['required', Rule::in(['none', DatabaseConnection::READ, DatabaseConnection::WRITE])],
            'group' => ['nullable', Rule::in($this->groups->pluck('uuid')->all())],
        ];
    }

    /** @return array<string, string> */
    private function connectionMessages(): array
    {
        return [
            'host.regex' => __('A host name or an IP address, without user or port.'),
            'database.regex' => __('The full path of the file: /var/www/app/database/database.sqlite, or ~/… under the SSH user’s home.'),
            'ssh.required_if' => __('Choose the SSH connection the tunnel goes through.'),
            'ssh_host.required_if' => __('The SSH host is needed.'),
            'ssh_username.required_if' => __('The SSH user is needed.'),
        ];
    }

    public function saveConnection(): void
    {
        abort_unless($this->canCreate(), 403);
        $existing = $this->editing ? $this->own($this->editing) : null;
        $this->validate($this->connectionRules($existing), $this->connectionMessages());

        if ($this->via === 'new' && $this->ssh_password === '' && $this->ssh_key === null) {
            $this->addError('ssh_password', __('The SSH connection needs a password or one of your keys.'));

            return;
        }

        $user = auth()->user();
        $sshId = match ($this->via) {
            'ssh' => $this->sshConnections->firstWhere('uuid', $this->ssh)?->id,
            'new' => $this->createSshConnection()->id,
            default => null,
        };

        $attributes = $this->attributes($existing, $sshId);

        if ($existing !== null) {
            $existing->update($attributes);
            $connection = $existing;
        } else {
            $connection = $user->databaseConnections()->create($attributes);
        }

        $this->placeIn($connection, $this->group);
        unset($this->connections);
        Flux::modal('database-connection')->close();
        Flux::toast(variant: 'success', text: __('Connection :name saved.', ['name' => $connection->name]));
    }

    /** @return array<string, mixed> */
    private function attributes(?DatabaseConnection $existing, ?int $sshId): array
    {
        $driver = Driver::from($this->driver);
        $project = $this->project !== null ? Project::query()->where('uuid', $this->project)->value('id') : null;
        $password = match (true) {
            $this->password !== '' => $this->password,
            $this->password_clear => null,
            default => $existing?->password,
        };

        return [
            'name' => trim($this->name),
            'driver' => $driver,
            'environment' => Environment::from($this->environment),
            'host' => $driver === Driver::Sqlite ? null : trim($this->host, '[] '),
            'port' => $driver === Driver::Sqlite || blank($this->port) ? null : (int) $this->port,
            'username' => $driver === Driver::Sqlite || trim($this->username) === '' ? null : trim($this->username),
            'password' => $driver === Driver::Sqlite ? null : $password,
            'database' => trim($this->database) === '' ? null : trim($this->database),
            'tls' => $driver !== Driver::Sqlite && $sshId === null && $this->tls,
            'ssh_connection_id' => $sshId,
            'tunnel_as' => $this->tunnel_as,
            'project_id' => $project,
            'project_access' => $project === null ? null : collect($this->access)->only(DatabaseConnection::ROLES)->filter(fn ($v) => $v !== 'none')->all(),
        ];
    }

    /** The SSH connection a pasted link brought, the person's own, with the host key the test accepted. */
    private function createSshConnection(): SshConnection
    {
        $user = auth()->user();
        $name = trim($this->name).' SSH';
        $taken = $user->sshConnections()->pluck('name')->all();
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = mb_substr(trim($this->name), 0, 50).' SSH '.$i;
        }

        $connection = $user->sshConnections()->create($this->draftSsh($name)->only(['name', 'host', 'port', 'username', 'auth', 'ssh_key_id', 'password', 'host_key']));
        $this->via = 'ssh';
        $this->ssh = $connection->uuid;
        unset($this->sshConnections);

        return $connection;
    }

    private function draftSsh(?string $name = null): SshConnection
    {
        $user = auth()->user();
        $key = $this->ssh_password === '' && $this->ssh_key !== null ? $this->sshKeys->firstWhere('uuid', $this->ssh_key) : null;
        $ssh = new SshConnection([
            'name' => $name ?? trim($this->name).' SSH',
            'host' => trim($this->ssh_host, '[] '),
            'port' => (int) $this->ssh_port,
            'username' => trim($this->ssh_username),
            'auth' => $key !== null ? 'key' : 'password',
            'ssh_key_id' => $key?->id,
            'password' => $key !== null ? null : $this->ssh_password,
            'host_key' => $this->ssh_host_key,
        ]);
        $ssh->user_id = $user->id;
        $ssh->setRelation('user', $user);
        $ssh->setRelation('key', $key);

        return $ssh;
    }

    /**
     * Try the form as it is, saved or not: the server's version, or why it does not answer.
     * A tunnel's host whose key is not accepted yet shows the keys to accept first.
     */
    public function testConnection(Connector $connector): void
    {
        abort_unless($this->canCreate(), 403);
        $existing = $this->editing ? $this->own($this->editing) : null;
        $this->validate(collect($this->connectionRules($existing))->except(['name'])->all(), $this->connectionMessages());
        $this->fingerprints = [];

        $connection = new DatabaseConnection($this->attributes($existing, null));
        $connection->user_id = auth()->user()->id;
        $connection->setRelation('user', auth()->user());

        $ssh = match ($this->via) {
            'ssh' => $this->sshConnections->firstWhere('uuid', $this->ssh),
            'new' => $this->draftSsh(),
            default => null,
        };

        if ($ssh !== null) {
            $connection->ssh_connection_id = $ssh->id ?? 0;
            $connection->setRelation('sshConnection', $ssh);

            if (blank($ssh->host_key)) {
                try {
                    $this->fingerprints = $connector->tunnels()->scan($ssh);
                    $this->testOk = false;
                    $this->testResult = __(':host is new: check its key below and accept it to go on.', ['host' => $ssh->host]);
                } catch (Throwable $e) {
                    $this->testOk = false;
                    $this->testResult = $e->getMessage();
                }

                return;
            }
        }

        try {
            $this->testResult = $this->probe($connector, $connection);
            $this->testOk = true;
        } catch (Throwable $e) {
            $this->testOk = false;
            $this->testResult = DatabaseException::from($e)->getMessage();
        }
    }

    private function probe(Connector $connector, DatabaseConnection $connection): string
    {
        $user = auth()->user();

        if ($connection->driver->isRedis()) {
            $client = $connector->redis($connection, $user);

            try {
                $info = (new Keyspace($client))->info()['server'] ?? [];
                $keys = (new Keyspace($client))->databases();
            } finally {
                $client->close();
            }

            $product = isset($info['valkey_version']) ? 'Valkey '.$info['valkey_version'] : 'Redis '.($info['redis_version'] ?? '?');

            return __(':product · :count keys', ['product' => $product, 'count' => number_format(array_sum($keys))]);
        }

        $link = $connector->sql($connection, $user);

        try {
            $version = match ($connection->driver->family()) {
                'mysql' => 'MySQL/MariaDB '.$link->select('SELECT VERSION()')->value(),
                'pgsql' => (string) $link->select('SHOW server_version')->value(),
                default => 'SQLite '.$link->select('SELECT sqlite_version()')->value(),
            };
            $tables = count(Dialect::for($link, $connection->database ?: null)->objects());
        } finally {
            $link->close();
        }

        return $connection->driver->family() === 'pgsql'
            ? 'PostgreSQL '.$version.' · '.trans_choice(':count table in public|:count tables in public', $tables, ['count' => $tables])
            : $version.(filled($connection->database) || $connection->driver === Driver::Sqlite ? ' · '.trans_choice(':count table|:count tables', $tables, ['count' => $tables]) : '');
    }

    /** The person checked the key the tunnel's host showed: it is pinned, and the test goes on. */
    public function acceptHostKey(string $fingerprint, Connector $connector): void
    {
        if (! in_array($fingerprint, $this->fingerprints, true)) {
            return;
        }

        if ($this->via === 'new') {
            $this->ssh_host_key = $fingerprint;
        } else {
            $ssh = $this->sshConnections->firstWhere('uuid', $this->ssh);
            if ($ssh !== null && blank($ssh->host_key)) {
                SshConnection::query()->whereKey($ssh->id)->update(['host_key' => $fingerprint]);
                Log::info('SSH host key accepted from Databases', ['user' => auth()->user()->email, 'connection' => $ssh->name, 'fingerprint' => $fingerprint]);
                unset($this->sshConnections);
            }
        }

        $this->testConnection($connector);
    }

    public function deleteConnection(string $id): void
    {
        $connection = $this->own($id);
        $connection->delete();
        unset($this->connections);

        Flux::toast(text: __('Connection :name removed.', ['name' => $connection->name]));
    }

    /** The link of a connection, for the clipboard: with the passwords only for its owner. */
    public function copyLink(string $id, bool $secrets = false): string
    {
        $connection = $this->visible($id);

        return ConnectionString::build($connection, $secrets && $connection->isOwnedBy(auth()->user()));
    }

    /* ------------------------------------------------------------------ groups and stars */

    public function toggleFavorite(string $id): void
    {
        $connection = $this->visible($id);
        $preference = DatabasePreference::query()->firstOrNew(['user_id' => auth()->id(), 'database_connection_id' => $connection->id]);
        $preference->favorite = ! $preference->favorite;
        $preference->save();
        unset($this->connections);
    }

    public function moveToGroup(string $id, string $group = ''): void
    {
        $this->placeIn($this->visible($id), $group !== '' ? $group : null);
        unset($this->connections);
    }

    private function placeIn(DatabaseConnection $connection, ?string $group): void
    {
        DatabasePreference::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'database_connection_id' => $connection->id],
            ['database_group_id' => $group !== null ? $this->groups->firstWhere('uuid', $group)?->id : null],
        );
    }

    public function newGroup(): void
    {
        $this->reset(['groupEditing', 'group_name']);
        $this->group_color = DatabaseGroup::COLORS[$this->groups->count() % count(DatabaseGroup::COLORS)];
        $this->resetValidation();

        Flux::modal('database-group')->show();
    }

    public function editGroup(string $id): void
    {
        $group = $this->ownGroup($id);
        $this->groupEditing = $group->uuid;
        $this->group_name = $group->name;
        $this->group_color = $group->color;
        $this->resetValidation();

        Flux::modal('database-group')->show();
    }

    public function saveGroup(): void
    {
        $user = auth()->user();
        $group = $this->groupEditing ? $this->ownGroup($this->groupEditing) : null;

        $this->validate([
            'group_name' => ['required', 'string', 'max:60', Rule::unique('database_groups', 'name')->where('user_id', $user->id)->ignore($group?->id)],
            'group_color' => ['required', Rule::in(DatabaseGroup::COLORS)],
        ]);

        if ($group) {
            $group->update(['name' => trim($this->group_name), 'color' => $this->group_color]);
        } else {
            $user->databaseGroups()->create(['name' => trim($this->group_name), 'color' => $this->group_color, 'position' => (int) $user->databaseGroups()->max('position') + 1]);
        }

        unset($this->groups, $this->connections);
        Flux::modal('database-group')->close();
        Flux::toast(variant: 'success', text: __('Group saved.'));
    }

    public function deleteGroup(string $id): void
    {
        $group = $this->ownGroup($id);
        $group->delete();
        unset($this->groups, $this->connections);

        Flux::toast(text: __('Group :group deleted: its connections are back in the list.', ['group' => $group->name]));
    }

    public function moveGroup(string $id, int $step): void
    {
        $groups = $this->groups->values();
        $index = $groups->search(fn (DatabaseGroup $g) => $g->uuid === $id);

        if ($index === false || ! $groups->has($index + $step)) {
            return;
        }

        $order = $groups->all();
        [$order[$index], $order[$index + $step]] = [$order[$index + $step], $order[$index]];

        foreach (array_values($order) as $position => $group) {
            $group->forceFill(['position' => $position + 1])->save();
        }

        unset($this->groups);
    }

    /* ------------------------------------------------------------------ helpers */

    private function own(string $id): DatabaseConnection
    {
        return auth()->user()->databaseConnections()->with(['sshConnection', 'project'])->where('uuid', $id)->firstOrFail();
    }

    private function visible(string $id): DatabaseConnection
    {
        $connection = DatabaseConnection::query()->visibleTo(auth()->user())->with(['sshConnection', 'project', 'user'])->where('uuid', $id)->firstOrFail();
        abort_if($connection->accessFor(auth()->user()) === null, 404);

        return $connection;
    }

    private function ownGroup(string $id): DatabaseGroup
    {
        return auth()->user()->databaseGroups()->where('uuid', $id)->firstOrFail();
    }

    private function preference(DatabaseConnection $connection): ?DatabasePreference
    {
        return auth()->user()->databasePreferences()->with('group')->where('database_connection_id', $connection->id)->first();
    }

    /**
     * Who reaches the connection being edited through its project, with what.
     *
     * @return BaseCollection<int, array{user: User, role: UserRole|null, level: string}>
     */
    private function audience(): BaseCollection
    {
        $project = $this->project !== null ? Project::query()->where('uuid', $this->project)->first() : null;

        if ($project === null) {
            return collect();
        }

        return User::query()->whereKeyNot(auth()->id())
            ->where(fn ($q) => $q->where('role', 'admin')->orWhereHas('projects', fn ($p) => $p->whereKey($project->id)))
            ->orderBy('name')->get()
            ->map(function (User $user) use ($project) {
                $role = $user->roleIn($project);
                $level = $user->isAdmin() ? DatabaseConnection::WRITE : ($role !== null ? ($this->access[$role->value] ?? 'none') : 'none');

                return ['user' => $user, 'role' => $role, 'level' => $level];
            });
    }

    public function render(): View
    {
        $user = auth()->user();
        $needle = mb_strtolower(trim($this->search));
        $rows = $this->connections->filter(fn (array $row) => $needle === '' || str_contains(mb_strtolower($row['connection']->name.' '.$row['connection']->address().' '.$row['connection']->driver->label().' '.$row['connection']->project?->name.' '.$row['group']?->name.' '.$row['connection']->environment->value), $needle));

        $sections = collect();
        if (($favorites = $rows->where('favorite', true))->isNotEmpty()) {
            $sections->push(['key' => 'favorites', 'group' => null, 'title' => __('Favourites'), 'rows' => $favorites->values()]);
        }
        foreach ($this->groups as $group) {
            $sections->push(['key' => 'group-'.$group->uuid, 'group' => $group, 'title' => $group->name, 'rows' => $rows->filter(fn (array $r) => ! $r['favorite'] && $r['group']?->id === $group->id)->values()]);
        }
        $loose = $rows->filter(fn (array $r) => ! $r['favorite'] && $r['group'] === null);
        if (($mine = $loose->where('mine', true))->isNotEmpty() || $this->groups->isEmpty()) {
            $sections->push(['key' => 'mine', 'group' => null, 'title' => $this->groups->isEmpty() ? __('My connections') : __('Not in a group'), 'rows' => $mine->values()]);
        }
        if (($shared = $loose->where('mine', false))->isNotEmpty()) {
            $sections->push(['key' => 'shared', 'group' => null, 'title' => __('Shared with me'), 'rows' => $shared->values()]);
        }

        $sshPicked = $this->via === 'ssh' ? $this->sshConnections->firstWhere('uuid', $this->ssh) : null;

        return view('livewire.tools.databases', [
            'sections' => $sections,
            'total' => $this->connections->count(),
            'driverEnum' => Driver::tryFrom($this->driver) ?? Driver::Mysql,
            'pickedProject' => $this->project ? Project::query()->where('uuid', $this->project)->first() : null,
            'audience' => $this->audience(),
            'sshPicked' => $sshPicked,
            'hasPassword' => $this->editing !== null && filled($user->databaseConnections()->where('uuid', $this->editing)->first()?->password),
        ]);
    }
}
