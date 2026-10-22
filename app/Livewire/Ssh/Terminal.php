<?php

namespace App\Livewire\Ssh;

use App\Enums\UserRole;
use App\Livewire\Concerns\CreatesSshKeys;
use App\Livewire\Concerns\SearchesDirectory;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshCredential;
use App\Models\SshGroup;
use App\Models\SshKey;
use App\Models\SshPreference;
use App\Models\User;
use App\Ssh\Gateway;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The SSH page: a person's saved hosts (favourites first), the hosts shared
 * with the projects they work on, in groups of their own with colours (as the
 * databases'), with a search and a group filter; their keys and the system keys shared with
 * them (managed in System) on the left; any number of terminals on the right,
 * each of which can turn into the file manager of its host (Files, over SFTP).
 * Livewire owns connections and keys; the terminals
 * live in the browser (xterm.js) and talk to the SSH gateway with a one-time
 * ticket from open(). On a shared connection everybody uses their own key or
 * password: credentials are never shared.
 */
#[Layout('layouts::app')]
#[Title('SSH')]
class Terminal extends Component
{
    use CreatesSshKeys;
    use SearchesDirectory;

    /** The uuid of the connection in the form. */
    public ?string $editing = null;

    public string $connection_name = '';

    public string $connection_host = '';

    public int|string $connection_port = 22;

    public string $connection_username = '';

    public string $connection_auth = 'key';

    /** uuid of the key */
    public ?string $connection_key = null;

    public string $connection_password = '';

    public bool $connection_favorite = false;

    /** uuid of the project, or null: personal */
    public ?string $connection_project = null;

    /** uuid of the person's group the connection sits in, or null */
    public ?string $connection_group = null;

    // ---- A group in its dialog
    public ?string $groupEditing = null;

    public string $group_name = '';

    public string $group_color = 'zinc';

    /** The shared connection whose credential (of this person) the modal edits. */
    public ?string $credentialFor = null;

    public string $credential_auth = 'key';

    /** uuid of the key */
    public ?string $credential_key = null;

    public string $credential_password = '';

    /** @var list<string> uuids of the connections whose file manager is open next to their terminals */
    #[Locked]
    public array $browsing = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->canUseSsh(), 403);
    }

    /**
     * Own and shared connections, favourites (of this person) first, each with the group it sits in for them.
     *
     * @return BaseCollection<int, array{connection: SshConnection, mine: bool, favorite: bool, credential: SshCredential|null, label: string|null, group: SshGroup|null}>
     */
    #[Computed]
    public function connections(): BaseCollection
    {
        $user = auth()->user();
        $preferences = $user->sshPreferences()->with('group')->get()->keyBy('ssh_connection_id');

        return SshConnection::query()
            ->visibleTo($user)
            ->with(['key', 'user', 'project', 'credentials' => fn ($q) => $q->where('user_id', $user->id)->with('key')])
            ->get()
            ->map(function (SshConnection $connection) use ($user, $preferences) {
                $mine = $connection->isOwnedBy($user);
                $credential = $mine ? null : $connection->credentials->first();

                return [
                    'connection' => $connection,
                    'mine' => $mine,
                    'favorite' => $mine ? $connection->favorite : (bool) $credential?->favorite,
                    'credential' => $credential,
                    'label' => $connection->credentialLabel($user, $credential),
                    'group' => $preferences->get($connection->id)?->group,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['favorite'] <=> $a['favorite'],
                fn (array $a, array $b) => strcasecmp($a['connection']->name, $b['connection']->name),
                fn (array $a, array $b) => $b['mine'] <=> $a['mine'],
            ])
            ->values();
    }

    /** @return Collection<int, SshGroup> this person's groups, in their order */
    #[Computed]
    public function groups(): Collection
    {
        return auth()->user()->sshGroups()->get();
    }

    /** @return Collection<int, SshKey> */
    #[Computed]
    public function keys(): Collection
    {
        return auth()->user()->sshKeys()->withCount('connections')->orderBy('name')->get();
    }

    /**
     * The installation's keys this person may use, with the projects that
     * give them: administrators manage them in System.
     *
     * @return Collection<int, SshKey>
     */
    #[Computed]
    public function systemKeys(): Collection
    {
        $user = auth()->user();

        return SshKey::query()
            ->sharedWith($user)
            ->with(['projects' => fn ($q) => $user->isAdmin()
                ? $q->orderBy('name')
                : $q->wherePivot('developers', true)->whereIn('projects.id', $user->projects()->select('projects.id'))->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Who sees the connection being edited through its project: the project's
     * administrators and developers, and every administrator of Studio.
     *
     * @return BaseCollection<int, array{user: User, role: UserRole|null, has_credential: bool}>
     */
    #[Computed]
    public function audience(): BaseCollection
    {
        if ($this->connection_project === null) {
            return collect();
        }

        $connection = $this->editing ? SshConnection::query()->where('uuid', $this->editing)->first() : null;
        $credentials = $connection ? $connection->credentials()->with('key')->get()->keyBy('user_id') : collect();

        $project = Project::query()->where('uuid', $this->connection_project)->first();

        return User::query()
            ->whereKeyNot(auth()->id())
            ->where(fn (Builder $q) => $q
                ->where('role', 'admin')
                ->orWhere(fn (Builder $q) => $q->withRoleIn((int) $project?->id, ['dev'])))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => ['user' => $user, 'role' => $project ? $user->roleIn($project) : $user->role, 'has_credential' => (bool) $credentials->get($user->id)?->isUsable()]);
    }

    public function newConnection(?string $group = null): void
    {
        $this->reset(['editing', 'connection_name', 'connection_host', 'connection_port', 'connection_username', 'connection_auth', 'connection_key', 'connection_password', 'connection_favorite', 'connection_project', 'connection_group']);
        $this->connection_group = $group !== null && $this->groups()->firstWhere('uuid', $group) ? $group : null;
        $keys = $this->usableKeys(null);
        $this->connection_key = $keys->first()?->uuid;
        $this->connection_auth = $keys->isEmpty() ? 'password' : 'key';
        $this->resetValidation();
        unset($this->audience);

        Flux::modal('ssh-connection')->show();
    }

    public function editConnection(string $id): void
    {
        $connection = $this->ownConnection($id);

        $this->editing = $connection->uuid;
        $this->connection_name = $connection->name;
        $this->connection_host = $connection->host;
        $this->connection_port = $connection->port;
        $this->connection_username = $connection->username;
        $this->connection_auth = $connection->auth;
        $this->connection_key = $connection->key?->uuid;
        $this->connection_password = '';
        $this->connection_favorite = $connection->favorite;
        $this->connection_project = $connection->project?->uuid;
        $this->connection_group = $this->preference($connection)?->group?->uuid;
        $this->resetValidation();
        unset($this->audience);

        Flux::modal('ssh-connection')->show();
    }

    /** The project picker of the connection form; null: personal only. */
    public function pickProject(?string $id): void
    {
        $this->connection_project = $id !== null && auth()->user()->accessibleProjects()->where('uuid', $id)->exists() ? $id : null;
        unset($this->audience);
    }

    public function saveConnection(): void
    {
        $existing = $this->editing ? $this->ownConnection($this->editing) : null;
        $user = auth()->user();
        $projectId = $this->connection_project !== null ? Project::query()->where('uuid', $this->connection_project)->value('id') : null;

        $validated = $this->validate([
            'connection_name' => ['required', 'string', 'max:60', Rule::unique('ssh_connections', 'name')->where('user_id', $user->id)->ignore($existing?->id)],
            'connection_host' => ['required', 'string', 'max:255', 'regex:/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?|[0-9a-fA-F:]+)$/'],
            'connection_port' => ['required', 'integer', 'between:1,65535'],
            'connection_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._][A-Za-z0-9._@-]*$/'],
            'connection_auth' => ['required', Rule::in(['key', 'password'])],
            'connection_key' => ['required_if:connection_auth,key', 'nullable', 'string', $this->usableKeyRule($projectId)],
            'connection_password' => [$this->connection_auth === 'password' && ! filled($existing?->password) ? 'required' : 'nullable', 'string', 'max:500'],
            'connection_favorite' => ['boolean'],
            'connection_group' => ['nullable', Rule::in($this->groups()->pluck('uuid')->all())],
            'connection_project' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if ($value !== null && ! $user->accessibleProjects()->where('uuid', $value)->exists()) {
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
            'ssh_key_id' => $validated['connection_auth'] === 'key' ? SshKey::query()->usableBy($user, $projectId)->where('uuid', $validated['connection_key'])->value('id') : null,
            'password' => $password,
            'favorite' => (bool) $validated['connection_favorite'],
            'project_id' => $projectId,
        ];

        if ($existing) {
            // Another machine answers at another address: its key is not the one accepted before.
            if ($existing->host !== $host || $existing->port !== $port) {
                $attributes['host_key'] = null;
            }
            $existing->update($attributes);
        } else {
            $existing = $user->sshConnections()->create($attributes);
        }
        $this->placeIn($existing, $validated['connection_group'] ?? null);

        unset($this->connections);
        Flux::modal('ssh-connection')->close();
        Flux::toast(variant: 'success', text: __('Connection saved.'));
    }

    public function deleteConnection(string $id): void
    {
        $this->ownConnection($id)->delete();
        unset($this->connections);

        Flux::toast(text: __('Connection removed.'));
    }

    /** Stars are personal: on a shared connection the star is this person's own. */
    public function toggleFavorite(string $id): void
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

    /* ------------------------------------------------------------------ groups */

    /** Groups are each person's own: a shared connection goes in a group of theirs as well. */
    public function moveToGroup(string $id, string $group = ''): void
    {
        $this->placeIn($this->visibleConnection($id), $group !== '' ? $group : null);
        unset($this->connections);
    }

    public function newGroup(): void
    {
        $this->reset(['groupEditing', 'group_name']);
        $this->group_color = SshGroup::COLORS[$this->groups()->count() % count(SshGroup::COLORS)];
        $this->resetValidation();

        Flux::modal('ssh-group')->show();
    }

    public function editGroup(string $id): void
    {
        $group = $this->ownGroup($id);
        $this->groupEditing = $group->uuid;
        $this->group_name = $group->name;
        $this->group_color = $group->color;
        $this->resetValidation();

        Flux::modal('ssh-group')->show();
    }

    public function saveGroup(): void
    {
        $user = auth()->user();
        $group = $this->groupEditing ? $this->ownGroup($this->groupEditing) : null;

        $this->validate([
            'group_name' => ['required', 'string', 'max:60', Rule::unique('ssh_groups', 'name')->where('user_id', $user->id)->ignore($group?->id)],
            'group_color' => ['required', Rule::in(SshGroup::COLORS)],
        ]);

        if ($group) {
            $group->update(['name' => trim($this->group_name), 'color' => $this->group_color]);
        } else {
            $user->sshGroups()->create(['name' => trim($this->group_name), 'color' => $this->group_color, 'position' => (int) $user->sshGroups()->max('position') + 1]);
        }

        unset($this->groups, $this->connections);
        Flux::modal('ssh-group')->close();
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
        $groups = $this->groups()->values();
        $index = $groups->search(fn (SshGroup $g) => $g->uuid === $id);

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

    private function placeIn(SshConnection $connection, ?string $group): void
    {
        SshPreference::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'ssh_connection_id' => $connection->id],
            ['ssh_group_id' => $group !== null ? $this->groups()->firstWhere('uuid', $group)?->id : null],
        );
    }

    private function preference(SshConnection $connection): ?SshPreference
    {
        return SshPreference::query()->with('group')->where('user_id', auth()->id())->where('ssh_connection_id', $connection->id)->first();
    }

    private function ownGroup(string $id): SshGroup
    {
        return auth()->user()->sshGroups()->where('uuid', $id)->firstOrFail();
    }

    public function forgetHostKey(string $id): void
    {
        $this->ownConnection($id)->update(['host_key' => null]);
        unset($this->connections);

        Flux::toast(text: __('Host key forgotten: the next connection asks to accept it again.'));
    }

    /** Your own key or password for a connection someone shared with the project. */
    public function editCredential(string $id): void
    {
        $connection = $this->visibleConnection($id);
        abort_if($connection->isOwnedBy(auth()->user()), 404);

        $credential = $connection->credentialOf(auth()->user());
        $keys = $this->usableKeys($connection->project_id);

        $this->credentialFor = $connection->uuid;
        $this->credential_auth = $credential->auth ?? ($keys->isEmpty() ? 'password' : 'key');
        $this->credential_key = $credential?->key->uuid ?? $keys->first()?->uuid;
        $this->credential_password = '';
        $this->resetValidation();

        Flux::modal('ssh-credential')->show();
    }

    public function saveCredential(): void
    {
        $connection = $this->visibleConnection((string) $this->credentialFor);
        abort_if($connection->isOwnedBy(auth()->user()), 404);
        $existing = $connection->credentialOf(auth()->user());

        $validated = $this->validate([
            'credential_auth' => ['required', Rule::in(['key', 'password'])],
            'credential_key' => ['required_if:credential_auth,key', 'nullable', 'string', $this->usableKeyRule($connection->project_id)],
            'credential_password' => [$this->credential_auth === 'password' && ! filled($existing?->password) ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'credential_key.required_if' => __('Choose a key, or create one first.'),
        ]);

        $connection->credentials()->updateOrCreate(['user_id' => auth()->id()], [
            'auth' => $validated['credential_auth'],
            'ssh_key_id' => $validated['credential_auth'] === 'key' ? SshKey::query()->usableBy(auth()->user(), $connection->project_id)->where('uuid', $validated['credential_key'])->value('id') : null,
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
     * @return array{ticket: string, url: string, name: string, address: string, connection_id: string}|null
     */
    public function open(string $id): ?array
    {
        $user = auth()->user();
        $connection = $this->visibleConnection($id);

        if (! $connection->canConnect($user)) {
            if ($connection->isOwnedBy($user)) {
                Flux::toast(variant: 'warning', text: __(':name has no key or password: edit it first.', ['name' => $connection->name]));
            } else {
                $this->editCredential($connection->uuid);
            }

            return null;
        }

        return [
            'ticket' => Gateway::issue($connection, $user),
            'url' => Gateway::url(),
            'name' => $connection->name,
            'address' => $connection->address(),
            'connection_id' => $connection->uuid,
        ];
    }

    /**
     * A ticket to take back a session the page left (another page of Studio, a reload): the
     * gateway gives the shell back to its own person only. Nothing is said when the connection
     * is gone or no longer theirs: the page forgets that session.
     *
     * @return array{ticket: string, url: string, name: string, address: string, connection_id: string}|null
     */
    public function resume(string $id): ?array
    {
        $user = auth()->user();
        $connection = SshConnection::query()->visibleTo($user)->with('key')->where('uuid', $id)->first();

        if ($connection === null || ! $connection->canConnect($user)) {
            return null;
        }

        return [
            'ticket' => Gateway::issue($connection, $user),
            'url' => Gateway::url(),
            'name' => $connection->name,
            'address' => $connection->address(),
            'connection_id' => $connection->uuid,
        ];
    }

    /**
     * The file manager of a connection (Files), in place of its terminal: over
     * SFTP with the same credential, and only on a host whose key was accepted.
     */
    public function browse(string $id): bool
    {
        $user = auth()->user();
        $connection = $this->visibleConnection($id);

        if (! $connection->canConnect($user)) {
            if ($connection->isOwnedBy($user)) {
                Flux::toast(variant: 'warning', text: __(':name has no key or password: edit it first.', ['name' => $connection->name]));
            } else {
                $this->editCredential($connection->uuid);
            }

            return false;
        }

        if (blank($connection->host_key)) {
            Flux::toast(variant: 'warning', text: __('Accept the host key of :name in its terminal first: the file manager checks the same key.', ['name' => $connection->name]));

            return false;
        }

        if (! in_array($connection->uuid, $this->browsing, true)) {
            $this->browsing[] = $connection->uuid;
        }

        return true;
    }

    /** The person accepted the key a new host showed: later connections, of anybody, expect the same one. */
    public function trustHostKey(string $id, string $fingerprint): void
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

    public function saveKey(): void
    {
        $key = $this->createKey(auth()->user());

        if ($key) {
            unset($this->keys);
            $this->showKey($key->uuid);
        }
    }

    /** The public half of one of this person's keys, or of a system key shared with them. */
    public function showKey(string $id): void
    {
        $this->shownKey = ($this->keys()->firstWhere('uuid', $id) ?? $this->systemKeys()->firstWhere('uuid', $id) ?? abort(404))->uuid;

        Flux::modal('ssh-public-key')->show();
    }

    public function deleteKey(string $id): void
    {
        $this->key($id)->delete();
        unset($this->keys, $this->connections);

        Flux::toast(text: __('Key removed. The connections that used it need another one.'));
    }

    public function render(): View
    {
        $credentialConnection = $this->credentialFor ? SshConnection::query()->visibleTo(auth()->user())->with('user')->where('uuid', $this->credentialFor)->first() : null;

        $pickedProject = $this->connection_project ? Project::query()->where('uuid', $this->connection_project)->first() : null;

        return view('livewire.ssh.terminal', [
            'shown' => $this->shownKey ? ($this->keys()->firstWhere('uuid', $this->shownKey) ?? $this->systemKeys()->firstWhere('uuid', $this->shownKey)) : null,
            'pickedProject' => $pickedProject,
            'credentialConnection' => $credentialConnection,
            'connectionKeys' => $this->usableKeys($pickedProject?->id),
            'credentialKeys' => $credentialConnection ? $this->usableKeys($credentialConnection->project_id) : collect(),
        ]);
    }

    /**
     * What the key pickers offer for a connection of this project (null: a
     * personal one): this person's keys first, then the system keys they may use.
     *
     * @return Collection<int, SshKey>
     */
    private function usableKeys(?int $projectId): Collection
    {
        return SshKey::query()
            ->usableBy(auth()->user(), $projectId)
            ->orderByRaw('user_id is null')
            ->orderBy('name')
            ->get();
    }

    private function usableKeyRule(?int $projectId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($projectId): void {
            if (! SshKey::query()->usableBy(auth()->user(), $projectId)->where('uuid', $value)->exists()) {
                $fail(__('Choose one of your keys, or a system key shared with this project.'));
            }
        };
    }

    private function ownConnection(string $id): SshConnection
    {
        return auth()->user()->sshConnections()->where('uuid', $id)->firstOrFail();
    }

    private function visibleConnection(string $id): SshConnection
    {
        return SshConnection::query()->visibleTo(auth()->user())->with('key')->where('uuid', $id)->firstOrFail();
    }

    private function key(string $id): SshKey
    {
        return auth()->user()->sshKeys()->where('uuid', $id)->firstOrFail();
    }
}
