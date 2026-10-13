<?php

namespace App\Livewire\Ssh;

use App\Livewire\Concerns\SearchesDirectory;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshCredential;
use App\Models\SshKey;
use App\Models\User;
use App\Ssh\Gateway;
use App\Ssh\Keys;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The SSH page: a person's saved hosts (favourites first), the hosts shared
 * with the projects they work on, and their keys on the left; any number of
 * terminals on the right. Livewire owns connections and keys; the terminals
 * live in the browser (xterm.js) and talk to the SSH gateway with a one-time
 * ticket from open(). On a shared connection everybody uses their own key or
 * password: credentials are never shared.
 */
#[Layout('layouts::app')]
#[Title('SSH')]
class Terminal extends Component
{
    use SearchesDirectory;

    public ?int $editing = null;

    public string $connection_name = '';

    public string $connection_host = '';

    public int|string $connection_port = 22;

    public string $connection_username = '';

    public string $connection_auth = 'key';

    public int|string|null $connection_key = null;

    public string $connection_password = '';

    public bool $connection_favorite = false;

    public ?int $connection_project = null;

    /** The shared connection whose credential (of this person) the modal edits. */
    public ?int $credentialFor = null;

    public string $credential_auth = 'key';

    public int|string|null $credential_key = null;

    public string $credential_password = '';

    public string $key_name = '';

    public string $key_mode = 'generate';

    public string $key_type = 'ed25519';

    public string $key_private = '';

    public string $key_passphrase = '';

    /** The key whose public half the modal shows, to copy into authorized_keys. */
    public ?int $shownKey = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->canUseSsh(), 403);
    }

    /**
     * Own and shared connections, favourites (of this person) first.
     *
     * @return BaseCollection<int, array{connection: SshConnection, mine: bool, favorite: bool, credential: SshCredential|null, label: string|null}>
     */
    #[Computed]
    public function connections(): BaseCollection
    {
        $user = auth()->user();

        return SshConnection::query()
            ->visibleTo($user)
            ->with(['key', 'user', 'project', 'credentials' => fn ($q) => $q->where('user_id', $user->id)->with('key')])
            ->get()
            ->map(function (SshConnection $connection) use ($user) {
                $mine = $connection->isOwnedBy($user);
                $credential = $mine ? null : $connection->credentials->first();

                return [
                    'connection' => $connection,
                    'mine' => $mine,
                    'favorite' => $mine ? $connection->favorite : (bool) $credential?->favorite,
                    'credential' => $credential,
                    'label' => $connection->credentialLabel($user, $credential),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['favorite'] <=> $a['favorite'],
                fn (array $a, array $b) => strcasecmp($a['connection']->name, $b['connection']->name),
                fn (array $a, array $b) => $b['mine'] <=> $a['mine'],
            ])
            ->values();
    }

    /** @return Collection<int, SshKey> */
    #[Computed]
    public function keys(): Collection
    {
        return auth()->user()->sshKeys()->withCount('connections')->orderBy('name')->get();
    }

    /**
     * Who sees the connection being edited through its project: the project's
     * administrators and developers, and every administrator of Studio.
     *
     * @return BaseCollection<int, array{user: User, has_credential: bool}>
     */
    #[Computed]
    public function audience(): BaseCollection
    {
        if ($this->connection_project === null) {
            return collect();
        }

        $connection = $this->editing ? SshConnection::query()->find($this->editing) : null;
        $credentials = $connection ? $connection->credentials()->with('key')->get()->keyBy('user_id') : collect();

        return User::query()
            ->whereKeyNot(auth()->id())
            ->where(fn (Builder $q) => $q
                ->where('role', 'admin')
                ->orWhere(fn (Builder $q) => $q->where('role', 'dev')->whereHas('projects', fn (Builder $p) => $p->whereKey($this->connection_project))))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => ['user' => $user, 'has_credential' => (bool) $credentials->get($user->id)?->isUsable()]);
    }

    public function newConnection(): void
    {
        $this->reset(['editing', 'connection_name', 'connection_host', 'connection_port', 'connection_username', 'connection_auth', 'connection_key', 'connection_password', 'connection_favorite', 'connection_project']);
        $keys = $this->keys();
        $this->connection_key = $keys->first()?->id;
        $this->connection_auth = $keys->isEmpty() ? 'password' : 'key';
        $this->resetValidation();
        unset($this->audience);

        Flux::modal('ssh-connection')->show();
    }

    public function editConnection(int $id): void
    {
        $connection = $this->ownConnection($id);

        $this->editing = $connection->id;
        $this->connection_name = $connection->name;
        $this->connection_host = $connection->host;
        $this->connection_port = $connection->port;
        $this->connection_username = $connection->username;
        $this->connection_auth = $connection->auth;
        $this->connection_key = $connection->ssh_key_id;
        $this->connection_password = '';
        $this->connection_favorite = $connection->favorite;
        $this->connection_project = $connection->project_id;
        $this->resetValidation();
        unset($this->audience);

        Flux::modal('ssh-connection')->show();
    }

    /** The project picker of the connection form; null: personal only. */
    public function pickProject(?int $id): void
    {
        $this->connection_project = $id !== null && auth()->user()->accessibleProjects()->whereKey($id)->exists() ? $id : null;
        unset($this->audience);
    }

    public function saveConnection(): void
    {
        $existing = $this->editing ? $this->ownConnection($this->editing) : null;
        $user = auth()->user();

        $validated = $this->validate([
            'connection_name' => ['required', 'string', 'max:60', Rule::unique('ssh_connections', 'name')->where('user_id', $user->id)->ignore($existing?->id)],
            'connection_host' => ['required', 'string', 'max:255', 'regex:/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?|[0-9a-fA-F:]+)$/'],
            'connection_port' => ['required', 'integer', 'between:1,65535'],
            'connection_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._][A-Za-z0-9._@-]*$/'],
            'connection_auth' => ['required', Rule::in(['key', 'password'])],
            'connection_key' => ['required_if:connection_auth,key', 'nullable', Rule::exists('ssh_keys', 'id')->where('user_id', $user->id)],
            'connection_password' => [$this->connection_auth === 'password' && ! filled($existing?->password) ? 'required' : 'nullable', 'string', 'max:500'],
            'connection_favorite' => ['boolean'],
            'connection_project' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if ($value !== null && ! $user->accessibleProjects()->whereKey($value)->exists()) {
                    $fail(__('Choose one of your projects.'));
                }
            }],
        ], [
            'connection_host.regex' => __('A host name or an IP address, without user or port.'),
            'connection_key.required_if' => __('Choose a key, or create one first.'),
        ]);

        $host = trim($validated['connection_host'], '[]');
        $port = (int) $validated['connection_port'];
        $password = $validated['connection_auth'] === 'password'
            ? (filled($validated['connection_password']) ? $validated['connection_password'] : $existing?->password)
            : null;

        $attributes = [
            'name' => $validated['connection_name'],
            'host' => $host,
            'port' => $port,
            'username' => $validated['connection_username'],
            'auth' => $validated['connection_auth'],
            'ssh_key_id' => $validated['connection_auth'] === 'key' ? (int) $validated['connection_key'] : null,
            'password' => $password,
            'favorite' => (bool) $validated['connection_favorite'],
            'project_id' => $validated['connection_project'],
        ];

        if ($existing) {
            // Another machine answers at another address: its key is not the one accepted before.
            if ($existing->host !== $host || $existing->port !== $port) {
                $attributes['host_key'] = null;
            }
            $existing->update($attributes);
        } else {
            $user->sshConnections()->create($attributes);
        }

        unset($this->connections);
        Flux::modal('ssh-connection')->close();
        Flux::toast(variant: 'success', text: __('Connection saved.'));
    }

    public function deleteConnection(int $id): void
    {
        $this->ownConnection($id)->delete();
        unset($this->connections);

        Flux::toast(text: __('Connection removed.'));
    }

    /** Stars are personal: on a shared connection the star is this person's own. */
    public function toggleFavorite(int $id): void
    {
        $connection = $this->visibleConnection($id);

        if ($connection->isOwnedBy(auth()->user())) {
            $connection->update(['favorite' => ! $connection->favorite]);
        } else {
            $credential = $connection->credentials()->firstOrNew(['user_id' => auth()->id()]);
            $credential->favorite = ! $credential->favorite;
            $credential->save();
        }

        unset($this->connections);
    }

    public function forgetHostKey(int $id): void
    {
        $this->ownConnection($id)->update(['host_key' => null]);
        unset($this->connections);

        Flux::toast(text: __('Host key forgotten: the next connection asks to accept it again.'));
    }

    /** Your own key or password for a connection someone shared with the project. */
    public function editCredential(int $id): void
    {
        $connection = $this->visibleConnection($id);
        abort_if($connection->isOwnedBy(auth()->user()), 404);

        $credential = $connection->credentialOf(auth()->user());
        $keys = $this->keys();

        $this->credentialFor = $connection->id;
        $this->credential_auth = $credential->auth ?? ($keys->isEmpty() ? 'password' : 'key');
        $this->credential_key = $credential->ssh_key_id ?? $keys->first()?->id;
        $this->credential_password = '';
        $this->resetValidation();

        Flux::modal('ssh-credential')->show();
    }

    public function saveCredential(): void
    {
        $connection = $this->visibleConnection((int) $this->credentialFor);
        abort_if($connection->isOwnedBy(auth()->user()), 404);
        $existing = $connection->credentialOf(auth()->user());

        $validated = $this->validate([
            'credential_auth' => ['required', Rule::in(['key', 'password'])],
            'credential_key' => ['required_if:credential_auth,key', 'nullable', Rule::exists('ssh_keys', 'id')->where('user_id', auth()->id())],
            'credential_password' => [$this->credential_auth === 'password' && ! filled($existing?->password) ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'credential_key.required_if' => __('Choose a key, or create one first.'),
        ]);

        $connection->credentials()->updateOrCreate(['user_id' => auth()->id()], [
            'auth' => $validated['credential_auth'],
            'ssh_key_id' => $validated['credential_auth'] === 'key' ? (int) $validated['credential_key'] : null,
            'password' => $validated['credential_auth'] === 'password'
                ? (filled($validated['credential_password']) ? $validated['credential_password'] : $existing?->password)
                : null,
        ]);

        unset($this->connections);
        Flux::modal('ssh-credential')->close();
        Flux::toast(variant: 'success', text: __('Your credential for :name is saved.', ['name' => $connection->name]));
    }

    /**
     * A ticket for one terminal on this connection, with this person's credential.
     * Without one, the page opens the form for it instead.
     *
     * @return array{ticket: string, url: string, name: string, address: string, connection_id: int}|null
     */
    public function open(int $id): ?array
    {
        $user = auth()->user();
        $connection = $this->visibleConnection($id);

        if (! $connection->canConnect($user)) {
            if ($connection->isOwnedBy($user)) {
                Flux::toast(variant: 'warning', text: __(':name has no key or password: edit it first.', ['name' => $connection->name]));
            } else {
                $this->editCredential($connection->id);
            }

            return null;
        }

        return [
            'ticket' => Gateway::issue($connection, $user),
            'url' => Gateway::url(),
            'name' => $connection->name,
            'address' => $connection->address(),
            'connection_id' => $connection->id,
        ];
    }

    /** The person accepted the key a new host showed: later connections, of anybody, expect the same one. */
    public function trustHostKey(int $id, string $fingerprint): void
    {
        if (! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint)) {
            return;
        }

        $connection = $this->visibleConnection($id);
        if ($connection->host_key === null) {
            $connection->update(['host_key' => $fingerprint]);
            unset($this->connections);
        }
    }

    public function newKey(): void
    {
        $this->reset(['key_name', 'key_mode', 'key_type', 'key_private', 'key_passphrase']);
        $this->resetValidation();

        Flux::modal('ssh-key')->show();
    }

    public function saveKey(): void
    {
        $user = auth()->user();

        $this->validate([
            'key_name' => ['required', 'string', 'max:60', Rule::unique('ssh_keys', 'name')->where('user_id', $user->id)],
            'key_mode' => ['required', Rule::in(['generate', 'import'])],
            'key_type' => ['required_if:key_mode,generate', Rule::in(Keys::TYPES)],
            'key_private' => ['required_if:key_mode,import', 'nullable', 'string', 'max:20000'],
            'key_passphrase' => ['nullable', 'string', 'max:500'],
        ], [
            'key_private.required_if' => __('Paste the private key.'),
        ]);

        $comment = ($user->handle ?: 'studio').'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        try {
            $material = $this->key_mode === 'generate'
                ? Keys::generate($this->key_type, $comment)
                : Keys::import($this->key_private, $this->key_passphrase, $comment);
        } catch (InvalidArgumentException $e) {
            $this->addError('key_private', $e->getMessage());

            return;
        }

        $key = $user->sshKeys()->create(['name' => $this->key_name, ...$material]);

        $this->reset(['key_private', 'key_passphrase']);
        unset($this->keys);
        Flux::modal('ssh-key')->close();
        $this->showKey($key->id);
    }

    public function showKey(int $id): void
    {
        $this->shownKey = $this->key($id)->id;

        Flux::modal('ssh-public-key')->show();
    }

    public function deleteKey(int $id): void
    {
        $this->key($id)->delete();
        unset($this->keys, $this->connections);

        Flux::toast(text: __('Key removed. The connections that used it need another one.'));
    }

    public function render(): View
    {
        $credentialConnection = $this->credentialFor ? SshConnection::query()->visibleTo(auth()->user())->with('user')->find($this->credentialFor) : null;

        return view('livewire.ssh.terminal', [
            'shown' => $this->shownKey ? $this->keys()->firstWhere('id', $this->shownKey) : null,
            'pickedProject' => $this->connection_project ? Project::query()->find($this->connection_project) : null,
            'credentialConnection' => $credentialConnection,
        ]);
    }

    private function ownConnection(int $id): SshConnection
    {
        return auth()->user()->sshConnections()->findOrFail($id);
    }

    private function visibleConnection(int $id): SshConnection
    {
        return SshConnection::query()->visibleTo(auth()->user())->with('key')->findOrFail($id);
    }

    private function key(int $id): SshKey
    {
        return auth()->user()->sshKeys()->findOrFail($id);
    }
}
