@php($tree = $this->folders->groupBy(fn ($f) => $f->parent_id ?? 0))
<div class="flex min-h-[32rem] flex-col gap-6 lg:flex-row">
    {{-- Sections and folders --}}
    <aside class="flex shrink-0 flex-col gap-4 lg:w-64" x-data="{ open: false }">
        <div>
            <flux:heading size="xl">{{ __('Password manager') }}</flux:heading>
            <flux:text class="mt-1 text-xs">{{ __('Encrypted on this server. Share an item with a project, by role, or with single people.') }}</flux:text>
        </div>

        {{-- Below lg the sections sit above the items: folded behind the one that is open. --}}
        <div class="lg:hidden">
            <flux:button class="w-full justify-between!" icon="folder" icon:trailing="chevron-down" x-on:click="open = ! open" x-bind:aria-expanded="open">{{ trim($search) !== '' ? __('Search') : $heading }}</flux:button>
        </div>

        <div class="contents" x-bind:class="{ 'max-lg:hidden': ! open }" x-on:click="$event.target.closest('[wire\\:click^=show]') && (open = false)">

        <nav class="flex flex-col gap-0.5 text-sm">
            @foreach (['all' => ['icon' => 'key', 'label' => __('All items')], 'shared' => ['icon' => 'users', 'label' => __('Shared with me')]] as $key => $entry)
                <button type="button" wire:click="show('{{ $key }}')" @class([
                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-start',
                    'bg-zinc-100 font-medium dark:bg-zinc-800' => $view === $key,
                    'hover:bg-zinc-100 dark:hover:bg-zinc-800' => $view !== $key,
                ])>
                    <flux:icon :name="$entry['icon']" variant="micro" class="size-4 text-zinc-400" />{{ $entry['label'] }}
                </button>
            @endforeach
        </nav>

        @if ($canCreate)
            <div class="flex flex-col gap-0.5">
                <div class="flex items-center justify-between px-2">
                    <flux:heading size="sm">{{ __('My folders') }}</flux:heading>
                    <flux:button size="xs" variant="ghost" icon="folder-plus" :tooltip="__('New folder')" wire:click="newFolder" />
                </div>
                <button type="button" wire:click="show('folder')" @class([
                    'flex items-center gap-2 rounded-md px-2 py-1 text-start text-sm',
                    'font-medium text-zinc-900 dark:text-white' => $view === 'folder' && $folder === null,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! ($view === 'folder' && $folder === null),
                ])>
                    <flux:icon.inbox variant="micro" class="size-4 text-zinc-400" />{{ __('Top level') }}
                </button>
                @include('livewire.tools.partials.vault-folders', ['parent' => 0, 'depth' => 0])
            </div>
        @endif

        @if ($this->projects->isNotEmpty())
            <div class="flex flex-col gap-0.5">
                <flux:heading size="sm" class="px-2">{{ __('Projects') }}</flux:heading>
                @foreach ($this->projects as $entry)
                    <button type="button" wire:key="vault-project-{{ $entry['project']->uuid }}" wire:click="show('project', '{{ $entry['project']->uuid }}')" @class([
                        'flex items-center gap-2 rounded-md px-2 py-1 text-start text-sm',
                        'bg-zinc-100 font-medium dark:bg-zinc-800' => $view === 'project' && $project === $entry['project']->uuid,
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! ($view === 'project' && $project === $entry['project']->uuid),
                    ])>
                        <flux:icon.rectangle-stack variant="micro" class="size-4 text-zinc-400" />
                        <span class="flex-1 truncate">{{ $entry['project']->name }}</span>
                        <span class="text-xs text-zinc-400 tabular-nums">{{ $entry['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
        </div>
    </aside>

    {{-- Items --}}
    <section class="flex min-w-0 flex-1 flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg" class="flex-1">{{ trim($search) !== '' ? __('Search') : $heading }}</flux:heading>
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.250ms="search" :placeholder="__('Search everything…')" class="max-w-64" clearable />
            @if ($canCreate)
                <flux:button size="sm" variant="primary" icon="plus" wire:click="newItem">{{ __('New item') }}</flux:button>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="table-cards w-full text-sm" x-data="{ shown: {}, copied: null,
                async copy(id, field) { const value = await $wire.reveal(id, field); if (value) { await navigator.clipboard.writeText(value); this.copied = id + field; setTimeout(() => this.copied = null, 1500) } },
                async toggle(id) { if (this.shown[id] !== undefined) { delete this.shown[id]; return } this.shown[id] = await $wire.reveal(id, 'password') ?? '' } }">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Item') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Username') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Password') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Shared') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->items as $item)
                        @php($mine = $item->isOwnedBy(auth()->user()))
                        <tr wire:key="vault-item-{{ $item->uuid }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-2" data-cell="main">
                                <button type="button" class="text-start" wire:click="open('{{ $item->uuid }}')">
                                    <div class="flex items-center gap-1.5 font-medium">
                                        {{ $item->title }}
                                        @if ($item->totp_secret)<flux:icon.clock variant="micro" class="size-3.5 text-zinc-400" title="{{ __('Two-factor code') }}" />@endif
                                    </div>
                                    @if ($item->url)<div class="max-w-64 truncate text-xs text-zinc-500">{{ parse_url($item->url, PHP_URL_HOST) ?: $item->url }}</div>@endif
                                </button>
                            </td>
                            <td class="px-4 py-2" data-cell="wide">
                                @if ($item->username)
                                    <div class="flex items-center gap-1">
                                        <span class="max-w-48 truncate font-mono text-xs">{{ $item->username }}</span>
                                        <button type="button" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" x-on:click="copy('{{ $item->uuid }}', 'username')" title="{{ __('Copy') }}">
                                            <flux:icon.clipboard-document variant="micro" class="size-4" x-show="copied !== '{{ $item->uuid }}username'" />
                                            <flux:icon.check variant="micro" class="size-4 text-green-500" x-show="copied === '{{ $item->uuid }}username'" />
                                        </button>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2" data-cell="wide">
                                @if ($item->password)
                                    <div class="flex items-center gap-1">
                                        <span class="font-mono text-xs" x-text="shown['{{ $item->uuid }}'] ?? '••••••••••'"></span>
                                        <button type="button" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" x-on:click="toggle('{{ $item->uuid }}')" title="{{ __('Show') }}">
                                            <flux:icon.eye variant="micro" class="size-4" />
                                        </button>
                                        <button type="button" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" x-on:click="copy('{{ $item->uuid }}', 'password')" title="{{ __('Copy') }}">
                                            <flux:icon.clipboard-document variant="micro" class="size-4" x-show="copied !== '{{ $item->uuid }}password'" />
                                            <flux:icon.check variant="micro" class="size-4 text-green-500" x-show="copied === '{{ $item->uuid }}password'" />
                                        </button>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2" data-cell="wide">
                                <div class="flex flex-wrap items-center gap-1">
                                    @unless ($mine)<flux:badge size="sm" :title="__('Owner')">{{ $item->user->handle ?: $item->user->name }}</flux:badge>@endunless
                                    @if ($item->project)<flux:badge size="sm" color="sky" icon="rectangle-stack">{{ $item->project->name }} · {{ implode(', ', $item->project_roles ?? []) ?: __('admins only') }}</flux:badge>@endif
                                    @if ($item->shared_with_count)<flux:badge size="sm" color="violet" icon="users">{{ $item->shared_with_count }}</flux:badge>@endif
                                </div>
                            </td>
                            <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="open('{{ $item->uuid }}')">{{ __('Open') }}</flux:menu.item>
                                        @if ($item->canEdit(auth()->user()))
                                            <flux:menu.item icon="pencil-square" wire:click="editItem('{{ $item->uuid }}')">{{ __('Edit') }}</flux:menu.item>
                                        @endif
                                        @if ($item->canManage(auth()->user()))
                                            <flux:menu.item icon="share" wire:click="sharing('{{ $item->uuid }}')">{{ __('Sharing') }}</flux:menu.item>
                                        @endif
                                        @if ($mine && $this->folders->isNotEmpty())
                                            <flux:menu.submenu :heading="__('Move to')">
                                                <flux:menu.item wire:click="moveItem('{{ $item->uuid }}', null)">{{ __('Top level') }}</flux:menu.item>
                                                @foreach ($this->folders as $target)
                                                    <flux:menu.item wire:click="moveItem('{{ $item->uuid }}', '{{ $target->uuid }}')">{{ $target->name }}</flux:menu.item>
                                                @endforeach
                                            </flux:menu.submenu>
                                        @endif
                                        @if ($item->canManage(auth()->user()))
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteItem('{{ $item->uuid }}')" wire:confirm="{{ __('Remove :title? Everyone it is shared with loses it too.', ['title' => $item->title]) }}">{{ __('Remove') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">
                                {{ trim($search) !== '' ? __('Nothing matches.') : ($canCreate ? __('No items here. Add one with “New item”.') : __('Nothing has been shared with you yet.')) }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Folder name --}}
    <flux:modal name="vault-folder" class="md:w-96">
        <form wire:submit="saveFolder" class="space-y-5">
            <flux:heading size="lg">{{ $folderEditing ? __('Rename folder') : __('New folder') }}</flux:heading>
            <flux:input wire:model="folder_name" :label="__('Name')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- New / edit item --}}
    <flux:modal name="vault-item" class="md:w-[34rem]">
        <form wire:submit="saveItem" class="space-y-4" x-data="{
            length: 20, symbols: true, digits: true,
            generate() {
                const sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ'];
                if (this.digits) sets.push('23456789');
                if (this.symbols) sets.push('!@#$%^&*()-_=+[]{};:,.?');
                const all = sets.join('');
                const random = (n) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n };
                const chars = sets.map((s) => s[random(s.length)]);
                while (chars.length < this.length) chars.push(all[random(all.length)]);
                for (let i = chars.length - 1; i > 0; i--) { const j = random(i + 1); [chars[i], chars[j]] = [chars[j], chars[i]] }
                $wire.item_password = chars.join('');
            },
            strength(p) {
                if (!p) return 0;
                let pool = 0;
                if (/[a-z]/.test(p)) pool += 26; if (/[A-Z]/.test(p)) pool += 26; if (/[0-9]/.test(p)) pool += 10; if (/[^A-Za-z0-9]/.test(p)) pool += 32;
                const bits = p.length * Math.log2(pool || 1);
                return bits < 40 ? 1 : bits < 60 ? 2 : bits < 90 ? 3 : 4;
            },
        }">
            <flux:heading size="lg">{{ $itemEditing ? __('Edit item') : __('New item') }}</flux:heading>
            <flux:input wire:model="item_title" :label="__('Title')" :placeholder="__('Hosting panel, database, mailbox…')" required />
            <flux:input wire:model="item_url" :label="__('URL')" placeholder="https://" />
            <flux:input wire:model="item_username" :label="__('Username')" autocomplete="off" />
            <flux:field>
                <flux:label>{{ __('Password') }}</flux:label>
                <div class="flex gap-2">
                    <flux:input wire:model.live.debounce.300ms="item_password" type="password" autocomplete="new-password" viewable class="font-mono" />
                    <flux:button icon="sparkles" x-on:click="generate()" :tooltip="__('Generate')" />
                </div>
                <div class="mt-1 flex items-center gap-3 text-xs text-zinc-500">
                    <div class="flex h-1.5 w-24 gap-0.5">
                        <template x-for="i in 4">
                            <div class="flex-1 rounded-full" :class="strength($wire.item_password) >= i ? ['', 'bg-red-500', 'bg-amber-500', 'bg-lime-500', 'bg-green-500'][strength($wire.item_password)] : 'bg-zinc-200 dark:bg-zinc-700'"></div>
                        </template>
                    </div>
                    <label class="flex items-center gap-1">{{ __('Length') }} <input type="number" min="8" max="64" x-model.number="length" class="w-12 rounded border border-zinc-200 bg-transparent px-1 dark:border-zinc-600" /></label>
                    <label class="flex items-center gap-1"><input type="checkbox" x-model="digits" /> 0-9</label>
                    <label class="flex items-center gap-1"><input type="checkbox" x-model="symbols" /> !@#</label>
                </div>
            </flux:field>
            <flux:input wire:model="item_totp" :label="__('Two-factor secret')" :description="__('Optional: the base32 secret or otpauth:// link of the authenticator setup; Studio then shows the current code.')" autocomplete="off" class="font-mono" />
            <flux:textarea wire:model="item_notes" :label="__('Notes')" rows="3" />
            @if ($canCreate && $itemOwned)
                <flux:select wire:model="item_folder" :label="__('Folder')">
                    <flux:select.option value="">{{ __('Top level') }}</flux:select.option>
                    @foreach ($this->folders as $target)
                        <flux:select.option :value="$target->uuid">{{ $target->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Item details --}}
    <flux:modal name="vault-show" class="md:w-[34rem]">
        @if ($shownItem)
            <div class="space-y-4" wire:key="vault-show-{{ $shownItem->uuid }}" x-data="{
                values: {}, copied: null, code: null, remaining: 0, timer: null,
                async reveal(field) { if (this.values[field] !== undefined) { delete this.values[field]; return } this.values[field] = await $wire.reveal('{{ $shownItem->uuid }}', field) ?? '' },
                async copy(field) { const v = await $wire.reveal('{{ $shownItem->uuid }}', field); if (v) { await navigator.clipboard.writeText(v); this.copied = field; setTimeout(() => this.copied = null, 1500) } },
                async totp() { const r = await $wire.totp('{{ $shownItem->uuid }}'); if (!r) return; this.code = r.code; this.remaining = r.remaining; clearInterval(this.timer); this.timer = setInterval(() => { if (--this.remaining <= 0) this.totp() }, 1000) },
                init() { @if ($shownItem->totp_secret) this.totp() @endif },
                destroy() { clearInterval(this.timer) },
            }">
                <div>
                    <flux:heading size="lg">{{ $shownItem->title }}</flux:heading>
                    <flux:text class="mt-1 text-xs">
                        {{ __('Owner: :name', ['name' => $shownItem->user->name]) }}
                        @if ($shownItem->editor) · {{ __('changed by :name :when', ['name' => $shownItem->editor->name, 'when' => $shownItem->updated_at?->diffForHumans()]) }}@endif
                    </flux:text>
                </div>
                <dl class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                    @if ($shownItem->url)
                        <div class="flex items-center gap-3 px-3 py-2">
                            <dt class="w-24 shrink-0 text-xs text-zinc-500">{{ __('URL') }}</dt>
                            <dd class="min-w-0 flex-1 truncate"><flux:link :href="$shownItem->url" target="_blank" rel="noopener noreferrer">{{ $shownItem->url }}</flux:link></dd>
                        </div>
                    @endif
                    @foreach (['username' => __('Username'), 'password' => __('Password'), 'notes' => __('Notes')] as $field => $label)
                        @if ($shownItem->{$field})
                            <div class="flex items-start gap-3 px-3 py-2">
                                <dt class="w-24 shrink-0 pt-0.5 text-xs text-zinc-500">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1 break-all font-mono text-xs" :class="'{{ $field }}' === 'notes' ? 'whitespace-pre-wrap font-sans text-sm' : ''" x-text="values['{{ $field }}'] ?? '••••••••••'"></dd>
                                <div class="flex shrink-0 gap-1">
                                    <flux:button size="xs" variant="ghost" icon="eye" x-on:click="reveal('{{ $field }}')" />
                                    <flux:button size="xs" variant="ghost" icon="clipboard-document" x-on:click="copy('{{ $field }}')" />
                                </div>
                            </div>
                        @endif
                    @endforeach
                    @if ($shownItem->totp_secret)
                        <div class="flex items-center gap-3 px-3 py-2">
                            <dt class="w-24 shrink-0 text-xs text-zinc-500">{{ __('2FA code') }}</dt>
                            <dd class="flex flex-1 items-center gap-2">
                                <span class="font-mono text-lg tracking-widest" x-text="code ? code.slice(0, 3) + ' ' + code.slice(3) : '…'"></span>
                                <span class="text-xs text-zinc-500 tabular-nums" x-text="remaining + 's'"></span>
                            </dd>
                            <flux:button size="xs" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText(code); copied = 'totp'; setTimeout(() => copied = null, 1500)" />
                        </div>
                    @endif
                </dl>
                <div class="flex flex-wrap items-center gap-1">
                    @if ($shownItem->project)<flux:badge size="sm" color="sky" icon="rectangle-stack">{{ $shownItem->project->name }} · {{ implode(', ', $shownItem->project_roles ?? []) ?: __('admins only') }}</flux:badge>@endif
                    @foreach ($shownItem->sharedWith as $person)<flux:badge size="sm" color="violet">{{ $person->name }}{{ $person->pivot?->getAttribute('can_edit') ? ' · '.__('can edit') : '' }}</flux:badge>@endforeach
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs text-green-600" x-show="copied" x-transition>{{ __('Copied') }}</span>
                    <div class="ms-auto flex gap-2">
                        @if ($shownItem->canManage(auth()->user()))
                            <flux:button size="sm" variant="ghost" icon="share" wire:click="sharing('{{ $shownItem->uuid }}')">{{ __('Sharing') }}</flux:button>
                        @endif
                        @if ($shownItem->canEdit(auth()->user()))
                            <flux:button size="sm" icon="pencil-square" wire:click="editItem('{{ $shownItem->uuid }}')">{{ __('Edit') }}</flux:button>
                        @endif
                        <flux:modal.close><flux:button size="sm" variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Sharing --}}
    <flux:modal name="vault-sharing" class="md:w-[40rem]">
        @if ($shownItem && $shownItem->canManage(auth()->user()))
            <div class="space-y-6" wire:key="vault-sharing-{{ $shownItem->uuid }}">
                <div>
                    <flux:heading size="lg">{{ __('Sharing: :title', ['title' => $shownItem->title]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Changes apply at once.') }}</flux:text>
                </div>

                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('Project') }}</flux:heading>
                    <x-search-picker
                        wire:key="vault-project-picker-{{ $shownItem->uuid }}"
                        search="searchProjects"
                        select="pickShareProject"
                        :selected="$shownItem->project ? ['id' => $shownItem->project->uuid, 'label' => $shownItem->project->name] : null"
                        :placeholder="__('Search a project…')"
                    />
                    @if ($shownItem->project)
                        <div class="flex flex-wrap items-center gap-4 pt-1">
                            <flux:text class="text-xs">{{ __('Members who see it:') }}</flux:text>
                            @foreach (['client' => __('Client'), 'pm' => __('PM'), 'dev' => __('Developer')] as $role => $label)
                                <flux:checkbox wire:model.live="share_roles" :value="$role" :label="$label" />
                            @endforeach
                        </div>
                        <flux:text class="text-xs">{{ __('Administrators always see project items.') }}</flux:text>
                    @endif
                </div>

                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('People') }}</flux:heading>
                    <x-search-picker
                        wire:key="vault-user-picker-{{ $shownItem->uuid }}"
                        search="searchUsers"
                        select="addShare"
                        :keep="false"
                        :clearable="false"
                        :placeholder="__('Add a person: name, email…')"
                    />
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ __('Who') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('Through') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('Access') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($shownItem->audience() as $entry)
                                <tr wire:key="vault-audience-{{ $entry['user']->uuid }}">
                                    <td class="px-3 py-1.5">
                                        <div>{{ $entry['user']->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $entry['user']->role->label() }}</div>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-zinc-500">
                                        {{ match ($entry['via']) { 'owner' => __('owner'), 'direct' => __('shared directly'), 'admin' => __('administrator'), default => __('project role') } }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        @if ($entry['via'] === 'direct')
                                            <flux:select size="sm" wire:change="setShareEdit('{{ $entry['user']->uuid }}', $event.target.value === '1')">
                                                <flux:select.option value="0" :selected="! $entry['can_edit']">{{ __('can view') }}</flux:select.option>
                                                <flux:select.option value="1" :selected="$entry['can_edit']">{{ __('can edit') }}</flux:select.option>
                                            </flux:select>
                                        @else
                                            <span class="text-xs">{{ $entry['can_edit'] ? __('can edit') : __('can view') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-end">
                                        @if ($entry['via'] === 'direct')
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeShare('{{ $entry['user']->uuid }}')" :tooltip="__('Stop sharing')" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
