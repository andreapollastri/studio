<div
    x-data="sshTerminals"
    class="flex min-h-[32rem] flex-col gap-4 lg:-m-2 lg:h-[calc(100dvh-4rem)] lg:flex-row"
>
    {{-- Connections and keys --}}
    <aside class="flex shrink-0 flex-col gap-5 lg:w-80 lg:overflow-y-auto lg:pe-1">
        <div>
            <flux:heading size="xl">{{ __('SSH') }}</flux:heading>
            <flux:text class="mt-1 text-xs">{{ __('Sessions open from this Studio server. Connections tied to a project show up for its administrators and developers; keys and passwords stay personal, stored encrypted, and only reach the SSH gateway.') }}</flux:text>
        </div>

        <section class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Connections') }}</flux:heading>
                <div class="flex items-center gap-1">
                    <flux:button size="xs" icon="folder-plus" variant="subtle" wire:click="newGroup" :tooltip="__('New group')" />
                    <flux:button size="xs" icon="plus" variant="subtle" wire:click="newConnection">{{ __('New') }}</flux:button>
                </div>
            </div>

            {{-- what a row is found by: its name, address, owner, project and group --}}
            @php($sshHaystack = fn (array $row) => mb_strtolower($row['connection']->name.' '.$row['connection']->address().' '.$row['connection']->user->name.' '.$row['connection']->project?->name.' '.$row['group']?->name))
            @if ($this->connections->count() > 5 || $this->groups->isNotEmpty())
                <flux:input size="sm" icon="magnifying-glass" x-model="filter" :placeholder="__('Search name, host, project, group…')" clearable />
            @endif

            @if ($this->groups->isNotEmpty())
                {{-- the group filter: every group, one of them, or the connections in none --}}
                <div class="flex flex-wrap gap-1" role="group" aria-label="{{ __('Show a group') }}">
                    <button type="button" x-on:click="group = ''" :aria-pressed="group === ''" class="rounded-full border px-2 py-0.5 text-xs" :class="group === '' ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-200 text-zinc-600 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300'">{{ __('All') }}</button>
                    @foreach ($this->groups as $sshFilter)
                        <button type="button" wire:key="ssh-group-filter-{{ $sshFilter->uuid }}" x-on:click="group = group === '{{ $sshFilter->uuid }}' ? '' : '{{ $sshFilter->uuid }}'" :aria-pressed="group === '{{ $sshFilter->uuid }}'" class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs" :class="group === '{{ $sshFilter->uuid }}' ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-200 text-zinc-600 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300'">
                            <span class="size-2 rounded-full {{ \App\Models\DatabaseGroup::dot($sshFilter->color) }}"></span>{{ $sshFilter->name }}
                        </button>
                    @endforeach
                    @if ($this->connections->contains(fn (array $row) => $row['group'] === null))
                        <button type="button" x-on:click="group = group === 'none' ? '' : 'none'" :aria-pressed="group === 'none'" class="rounded-full border px-2 py-0.5 text-xs" :class="group === 'none' ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-200 text-zinc-600 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300'">{{ __('No group') }}</button>
                    @endif
                </div>
            @endif

            {{-- the person's groups in their order, then the connections in none --}}
            @php($sshSections = $this->groups->map(fn ($g) => ['key' => $g->uuid, 'group' => $g, 'rows' => $this->connections->filter(fn (array $row) => $row['group']?->id === $g->id)->values()])
                ->push(['key' => 'none', 'group' => null, 'rows' => $this->connections->filter(fn (array $row) => $row['group'] === null)->values()]))
            <div class="flex flex-col gap-3">
                @foreach ($sshSections as $sshSection)
                    @php($sshGroup = $sshSection['group'])
                    @continue($sshGroup === null && $sshSection['rows']->isEmpty())
                    <div wire:key="ssh-section-{{ $sshSection['key'] }}" class="flex flex-col gap-1" x-show="section({{ \Illuminate\Support\Js::from($sshSection['rows']->map($sshHaystack)->values()) }}, '{{ $sshSection['key'] }}')">
                        @if ($sshGroup)
                            <div class="flex items-center gap-1.5 px-1">
                                <span class="size-2 shrink-0 rounded-full {{ \App\Models\DatabaseGroup::dot($sshGroup->color) }}"></span>
                                <span class="min-w-0 flex-1 truncate text-xs font-medium uppercase tracking-wide text-zinc-600 dark:text-zinc-300">{{ $sshGroup->name }}</span>
                                <span class="text-[11px] text-zinc-400">{{ $sshSection['rows']->count() }}</span>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :tooltip="__('Group')" />
                                    <flux:menu>
                                        <flux:menu.item icon="plus" wire:click="newConnection('{{ $sshGroup->uuid }}')">{{ __('New connection here') }}</flux:menu.item>
                                        <flux:menu.item icon="pencil" wire:click="editGroup('{{ $sshGroup->uuid }}')">{{ __('Edit the group') }}</flux:menu.item>
                                        @unless ($this->groups->first()?->is($sshGroup))
                                            <flux:menu.item icon="arrow-up" wire:click="moveGroup('{{ $sshGroup->uuid }}', -1)">{{ __('Move up') }}</flux:menu.item>
                                        @endunless
                                        @unless ($this->groups->last()?->is($sshGroup))
                                            <flux:menu.item icon="arrow-down" wire:click="moveGroup('{{ $sshGroup->uuid }}', 1)">{{ __('Move down') }}</flux:menu.item>
                                        @endunless
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteGroup('{{ $sshGroup->uuid }}')" wire:confirm="{{ __('Delete the group :group? Its connections stay.', ['group' => $sshGroup->name]) }}">{{ __('Delete the group') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        @elseif ($this->groups->isNotEmpty())
                            <div class="px-1 text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('No group') }}</div>
                        @endif

                        @foreach ($sshSection['rows'] as $row)
                            @php($connection = $row['connection'])
                            <div
                                wire:key="ssh-connection-{{ $connection->uuid }}"
                                x-show="matches({{ \Illuminate\Support\Js::from($sshHaystack($row)) }})"
                                class="group flex items-center gap-1 rounded-lg px-1 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                <button type="button" class="shrink-0 cursor-pointer rounded p-1 hover:bg-zinc-200 dark:hover:bg-zinc-700" title="{{ $row['favorite'] ? __('Remove from favourites') : __('Add to favourites') }}" wire:click="toggleFavorite('{{ $connection->uuid }}')">
                                    @if ($row['favorite'])
                                        <flux:icon.star variant="micro" class="size-4 text-amber-500" />
                                    @else
                                        <flux:icon.star variant="micro" class="size-4 text-zinc-300 group-hover:text-zinc-400 dark:text-zinc-600" />
                                    @endif
                                </button>
                                <button type="button" class="min-w-0 flex-1 cursor-pointer text-start" x-on:click="open('{{ $connection->uuid }}')" title="{{ __('Open a terminal on :address', ['address' => $connection->address()]) }}">
                                    <div class="flex items-center gap-1.5">
                                        <span class="truncate text-sm font-medium">{{ $connection->name }}</span>
                                        @unless ($row['mine'])
                                            <span class="shrink-0 rounded bg-zinc-200 px-1 text-[10px] text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300" title="{{ __('Shared by :name', ['name' => $connection->user->name]) }}">{{ $connection->user->handle ?: $connection->user->name }}</span>
                                        @endunless
                                        @if ($connection->host_key)
                                            <flux:icon.shield-check variant="micro" class="size-3.5 shrink-0 text-green-600 dark:text-green-500" />
                                        @endif
                                    </div>
                                    <div class="truncate font-mono text-[11px] text-zinc-500">{{ $connection->address() }}</div>
                                    @if ($connection->project)
                                        <div class="flex items-center gap-1 truncate text-[11px] text-zinc-500">
                                            <flux:icon.folder variant="micro" class="size-3 shrink-0" />{{ $connection->project->name }}
                                        </div>
                                    @endif
                                </button>
                                <flux:badge size="sm" :color="$row['label'] ? 'zinc' : ($row['mine'] ? 'red' : 'amber')" class="shrink-0">
                                    {{ $row['label'] === 'password' ? __('password') : ($row['label'] ?? ($row['mine'] ? __('no key') : __('your key?'))) }}
                                </flux:badge>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="command-line" x-on:click="open('{{ $connection->uuid }}')">{{ __('Open a terminal') }}</flux:menu.item>
                                        <flux:menu.item icon="folder-open" x-on:click="openFiles('{{ $connection->uuid }}')">{{ __('Browse files') }}</flux:menu.item>
                                        <flux:menu.submenu :heading="__('Move to group')" icon="folder">
                                            @foreach ($this->groups as $sshTarget)
                                                <flux:menu.item wire:click="moveToGroup('{{ $connection->uuid }}', '{{ $sshTarget->uuid }}')" :disabled="$row['group']?->id === $sshTarget->id">
                                                    <span class="me-2 inline-block size-2 rounded-full {{ \App\Models\DatabaseGroup::dot($sshTarget->color) }}"></span>{{ $sshTarget->name }}
                                                </flux:menu.item>
                                            @endforeach
                                            @if ($row['group'])
                                                <flux:menu.item wire:click="moveToGroup('{{ $connection->uuid }}')">{{ __('No group') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="folder-plus" wire:click="newGroup">{{ __('New group…') }}</flux:menu.item>
                                        </flux:menu.submenu>
                                        @if ($row['mine'])
                                            <flux:menu.item icon="pencil-square" wire:click="editConnection('{{ $connection->uuid }}')">{{ __('Edit') }}</flux:menu.item>
                                            @if ($connection->host_key)
                                                <flux:menu.item icon="shield-exclamation" wire:click="forgetHostKey('{{ $connection->uuid }}')" wire:confirm="{{ __('Forget the host key :fingerprint? The next connection shows the key again and asks you to accept it.', ['fingerprint' => $connection->host_key]) }}">{{ __('Forget host key') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteConnection('{{ $connection->uuid }}')" wire:confirm="{{ $connection->project_id ? __('Remove the connection :name? It disappears for everybody on :project too.', ['name' => $connection->name, 'project' => $connection->project?->name]) : __('Remove the connection :name?', ['name' => $connection->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                        @else
                                            <flux:menu.item icon="key" wire:click="editCredential('{{ $connection->uuid }}')">{{ __('My key or password') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        @endforeach
                        @if ($sshGroup && $sshSection['rows']->isEmpty())
                            <flux:text class="px-1 text-xs">{{ __('No connections here yet: use “Move to group” in a connection’s menu.') }}</flux:text>
                        @endif
                    </div>
                @endforeach

                @if ($this->connections->isEmpty())
                    <flux:text class="px-1 text-xs">{{ __('No connections yet. Add a host, with one of your keys or a password.') }}</flux:text>
                @endif
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Keys') }}</flux:heading>
                <flux:button size="xs" icon="plus" variant="subtle" wire:click="newKey">{{ __('New key') }}</flux:button>
            </div>
            <div class="flex flex-col gap-1">
                @forelse ($this->keys as $key)
                    <div wire:key="ssh-key-{{ $key->uuid }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        <flux:icon.key variant="micro" class="size-4 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{ $key->name }}</span>
                                <flux:badge size="sm">{{ $key->label() }}</flux:badge>
                            </div>
                            <div class="truncate font-mono text-[11px] text-zinc-500" title="{{ $key->fingerprint }}">{{ $key->fingerprint }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" icon="clipboard-document" :tooltip="__('Public key')" wire:click="showKey('{{ $key->uuid }}')" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteKey('{{ $key->uuid }}')" wire:confirm="{{ $key->connections_count ? __('Remove the key :name? :count connection(s) use it and will need another one.', ['name' => $key->name, 'count' => $key->connections_count]) : __('Remove the key :name?', ['name' => $key->name]) }}" />
                    </div>
                @empty
                    <flux:text class="px-1 text-xs">{{ __('Generate a key here (Ed25519 or RSA) and copy its public half to the server, or import one you already use.') }}</flux:text>
                @endforelse
            </div>
        </section>

        @if ($this->systemKeys->isNotEmpty() || auth()->user()->isAdmin())
            <section class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ __('System keys') }}</flux:heading>
                    @if (auth()->user()->isAdmin())
                        <flux:button size="xs" icon="cog-6-tooth" variant="subtle" :href="route('admin.system').'#keys'" wire:navigate>{{ __('Manage') }}</flux:button>
                    @endif
                </div>
                <div class="flex flex-col gap-1">
                    @forelse ($this->systemKeys as $key)
                        <div wire:key="ssh-system-key-{{ $key->uuid }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <flux:icon.server-stack variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-medium">{{ $key->name }}</span>
                                    <flux:badge size="sm">{{ $key->label() }}</flux:badge>
                                </div>
                                <div class="truncate text-[11px] text-zinc-500">{{ $key->projects->isEmpty() ? __('not shared with any project') : $key->projects->pluck('name')->join(', ') }}</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="clipboard-document" :tooltip="__('Public key')" wire:click="showKey('{{ $key->uuid }}')" />
                        </div>
                    @empty
                        <flux:text class="px-1 text-xs">{{ __('Keys of this Studio, shared with projects: create them in Administration → System.') }}</flux:text>
                    @endforelse
                </div>
            </section>
        @endif
    </aside>

    {{-- Terminals (the browser owns them), and the file manager each session can switch to. --}}
    @include('livewire.ssh.partials.terminals', ['class' => 'min-h-[28rem] max-lg:scroll-mt-16 rounded-xl border border-zinc-200 dark:border-zinc-700', 'files' => true, 'empty' => __('Pick a connection on the left to open a terminal. Open as many as you like: tabs, or all side by side. Terminal ⇄ Files, above, turns a session into the file manager of its host.')])

    {{-- New / edit connection --}}
    <flux:modal name="ssh-connection" class="md:w-[32rem]">
        <form wire:submit="saveConnection" class="space-y-5">
            <flux:heading size="lg">{{ $editing ? __('Edit connection') : __('New connection') }}</flux:heading>
            <flux:input wire:model="connection_name" :label="__('Name')" :placeholder="__('production web')" required />
            <div class="grid grid-cols-[1fr_6rem] gap-3">
                <flux:input wire:model="connection_host" :label="__('Host')" placeholder="web1.example.com" class="font-mono" required />
                <flux:input wire:model="connection_port" :label="__('Port')" type="number" min="1" max="65535" required />
            </div>
            <flux:input wire:model="connection_username" :label="__('User')" placeholder="deploy" class="font-mono" required />

            <flux:radio.group wire:model.live="connection_auth" :label="__('Authentication')" variant="segmented">
                <flux:radio value="key" :label="__('SSH key')" icon="key" />
                <flux:radio value="password" :label="__('Password')" icon="lock-closed" />
            </flux:radio.group>

            @if ($connection_auth === 'key')
                @if ($connectionKeys->isEmpty())
                    <flux:callout variant="warning" icon="key" :heading="__('No keys yet')">
                        <flux:callout.text>{{ __('Save the connection with a password, or create a key first from Keys → New key.') }}</flux:callout.text>
                    </flux:callout>
                    <flux:error name="connection_key" />
                @else
                    <flux:select wire:model="connection_key" :label="__('Key')">
                        @foreach ($connectionKeys as $key)
                            <flux:select.option :value="$key->uuid">{{ $key->name }} · {{ $key->label() }}{{ $key->isSystem() ? ' · '.__('system') : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @else
                <flux:input wire:model="connection_password" type="password" :label="__('Password')" :placeholder="$editing ? __('Leave empty to keep the saved one') : ''" autocomplete="new-password" viewable />
            @endif

            <flux:field>
                <flux:label>{{ __('Project') }} <span class="ms-1 text-xs font-normal text-zinc-500">{{ __('optional') }}</span></flux:label>
                <x-search-picker
                    wire:key="ssh-project-picker-{{ $editing ?? 'new' }}"
                    search="searchProjects"
                    select="pickProject"
                    :selected="$pickedProject ? ['id' => $pickedProject->uuid, 'label' => $pickedProject->name] : null"
                    :placeholder="__('Personal: search a project to share with…')"
                />
                <flux:description>{{ __('The project’s administrators and developers find host, port and user ready; each connects with their own key or password, never yours.') }}</flux:description>
                <flux:error name="connection_project" />
            </flux:field>

            @if ($connection_project)
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800">{{ __('Shared with') }}</div>
                    <div class="max-h-48 overflow-y-auto">
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @forelse ($this->audience as $member)
                                    <tr wire:key="ssh-audience-{{ $member['user']->uuid }}">
                                        <td class="px-3 py-1.5">{{ $member['user']->name }}</td>
                                        <td class="px-3 py-1.5 text-zinc-500">{{ ($member['role'] ?? $member['user']->role)?->label() }}</td>
                                        <td class="px-3 py-1.5 text-end">
                                            @if ($member['has_credential'])
                                                <flux:badge size="sm" color="green">{{ __('ready') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ __('no credential yet') }}</flux:badge>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="px-3 py-2 text-zinc-500">{{ __('Nobody else on this project may use SSH yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($this->groups->isNotEmpty())
                <flux:select wire:model="connection_group" :label="__('Group')" :placeholder="__('No group')">
                    <flux:select.option value="">{{ __('No group') }}</flux:select.option>
                    @foreach ($this->groups as $sshCandidate)
                        <flux:select.option :value="$sshCandidate->uuid">{{ $sshCandidate->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:checkbox wire:model="connection_favorite" :label="__('Favourite')" :description="__('Favourites stay at the top of the list.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Your own credential on a shared connection --}}
    <flux:modal name="ssh-credential" class="md:w-[32rem]">
        @if ($credentialConnection)
            <form wire:submit="saveCredential" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Your key or password') }}</flux:heading>
                    <flux:text class="mt-1">{{ __(':name (:address), shared by :owner. What you set here is yours alone.', ['name' => $credentialConnection->name, 'address' => $credentialConnection->address(), 'owner' => $credentialConnection->user->name]) }}</flux:text>
                </div>

                <flux:radio.group wire:model.live="credential_auth" :label="__('Authentication')" variant="segmented">
                    <flux:radio value="key" :label="__('SSH key')" icon="key" />
                    <flux:radio value="password" :label="__('Password')" icon="lock-closed" />
                </flux:radio.group>

                @if ($credential_auth === 'key')
                    @if ($credentialKeys->isEmpty())
                        <flux:callout variant="warning" icon="key" :heading="__('No keys yet')">
                            <flux:callout.text>{{ __('Create a key first from Keys → New key, and add its public half to the server.') }}</flux:callout.text>
                        </flux:callout>
                        <flux:error name="credential_key" />
                    @else
                        <flux:select wire:model="credential_key" :label="__('Key')">
                            @foreach ($credentialKeys as $key)
                                <flux:select.option :value="$key->uuid">{{ $key->name }} · {{ $key->label() }}{{ $key->isSystem() ? ' · '.__('system') : '' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                @else
                    <flux:input wire:model="credential_password" type="password" :label="__('Password')" :placeholder="__('Leave empty to keep the saved one')" autocomplete="new-password" viewable />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Group --}}
    <flux:modal name="ssh-group" class="md:w-[28rem]">
        <form wire:submit="saveGroup" class="space-y-5">
            <flux:heading size="lg">{{ $groupEditing ? __('Edit the group') : __('New group') }}</flux:heading>
            <flux:input wire:model="group_name" :label="__('Name')" :placeholder="__('Clients, Production, Side projects…')" required />
            <flux:field>
                <flux:label>{{ __('Colour') }}</flux:label>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Models\SshGroup::COLORS as $color)
                        <label wire:key="ssh-color-{{ $color }}" class="cursor-pointer">
                            <input type="radio" class="peer sr-only" wire:model.live="group_color" value="{{ $color }}" />
                            <span class="block size-7 rounded-full ring-offset-2 peer-checked:ring-2 peer-checked:ring-zinc-800 dark:ring-offset-zinc-800 dark:peer-checked:ring-white {{ \App\Models\DatabaseGroup::dot($color) }}" title="{{ $color }}"></span>
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

    @include('livewire.ssh.partials.key-modals')

    {{-- The file manager's editor (Monaco itself loads on first use) --}}
    @assets
        @vite('resources/js/editor.js')
    @endassets
</div>
