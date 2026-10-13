@php
    use App\Databases\Driver;
    use App\Databases\Environment;
    use App\Models\DatabaseGroup;
    use Illuminate\Support\Js;
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="{ copy(text) { navigator.clipboard.writeText(text); $flux.toast({ text: {{ Js::from(__('Link copied.')) }} }) } }">
    <div class="flex items-start justify-between gap-4 max-sm:flex-col max-sm:gap-3">
        <div>
            <flux:heading size="xl">{{ __('Databases') }}</flux:heading>
            <flux:text class="mt-1 max-w-3xl">{{ __('MySQL, MariaDB, PostgreSQL, SQLite, Redis and Valkey, reached from this Studio server directly or through an SSH tunnel. Browse and edit data, run SQL, change structures, import and export. Passwords are stored encrypted and never reach a page; a connection tied to a project opens for the roles you choose.') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="folder-plus" wire:click="newGroup">{{ __('New group') }}</flux:button>
            @if ($this->canCreate())
                <flux:button icon="plus" variant="primary" wire:click="newConnection">{{ __('New connection') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($total > 6 || $search !== '')
        <flux:input wire:model.live.debounce.200ms="search" icon="magnifying-glass" :placeholder="__('Search connections, hosts, projects…')" clearable class="max-w-sm" />
    @endif

    @if ($total === 0)
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:icon.circle-stack class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">{{ __('No connections yet') }}</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-lg">{{ __('Add one from the form, or paste a link as TablePlus writes it (mysql://…, postgres://…, mariadb+ssh://ssh_user@ssh_host/user:password@127.0.0.1/database, redis://…) or the DB_ lines of a Laravel .env.') }}</flux:text>
            @if ($this->canCreate())
                <flux:button class="mt-4" icon="plus" variant="primary" wire:click="newConnection">{{ __('New connection') }}</flux:button>
            @endif
        </div>
    @endif

    @foreach ($sections as $section)
        @php($group = $section['group'])
        @continue($section['rows']->isEmpty() && ($search !== '' || $group === null))

        <section class="space-y-3" wire:key="db-section-{{ $section['key'] }}">
            <div class="flex flex-wrap items-center gap-2">
                @if ($group)
                    <span class="size-2.5 rounded-full {{ DatabaseGroup::dot($group->color) }}"></span>
                @elseif ($section['key'] === 'favorites')
                    <flux:icon.star variant="micro" class="size-4 text-amber-500" />
                @endif
                <flux:heading size="lg" @class(['text-zinc-500' => $group === null && $section['key'] !== 'favorites'])>{{ $section['title'] }}</flux:heading>
                <flux:badge size="sm">{{ $section['rows']->count() }}</flux:badge>

                @if ($group)
                    <flux:dropdown position="bottom" align="end" class="ms-auto">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Group actions')" />
                        <flux:menu>
                            @if ($this->canCreate())
                                <flux:menu.item icon="plus" wire:click="newConnection({{ Js::from($group->uuid) }})">{{ __('New connection here') }}</flux:menu.item>
                            @endif
                            <flux:menu.item icon="pencil" wire:click="editGroup({{ Js::from($group->uuid) }})">{{ __('Edit the group') }}</flux:menu.item>
                            @unless ($this->groups->first()?->is($group))
                                <flux:menu.item icon="arrow-up" wire:click="moveGroup({{ Js::from($group->uuid) }}, -1)">{{ __('Move up') }}</flux:menu.item>
                            @endunless
                            @unless ($this->groups->last()?->is($group))
                                <flux:menu.item icon="arrow-down" wire:click="moveGroup({{ Js::from($group->uuid) }}, 1)">{{ __('Move down') }}</flux:menu.item>
                            @endunless
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteGroup({{ Js::from($group->uuid) }})" wire:confirm="{{ __('Delete the group :group? Its connections stay.', ['group' => $group->name]) }}">{{ __('Delete the group') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>

            @if ($section['rows']->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    {{ __('No connections here yet: use “Move to group” in a connection’s menu.') }}
                </div>
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($section['rows'] as $row)
                        @php($connection = $row['connection'])
                        @php($env = $connection->environment)
                        <div wire:key="db-{{ $section['key'] }}-{{ $connection->uuid }}"
                             class="group relative flex flex-col gap-2 rounded-xl border border-s-4 border-zinc-200 bg-white p-4 transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500 {{ DatabaseGroup::edge($row['group']?->color) }}">
                            <div class="flex items-start gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold {{ $connection->driver->tint() }}">{{ $connection->driver->short() }}</span>
                                <a href="{{ route('databases.client', $connection) }}" wire:navigate class="min-w-0 flex-1 after:absolute after:inset-0 after:rounded-xl">
                                    <div class="flex items-center gap-1.5">
                                        <span class="truncate font-medium">{{ $connection->name }}</span>
                                    </div>
                                    <div class="truncate font-mono text-[11px] text-zinc-500" title="{{ $connection->address() }}">{{ $connection->address() }}</div>
                                </a>
                                <div class="relative z-10 flex items-center">
                                    <button type="button" class="cursor-pointer rounded p-1 hover:bg-zinc-100 dark:hover:bg-zinc-700" wire:click="toggleFavorite({{ Js::from($connection->uuid) }})" title="{{ $row['favorite'] ? __('Remove from favourites') : __('Add to favourites') }}">
                                        <flux:icon.star variant="micro" @class(['size-4', 'text-amber-500' => $row['favorite'], 'text-zinc-300 dark:text-zinc-600' => ! $row['favorite']]) />
                                    </button>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" :aria-label="__('Connection actions')" />
                                        <flux:menu>
                                            <flux:menu.item icon="arrow-top-right-on-square" :href="route('databases.client', $connection)" wire:navigate>{{ __('Open') }}</flux:menu.item>
                                            @if ($row['mine'])
                                                <flux:menu.item icon="pencil-square" wire:click="editConnection({{ Js::from($connection->uuid) }})">{{ __('Edit') }}</flux:menu.item>
                                                <flux:menu.item icon="document-duplicate" wire:click="duplicateConnection({{ Js::from($connection->uuid) }})">{{ __('Duplicate') }}</flux:menu.item>
                                                <flux:menu.item icon="link" x-on:click="copy(await $wire.copyLink({{ Js::from($connection->uuid) }}, true))">{{ __('Copy link with passwords') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.item icon="link" x-on:click="copy(await $wire.copyLink({{ Js::from($connection->uuid) }}))">{{ __('Copy link') }}</flux:menu.item>
                                            <flux:menu.submenu :heading="__('Move to group')" icon="folder">
                                                @foreach ($this->groups as $target)
                                                    <flux:menu.item wire:click="moveToGroup({{ Js::from($connection->uuid) }}, {{ Js::from($target->uuid) }})" :disabled="$row['group']?->id === $target->id">
                                                        <span class="me-2 inline-block size-2 rounded-full {{ DatabaseGroup::dot($target->color) }}"></span>{{ $target->name }}
                                                    </flux:menu.item>
                                                @endforeach
                                                @if ($row['group'])
                                                    <flux:menu.item wire:click="moveToGroup({{ Js::from($connection->uuid) }})">{{ __('No group') }}</flux:menu.item>
                                                @endif
                                                <flux:menu.separator />
                                                <flux:menu.item icon="folder-plus" wire:click="newGroup">{{ __('New group…') }}</flux:menu.item>
                                            </flux:menu.submenu>
                                            @if ($row['mine'])
                                                <flux:menu.separator />
                                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteConnection({{ Js::from($connection->uuid) }})" wire:confirm="{{ $connection->project_id ? __('Remove the connection :name? It disappears for everybody on :project too.', ['name' => $connection->name, 'project' => $connection->project?->name]) : __('Remove the connection :name?', ['name' => $connection->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                <flux:badge size="sm" :color="$env->color()">{{ $env->label() }}</flux:badge>
                                <flux:badge size="sm">{{ $connection->driver->label() }}</flux:badge>
                                @if ($connection->sshConnection)
                                    <flux:badge size="sm" icon="lock-closed" :title="__('Through the SSH connection :name, with :whose credential', ['name' => $connection->sshConnection->name, 'whose' => $connection->tunnel_as === 'member' ? __('each person’s own') : __('the owner’s')])">{{ $connection->sshConnection->name }}</flux:badge>
                                @elseif ($connection->isLocalFile())
                                    <flux:badge size="sm" icon="server">{{ __('this server') }}</flux:badge>
                                @elseif ($connection->tls)
                                    <flux:badge size="sm" icon="shield-check">TLS</flux:badge>
                                @endif
                                @if ($connection->project)
                                    <flux:badge size="sm" icon="folder">{{ $connection->project->name }}</flux:badge>
                                @endif
                                @if ($row['access'] === 'read')
                                    <flux:badge size="sm" color="amber" icon="eye">{{ __('read only') }}</flux:badge>
                                @endif
                                @unless ($row['mine'])
                                    <span class="text-zinc-500" title="{{ __('Shared by :name', ['name' => $connection->user->name]) }}">{{ $connection->user->handle ?: $connection->user->name }}</span>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

    {{-- New / edit connection --}}
    @if ($this->canCreate())
    <flux:modal name="database-connection" class="w-full md:w-[42rem]">
        <form wire:submit="saveConnection" class="space-y-5">
            <flux:heading size="lg">{{ $editing ? __('Edit connection') : __('New connection') }}</flux:heading>

            {{-- From a link or a .env --}}
            <div class="rounded-lg border border-dashed border-zinc-300 p-3 dark:border-zinc-600" x-data="{ open: {{ $editing ? 'false' : 'true' }} }">
                <button type="button" class="flex w-full items-center gap-2 text-start text-sm font-medium" x-on:click="open = ! open">
                    <flux:icon.link variant="micro" class="size-4 text-zinc-400" />
                    {{ __('Fill in from a link or a .env') }}
                    <flux:icon.chevron-down variant="micro" class="ms-auto size-4 text-zinc-400 transition" x-bind:class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-collapse class="mt-3 space-y-2">
                    <flux:textarea wire:model="link" rows="2" class="font-mono text-xs" :placeholder="'mariadb+ssh://ssh_user:ssh_password@203.0.113.10/db_user:db_password@127.0.0.1/database?env=production'" />
                    <flux:error name="link" />
                    <div class="flex items-center justify-between gap-2">
                        <flux:text class="text-xs">{{ __('mysql, mariadb, postgres, sqlite, redis, rediss, valkey; +ssh for a tunnel. Or paste DB_CONNECTION, DB_HOST… / REDIS_HOST… lines.') }}</flux:text>
                        <flux:button size="sm" icon="arrow-down-tray" wire:click="applyLink">{{ __('Fill in') }}</flux:button>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('shop production')" required />
                <flux:select wire:model="environment" :label="__('Environment')">
                    @foreach (Environment::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:field>
                <flux:label>{{ __('Database') }}</flux:label>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                    @foreach (Driver::cases() as $case)
                        <label wire:key="driver-{{ $case->value }}" @class(['flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-2 text-xs transition', 'border-zinc-800 ring-1 ring-zinc-800 dark:border-white dark:ring-white' => $driver === $case->value, 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' => $driver !== $case->value])>
                            <input type="radio" class="sr-only" wire:model.live="driver" value="{{ $case->value }}" />
                            <span class="flex size-7 items-center justify-center rounded-md text-[11px] font-bold {{ $case->tint() }}">{{ $case->short() }}</span>
                            {{ $case->label() }}
                        </label>
                    @endforeach
                </div>
            </flux:field>

            {{-- Where it is reached from --}}
            @if ($driverEnum === Driver::Sqlite)
                <flux:radio.group wire:model.live="via" :label="__('The file is')" variant="segmented">
                    <flux:radio value="ssh" :label="__('On an SSH host')" icon="command-line" />
                    @if (auth()->user()->isAdmin())
                        <flux:radio value="local" :label="__('On this server')" icon="server" />
                    @endif
                </flux:radio.group>
            @else
                <flux:radio.group wire:model.live="via" :label="__('Connect')" variant="segmented">
                    <flux:radio value="none" :label="__('Directly')" icon="globe-alt" />
                    <flux:radio :value="$via === 'new' ? 'new' : 'ssh'" :label="__('Through an SSH tunnel')" icon="lock-closed" />
                </flux:radio.group>
            @endif

            @if ($via === 'ssh')
                @if ($this->sshConnections->isEmpty())
                    <flux:callout variant="warning" icon="command-line" :heading="__('No SSH connection to use')">
                        <flux:callout.text>{{ __('Add the host on the SSH page with your key or password, or paste a +ssh link above: it creates one.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:select wire:model.live="ssh" :label="__('SSH connection')" :placeholder="__('Choose…')">
                        @foreach ($this->sshConnections as $candidate)
                            <flux:select.option :value="$candidate->uuid">{{ $candidate->name }} · {{ $candidate->address() }}{{ $candidate->host_key ? '' : ' · '.__('key not accepted yet') }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @elseif ($via === 'new')
                <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <flux:icon.command-line variant="micro" class="size-4 text-zinc-400" />
                        <span class="text-sm font-medium">{{ __('New SSH connection, from the link') }}</span>
                        @if ($ssh_host_key)
                            <flux:badge size="sm" color="green" icon="shield-check">{{ __('host key accepted') }}</flux:badge>
                        @endif
                        <flux:button size="xs" variant="ghost" class="ms-auto" wire:click="$set('via', 'ssh')">{{ __('Use an existing one') }}</flux:button>
                    </div>
                    <div class="grid grid-cols-[1fr_5rem] gap-3">
                        <flux:input wire:model="ssh_host" :label="__('SSH host')" class="font-mono" />
                        <flux:input wire:model="ssh_port" :label="__('Port')" type="number" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="ssh_username" :label="__('SSH user')" class="font-mono" />
                        <flux:input wire:model="ssh_password" type="password" :label="__('SSH password')" viewable autocomplete="new-password" />
                    </div>
                    @if ($ssh_password === '' && $this->sshKeys->isNotEmpty())
                        <flux:select wire:model="ssh_key" :label="__('Or one of your keys')">
                            @foreach ($this->sshKeys as $key)
                                <flux:select.option :value="$key->uuid">{{ $key->name }} · {{ $key->label() }}{{ $key->isSystem() ? ' · '.__('system') : '' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:text class="text-xs">{{ __('Saved with the connection as one of your SSH connections, its password encrypted.') }}</flux:text>
                </div>
            @endif

            @if ($driverEnum === Driver::Sqlite)
                <flux:input wire:model="database" :label="__('Path of the file')" class="font-mono" :placeholder="$via === 'local' ? '/var/lib/studio/app.sqlite' : '~/current/database/database.sqlite'" required
                    :description="$via === 'local' ? __('A file on Studio’s server, read and written as Studio’s user.') : __('Read and written with the host’s own sqlite3 (3.33 or later).')" />
            @else
                <div class="grid grid-cols-[1fr_6rem] gap-3">
                    <flux:input wire:model="host" :label="$via === 'none' ? __('Host') : __('Host, as the SSH host sees it')" class="font-mono" placeholder="127.0.0.1" required />
                    <flux:input wire:model="port" :label="__('Port')" type="number" :placeholder="(string) $driverEnum->defaultPort()" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="username" :label="$driverEnum->isRedis() ? __('User (ACL, optional)') : __('User')" class="font-mono" :placeholder="$driverEnum->isRedis() ? 'default' : 'root'" />
                    <div>
                        <flux:input wire:model="password" type="password" :label="__('Password')" viewable autocomplete="new-password" :placeholder="$hasPassword ? __('Saved: leave empty to keep it') : ''" />
                        @if ($hasPassword)
                            <flux:checkbox wire:model="password_clear" class="mt-1" :label="__('Remove the saved password')" />
                        @endif
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                    <flux:input wire:model="database" :label="$driverEnum->isRedis() ? __('Database number') : __('Database')" class="font-mono"
                        :placeholder="$driverEnum->isRedis() ? '0' : ($driverEnum === Driver::Pgsql ? 'postgres' : __('optional: choose later'))" />
                    @if ($via === 'none')
                        <flux:checkbox wire:model="tls" :label="__('TLS')" :description="__('Encrypted; the certificate is not checked.')" />
                    @endif
                </div>
            @endif

            @if ($via === 'ssh' || $via === 'new')
                <flux:radio.group wire:model="tunnel_as" :label="__('Whose SSH credential opens the tunnel for the others')">
                    <flux:radio value="owner" :label="__('Mine')" :description="__('People this connection is shared with reach this database through it, never a shell.')" />
                    <flux:radio value="member" :label="__('Each person’s own')" :description="__('Everybody needs a key or a password of their own on the SSH page.')" />
                </flux:radio.group>
            @endif

            {{-- Sharing --}}
            <flux:field>
                <flux:label>{{ __('Project') }} <span class="ms-1 text-xs font-normal text-zinc-500">{{ __('optional') }}</span></flux:label>
                <x-search-picker
                    wire:key="db-project-picker-{{ $editing ?? 'new' }}"
                    search="searchProjects"
                    select="pickProject"
                    :selected="$pickedProject ? ['id' => $pickedProject->uuid, 'label' => $pickedProject->name] : null"
                    :placeholder="__('Personal: search a project to share with…')"
                />
                <flux:description>{{ __('Its roles reach the database with what you choose, with your database credentials, never seeing them. Administrators of Studio may read and write every project connection.') }}</flux:description>
            </flux:field>

            @if ($project)
                <div class="grid grid-cols-3 gap-3">
                    @foreach (['client' => __('Client'), 'pm' => __('PM'), 'dev' => __('Developer')] as $role => $label)
                        <flux:select size="sm" wire:model.live="access.{{ $role }}" :label="$label">
                            <flux:select.option value="none">{{ __('no access') }}</flux:select.option>
                            <flux:select.option value="read">{{ __('can read') }}</flux:select.option>
                            <flux:select.option value="write">{{ __('can read and write') }}</flux:select.option>
                        </flux:select>
                    @endforeach
                </div>
                @if ($audience->isNotEmpty())
                    <div class="max-h-40 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($audience as $member)
                                    <tr wire:key="db-audience-{{ $member['user']->uuid }}">
                                        <td class="px-3 py-1.5">{{ $member['user']->name }}</td>
                                        <td class="px-3 py-1.5 text-zinc-500">{{ ($member['role'] ?? $member['user']->role)?->label() }}</td>
                                        <td class="px-3 py-1.5 text-end">
                                            @if ($member['level'] === 'write')
                                                <flux:badge size="sm" color="green">{{ __('reads and writes') }}</flux:badge>
                                            @elseif ($member['level'] === 'read')
                                                <flux:badge size="sm" color="amber">{{ __('reads') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ __('no access') }}</flux:badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($this->groups->isNotEmpty())
                <flux:select wire:model="group" :label="__('Group')" :placeholder="__('No group')">
                    <flux:select.option value="">{{ __('No group') }}</flux:select.option>
                    @foreach ($this->groups as $candidate)
                        <flux:select.option :value="$candidate->uuid">{{ $candidate->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            {{-- What the test said --}}
            @if ($testResult)
                <flux:callout :variant="$testOk ? 'success' : ($fingerprints !== [] ? 'warning' : 'danger')" :icon="$testOk ? 'check-circle' : ($fingerprints !== [] ? 'shield-exclamation' : 'x-circle')">
                    <flux:callout.text>{{ $testResult }}</flux:callout.text>
                    @if ($fingerprints !== [])
                        <div class="mt-2 space-y-1.5">
                            @foreach ($fingerprints as $fingerprint)
                                <div class="flex items-center gap-2" wire:key="fp-{{ $loop->index }}">
                                    <code class="min-w-0 flex-1 truncate rounded bg-white/60 px-1.5 py-0.5 text-[11px] dark:bg-black/30">{{ $fingerprint }}</code>
                                    <flux:button size="xs" icon="check" wire:click="acceptHostKey({{ Js::from($fingerprint) }})">{{ __('Accept') }}</flux:button>
                                </div>
                            @endforeach
                            <flux:text class="text-xs">{{ __('Compare with ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the server. Later connections of anybody expect the same key.') }}</flux:text>
                        </div>
                    @endif
                </flux:callout>
            @endif

            <div class="flex items-center justify-between gap-2">
                <flux:button icon="bolt" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection,acceptHostKey">
                    <span wire:loading.remove wire:target="testConnection,acceptHostKey">{{ __('Test') }}</span>
                    <span wire:loading wire:target="testConnection,acceptHostKey">{{ __('Testing…') }}</span>
                </flux:button>
                <div class="flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
    @endif

    {{-- Group --}}
    <flux:modal name="database-group" class="md:w-[28rem]">
        <form wire:submit="saveGroup" class="space-y-5">
            <flux:heading size="lg">{{ $groupEditing ? __('Edit the group') : __('New group') }}</flux:heading>
            <flux:input wire:model="group_name" :label="__('Name')" :placeholder="__('Clients, Production, Side projects…')" required />
            <flux:field>
                <flux:label>{{ __('Colour') }}</flux:label>
                <div class="flex flex-wrap gap-2">
                    @foreach (DatabaseGroup::COLORS as $color)
                        <label wire:key="db-color-{{ $color }}" class="cursor-pointer">
                            <input type="radio" class="peer sr-only" wire:model.live="group_color" value="{{ $color }}" />
                            <span class="block size-7 rounded-full ring-offset-2 peer-checked:ring-2 peer-checked:ring-zinc-800 dark:ring-offset-zinc-800 dark:peer-checked:ring-white {{ DatabaseGroup::dot($color) }}" title="{{ $color }}"></span>
                        </label>
                    @endforeach
                </div>
            </flux:field>
            <flux:text class="text-xs">{{ __('Groups are yours: the connections shared with you go in them too, and nobody else sees how you sorted them.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
