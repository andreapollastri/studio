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
                <flux:button size="xs" icon="plus" variant="subtle" wire:click="newConnection">{{ __('New') }}</flux:button>
            </div>

            @if ($this->connections->count() > 5)
                <flux:input size="sm" icon="magnifying-glass" x-model="filter" :placeholder="__('Filter…')" clearable />
            @endif

            <div class="flex flex-col gap-1">
                @forelse ($this->connections as $row)
                    @php($connection = $row['connection'])
                    <div
                        wire:key="ssh-connection-{{ $connection->id }}"
                        x-show="matches(@js(mb_strtolower($connection->name.' '.$connection->address().' '.$connection->user->name.' '.$connection->project?->name)))"
                        class="group flex items-center gap-1 rounded-lg px-1 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        <button type="button" class="shrink-0 cursor-pointer rounded p-1 hover:bg-zinc-200 dark:hover:bg-zinc-700" title="{{ $row['favorite'] ? __('Remove from favourites') : __('Add to favourites') }}" wire:click="toggleFavorite({{ $connection->id }})">
                            @if ($row['favorite'])
                                <flux:icon.star variant="micro" class="size-4 text-amber-500" />
                            @else
                                <flux:icon.star variant="micro" class="size-4 text-zinc-300 group-hover:text-zinc-400 dark:text-zinc-600" />
                            @endif
                        </button>
                        <button type="button" class="min-w-0 flex-1 cursor-pointer text-start" x-on:click="open({{ $connection->id }})" title="{{ __('Open a terminal on :address', ['address' => $connection->address()]) }}">
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
                                <flux:menu.item icon="command-line" x-on:click="open({{ $connection->id }})">{{ __('Open a terminal') }}</flux:menu.item>
                                @if ($row['mine'])
                                    <flux:menu.item icon="pencil-square" wire:click="editConnection({{ $connection->id }})">{{ __('Edit') }}</flux:menu.item>
                                    @if ($connection->host_key)
                                        <flux:menu.item icon="shield-exclamation" wire:click="forgetHostKey({{ $connection->id }})" wire:confirm="{{ __('Forget the host key :fingerprint? The next connection shows the key again and asks you to accept it.', ['fingerprint' => $connection->host_key]) }}">{{ __('Forget host key') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteConnection({{ $connection->id }})" wire:confirm="{{ $connection->project_id ? __('Remove the connection :name? It disappears for everybody on :project too.', ['name' => $connection->name, 'project' => $connection->project?->name]) : __('Remove the connection :name?', ['name' => $connection->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                @else
                                    <flux:menu.item icon="key" wire:click="editCredential({{ $connection->id }})">{{ __('My key or password') }}</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @empty
                    <flux:text class="px-1 text-xs">{{ __('No connections yet. Add a host, with one of your keys or a password.') }}</flux:text>
                @endforelse
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Keys') }}</flux:heading>
                <flux:button size="xs" icon="plus" variant="subtle" wire:click="newKey">{{ __('New key') }}</flux:button>
            </div>
            <div class="flex flex-col gap-1">
                @forelse ($this->keys as $key)
                    <div wire:key="ssh-key-{{ $key->id }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        <flux:icon.key variant="micro" class="size-4 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{ $key->name }}</span>
                                <flux:badge size="sm">{{ $key->label() }}</flux:badge>
                            </div>
                            <div class="truncate font-mono text-[11px] text-zinc-500" title="{{ $key->fingerprint }}">{{ $key->fingerprint }}</div>
                        </div>
                        <flux:button size="xs" variant="ghost" icon="clipboard-document" :tooltip="__('Public key')" wire:click="showKey({{ $key->id }})" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteKey({{ $key->id }})" wire:confirm="{{ $key->connections_count ? __('Remove the key :name? :count connection(s) use it and will need another one.', ['name' => $key->name, 'count' => $key->connections_count]) : __('Remove the key :name?', ['name' => $key->name]) }}" />
                    </div>
                @empty
                    <flux:text class="px-1 text-xs">{{ __('Generate a key here (Ed25519 or RSA) and copy its public half to the server, or import one you already use.') }}</flux:text>
                @endforelse
            </div>
        </section>
    </aside>

    {{-- Terminals: the browser owns them, Livewire never redraws this part. --}}
    <section wire:ignore class="flex min-h-[28rem] min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-zinc-950 dark:border-zinc-700">
        <div class="flex shrink-0 items-center gap-1 border-b border-zinc-800 px-2 py-1.5">
            <div class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
                <template x-for="session in sessions" :key="session.id">
                    <div
                        class="group flex shrink-0 cursor-pointer items-center gap-2 rounded-md px-2.5 py-1 text-xs"
                        :class="active === session.id ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'"
                        x-on:click="focus(session.id)"
                        x-on:auxclick.prevent="$event.button === 1 && close(session.id)"
                        :title="session.address"
                    >
                        <span class="size-1.5 shrink-0 rounded-full" :class="dot(session)"></span>
                        <span class="max-w-40 truncate" x-text="session.title"></span>
                        <button type="button" class="rounded text-zinc-500 hover:text-zinc-100" x-on:click.stop="close(session.id)" title="{{ __('Close') }}">
                            <flux:icon.x-mark variant="micro" class="size-3.5" />
                        </button>
                    </div>
                </template>
                <span x-show="sessions.length === 0" class="px-2 text-xs text-zinc-500">{{ __('No terminals open') }}</span>
            </div>
            <div class="flex shrink-0 items-center gap-0.5" x-show="sessions.length > 1">
                <button type="button" class="rounded p-1" :class="layout === 'tabs' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-500 hover:text-zinc-200'" x-on:click="setLayout('tabs')" title="{{ __('One at a time') }}">
                    <flux:icon.window variant="micro" class="size-4" />
                </button>
                <button type="button" class="rounded p-1" :class="layout === 'grid' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-500 hover:text-zinc-200'" x-on:click="setLayout('grid')" title="{{ __('All side by side') }}">
                    <flux:icon.squares-2x2 variant="micro" class="size-4" />
                </button>
            </div>
        </div>

        <div class="relative min-h-0 flex-1">
            <div x-show="sessions.length === 0" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-8 text-center text-zinc-500">
                <flux:icon.command-line class="size-8" />
                <div class="text-sm">{{ __('Pick a connection on the left to open a terminal. Open as many as you like: tabs, or all side by side.') }}</div>
            </div>

            <div
                class="grid h-full gap-px bg-zinc-800"
                :class="{
                    'grid-cols-1': layout === 'tabs' || sessions.length === 1,
                    'grid-cols-1 md:grid-cols-2': layout === 'grid' && sessions.length >= 2 && sessions.length <= 4,
                    'grid-cols-1 md:grid-cols-2 xl:grid-cols-3': layout === 'grid' && sessions.length > 4,
                }"
                x-show="sessions.length > 0"
            >
                <template x-for="session in sessions" :key="session.id">
                    <div
                        class="relative flex min-h-0 min-w-0 flex-col bg-zinc-950"
                        x-show="layout === 'grid' || active === session.id"
                        x-on:mousedown="active = session.id"
                        :class="layout === 'grid' && active === session.id && sessions.length > 1 ? 'ring-1 ring-inset ring-zinc-600' : ''"
                    >
                        <div x-show="layout === 'grid'" class="flex shrink-0 items-center gap-2 border-b border-zinc-800 px-2 py-1 text-[11px] text-zinc-400">
                            <span class="size-1.5 rounded-full" :class="dot(session)"></span>
                            <span class="truncate" x-text="session.title + ' · ' + session.address"></span>
                        </div>
                        <div class="min-h-0 flex-1 p-1.5" :id="'ssh-term-' + session.id"></div>

                        {{-- A host seen for the first time --}}
                        <div x-show="session.status === 'hostkey'" class="absolute inset-0 flex items-center justify-center bg-zinc-950/90 p-6">
                            <div class="max-w-md space-y-3 rounded-xl border border-amber-500/40 bg-zinc-900 p-5 text-sm text-zinc-200">
                                <div class="flex items-center gap-2 font-medium text-amber-400">
                                    <flux:icon.shield-exclamation class="size-5" />
                                    {{ __('First connection to this host') }}
                                </div>
                                <p class="text-zinc-400">{{ __('Check that the key fingerprint is the server’s (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the server). Once accepted, a different key will be refused.') }}</p>
                                <div class="break-all rounded bg-zinc-950 px-2 py-1.5 font-mono text-xs text-zinc-100" x-text="session.fingerprint"></div>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" x-on:click="answerHostKey(session.id, false)">{{ __('Cancel') }}</flux:button>
                                    <flux:button size="sm" variant="primary" x-on:click="answerHostKey(session.id, true)">{{ __('Trust and connect') }}</flux:button>
                                </div>
                            </div>
                        </div>

                        {{-- Ended or failed --}}
                        <div x-show="session.status === 'closed' || session.status === 'error'" class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 border-t border-zinc-800 bg-zinc-900/95 px-3 py-2 text-xs">
                            <span :class="session.status === 'error' ? 'text-red-400' : 'text-zinc-400'" x-text="session.error || @js(__('Session ended.'))"></span>
                            <div class="flex shrink-0 gap-2">
                                <flux:button size="xs" variant="filled" icon="arrow-path" x-on:click="reconnect(session.id)">{{ __('Reconnect') }}</flux:button>
                                <flux:button size="xs" variant="ghost" x-on:click="close(session.id)">{{ __('Close') }}</flux:button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </section>

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
                @if ($this->keys->isEmpty())
                    <flux:callout variant="warning" icon="key" :heading="__('No keys yet')">
                        <flux:callout.text>{{ __('Save the connection with a password, or create a key first from Keys → New key.') }}</flux:callout.text>
                    </flux:callout>
                    <flux:error name="connection_key" />
                @else
                    <flux:select wire:model="connection_key" :label="__('Key')">
                        @foreach ($this->keys as $key)
                            <flux:select.option :value="$key->id">{{ $key->name }} · {{ $key->label() }}</flux:select.option>
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
                    :selected="$pickedProject ? ['id' => $pickedProject->id, 'label' => $pickedProject->name] : null"
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
                                    <tr wire:key="ssh-audience-{{ $member['user']->id }}">
                                        <td class="px-3 py-1.5">{{ $member['user']->name }}</td>
                                        <td class="px-3 py-1.5 text-zinc-500">{{ $member['user']->role->label() }}</td>
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
                    @if ($this->keys->isEmpty())
                        <flux:callout variant="warning" icon="key" :heading="__('No keys yet')">
                            <flux:callout.text>{{ __('Create a key first from Keys → New key, and add its public half to the server.') }}</flux:callout.text>
                        </flux:callout>
                        <flux:error name="credential_key" />
                    @else
                        <flux:select wire:model="credential_key" :label="__('Key')">
                            @foreach ($this->keys as $key)
                                <flux:select.option :value="$key->id">{{ $key->name }} · {{ $key->label() }}</flux:select.option>
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

    {{-- New key --}}
    <flux:modal name="ssh-key" class="md:w-[34rem]">
        <form wire:submit="saveKey" class="space-y-5">
            <flux:heading size="lg">{{ __('New SSH key') }}</flux:heading>
            <flux:input wire:model="key_name" :label="__('Name')" :placeholder="__('studio deploy key')" required />

            <flux:radio.group wire:model.live="key_mode" variant="segmented">
                <flux:radio value="generate" :label="__('Generate')" icon="sparkles" />
                <flux:radio value="import" :label="__('Import')" icon="arrow-down-tray" />
            </flux:radio.group>

            @if ($key_mode === 'generate')
                <flux:radio.group wire:model="key_type" :label="__('Type')">
                    <flux:radio value="ed25519" label="Ed25519" :description="__('Short, fast and modern: the default of OpenSSH. Recommended.')" />
                    <flux:radio value="rsa" label="RSA 4096" :description="__('For older servers and appliances that do not accept Ed25519.')" />
                </flux:radio.group>
            @else
                <flux:textarea wire:model="key_private" :label="__('Private key')" rows="8" class="font-mono text-xs" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" />
                <flux:input wire:model="key_passphrase" type="password" :label="__('Passphrase')" :description="__('Only if the key has one: it is used to read the key and not kept.')" autocomplete="off" viewable />
                <flux:text class="text-xs">{{ __('OpenSSH, PEM (PKCS#1, PKCS#8) and PuTTY keys; RSA, Ed25519 and ECDSA.') }}</flux:text>
            @endif
            <flux:error name="key_private" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $key_mode === 'generate' ? __('Generate') : __('Import') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Public key, to copy into authorized_keys --}}
    <flux:modal name="ssh-public-key" class="md:w-[40rem]">
        @if ($shown)
            <div class="space-y-4" x-data="{ copied: null, copy(text, what) { navigator.clipboard.writeText(text); this.copied = what; setTimeout(() => this.copied = null, 1500) } }">
                <div>
                    <flux:heading size="lg">{{ $shown->name }}</flux:heading>
                    <flux:text class="mt-1">{{ $shown->label() }} · <span class="font-mono text-xs">{{ $shown->fingerprint }}</span></flux:text>
                </div>
                <flux:text>{{ __('Add the public key to ~/.ssh/authorized_keys of the user on the server, or to the deploy keys of your hosting panel.') }}</flux:text>
                <div class="relative">
                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-3 pe-24 font-mono text-xs dark:bg-zinc-900">{{ $shown->public_key }}</pre>
                    <flux:button size="xs" class="absolute end-2 top-2" icon="clipboard-document" x-on:click="copy(@js($shown->public_key), 'key')">
                        <span x-text="copied === 'key' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                    </flux:button>
                </div>
                <div class="relative">
                    <flux:text class="mb-1 text-xs">{{ __('Or run this on the server, as that user:') }}</flux:text>
                    <pre class="overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-3 pe-24 font-mono text-xs dark:bg-zinc-900">{{ $authorize = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '.escapeshellarg($shown->public_key).' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys' }}</pre>
                    <flux:button size="xs" class="absolute end-2 top-7" icon="clipboard-document" x-on:click="copy(@js($authorize), 'cmd')">
                        <span x-text="copied === 'cmd' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                    </flux:button>
                </div>
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
