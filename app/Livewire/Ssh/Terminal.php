<?php

namespace App\Livewire\Ssh;

use App\Models\SshConnection;
use App\Models\SshKey;
use App\Ssh\Gateway;
use App\Ssh\Keys;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The SSH page: a person's saved hosts (favourites first) and keys on the
 * left, any number of terminals on the right. Livewire owns connections and
 * keys; the terminals live in the browser (xterm.js) and talk to the SSH
 * gateway with a one-time ticket from open().
 */
#[Layout('layouts::app')]
#[Title('SSH')]
class Terminal extends Component
{
    public ?int $editing = null;

    public string $connection_name = '';

    public string $connection_host = '';

    public int|string $connection_port = 22;

    public string $connection_username = '';

    public string $connection_auth = 'key';

    public int|string|null $connection_key = null;

    public string $connection_password = '';

    public bool $connection_favorite = false;

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

    /** @return Collection<int, SshConnection> */
    #[Computed]
    public function connections(): Collection
    {
        return auth()->user()->sshConnections()->with('key')->orderByDesc('favorite')->orderBy('name')->get();
    }

    /** @return Collection<int, SshKey> */
    #[Computed]
    public function keys(): Collection
    {
        return auth()->user()->sshKeys()->withCount('connections')->orderBy('name')->get();
    }

    public function newConnection(): void
    {
        $this->reset(['editing', 'connection_name', 'connection_host', 'connection_port', 'connection_username', 'connection_auth', 'connection_key', 'connection_password', 'connection_favorite']);
        $keys = $this->keys();
        $this->connection_key = $keys->first()?->id;
        $this->connection_auth = $keys->isEmpty() ? 'password' : 'key';
        $this->resetValidation();

        Flux::modal('ssh-connection')->show();
    }

    public function editConnection(int $id): void
    {
        $connection = $this->connection($id);

        $this->editing = $connection->id;
        $this->connection_name = $connection->name;
        $this->connection_host = $connection->host;
        $this->connection_port = $connection->port;
        $this->connection_username = $connection->username;
        $this->connection_auth = $connection->auth;
        $this->connection_key = $connection->ssh_key_id;
        $this->connection_password = '';
        $this->connection_favorite = $connection->favorite;
        $this->resetValidation();

        Flux::modal('ssh-connection')->show();
    }

    public function saveConnection(): void
    {
        $existing = $this->editing ? $this->connection($this->editing) : null;
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
        $this->connection($id)->delete();
        unset($this->connections);

        Flux::toast(text: __('Connection removed.'));
    }

    public function toggleFavorite(int $id): void
    {
        $connection = $this->connection($id);
        $connection->update(['favorite' => ! $connection->favorite]);
        unset($this->connections);
    }

    public function forgetHostKey(int $id): void
    {
        $this->connection($id)->update(['host_key' => null]);
        unset($this->connections);

        Flux::toast(text: __('Host key forgotten: the next connection asks to accept it again.'));
    }

    /**
     * A ticket for one terminal on this connection; the page opens the socket with it.
     *
     * @return array{ticket: string, url: string, name: string, address: string, connection_id: int}|null
     */
    public function open(int $id): ?array
    {
        $connection = $this->connection($id);

        if (! $connection->hasCredential()) {
            Flux::toast(variant: 'warning', text: __(':name has no key or password: edit it first.', ['name' => $connection->name]));

            return null;
        }

        return [
            'ticket' => Gateway::issue($connection),
            'url' => Gateway::url(),
            'name' => $connection->name,
            'address' => $connection->address(),
            'connection_id' => $connection->id,
        ];
    }

    /** The person accepted the key a new host showed: later connections expect the same one. */
    public function trustHostKey(int $id, string $fingerprint): void
    {
        if (! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint)) {
            return;
        }

        $connection = $this->connection($id);
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
        return view('livewire.ssh.terminal', [
            'shown' => $this->shownKey ? $this->keys()->firstWhere('id', $this->shownKey) : null,
        ]);
    }

    private function connection(int $id): SshConnection
    {
        return auth()->user()->sshConnections()->findOrFail($id);
    }

    private function key(int $id): SshKey
    {
        return auth()->user()->sshKeys()->findOrFail($id);
    }
}
