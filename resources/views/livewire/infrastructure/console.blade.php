<div class="flex h-full w-full flex-1 flex-col gap-6 max-sm:gap-4">
    @if ($compact)
    {{-- One thing (a resource of a project, a project's repository): what it is, where it lives, read again --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <flux:icon :name="$definition->icon()" class="mt-0.5 size-6 shrink-0 text-zinc-400" />
            <div class="min-w-0">
                <flux:heading size="lg" class="truncate">
                    @if (! empty($compact['href']))
                        <a href="{{ $compact['href'] }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $compact['title'] }}</a>
                    @else
                        {{ $compact['title'] }}
                    @endif
                </flux:heading>
                <flux:text class="text-xs">{{ $compact['subtitle'] }}</flux:text>
            </div>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refresh" :tooltip="__('Read again from :provider', ['provider' => $definition->label()])" />
    </div>
    @else
    {{-- Connector, account, refresh --}}
    <div class="flex items-start justify-between gap-4 max-md:flex-col max-md:gap-3">
        <div class="flex items-start gap-3">
            <flux:icon :name="$definition->icon()" class="mt-1 size-7 shrink-0 text-zinc-400" />
            <div>
                <flux:heading size="xl">{{ $definition->label() }}</flux:heading>
                <flux:text class="mt-1">{{ $definition->description() }}</flux:text>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($this->accounts->isNotEmpty())
                <div class="min-w-48">
                    <flux:select size="sm" wire:change="useAccount($event.target.value)" :aria-label="__('Account')">
                        @foreach ($this->accounts as $option)
                            <flux:select.option value="{{ $option->uuid }}" :selected="$option->uuid === $current?->uuid">{{ $personal ? $option->name.' · '.($option->host ?: \App\Infrastructure\Git\Forges::make($option->connector)->label()) : $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refresh" :tooltip="__('Read again from :provider', ['provider' => $definition->label()])" />
            @endif
            @if ($personal)
                <flux:button size="sm" icon="user-circle" :href="route('connections.edit', ['tab' => 'git'])" wire:navigate>{{ __('My accounts') }}</flux:button>
            @elseif ($isAdmin)
                <flux:button size="sm" icon="user-circle" wire:click="manageAccounts">{{ __('Accounts') }}</flux:button>
            @endif
        </div>
    </div>
    @endif

    @if ($this->accounts->isEmpty())
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.heading>{{ $personal ? __('No git account yet') : __('No :provider account yet', ['provider' => $definition->label()]) }}</flux:callout.heading>
            <flux:callout.text>
                @if ($personal)
                    {{ __('Add your accounts in Settings → Accounts, as many as you work with (personal, work, a client\'s) and on any host: each with its own token and the name and email its commits carry. Only you see and use them.') }}
                @elseif ($isAdmin)
                    {{ __('Add one or more accounts: Studio stores their credentials encrypted and talks to the API with them.') }}
                @else
                    {{ __('An administrator adds the accounts Studio uses here.') }}
                @endif
            </flux:callout.text>
            @if ($personal)
                <x-slot name="actions">
                    <flux:button size="sm" variant="primary" icon="plus" :href="route('connections.edit', ['tab' => 'git'])" wire:navigate>{{ __('Add an account') }}</flux:button>
                </x-slot>
            @elseif ($isAdmin)
                <x-slot name="actions">
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="manageAccounts">{{ __('Add an account') }}</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @else
        {{-- Tabs, or the way back from a child list --}}
        @if ($parent)
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="back">{{ $parent->label }}</flux:button>
                <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-400" />
                <span class="font-mono">{{ $scope[$section->parentLabel] ?? '' }}</span>
                <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-400" />
                <span class="font-medium">{{ $section->label }}</span>
            </div>
        @elseif (count($tabs) > 0)
            @if (count($groups) > 1)
                <div class="-mx-1 -mb-3 flex gap-1 overflow-x-auto px-1" role="group" aria-label="{{ __('Groups') }}">
                    @foreach ($groups as $name)
                        <flux:button size="sm" :variant="$name === $group ? 'filled' : 'ghost'" wire:click="selectGroup({{ Js::from($name) }})" wire:key="group-{{ md5($name) }}" class="shrink-0">{{ $name }}</flux:button>
                    @endforeach
                </div>
            @endif
            <nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-zinc-200 px-1 dark:border-zinc-700" aria-label="{{ $definition->label() }}">
                @foreach ($tabs as $tabSection)
                    <button type="button" wire:click="select(@js($tabSection->key))" wire:key="tab-{{ $tabSection->key }}" @class([
                        'flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                        'border-zinc-800 font-medium text-zinc-900 dark:border-white dark:text-white' => $tabSection->key === $section->key,
                        'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tabSection->key !== $section->key,
                    ])>
                        <flux:icon :name="$tabSection->icon" variant="micro" class="size-4" />{{ $tabSection->label }}
                    </button>
                @endforeach
            </nav>
        @endif

        @if ($section->description)
            <flux:text class="-mt-3 text-xs">{{ $section->description }}</flux:text>
        @endif

        {{-- Search, filters, list actions --}}
        @php($toolbar = $this->toolbarActions($section))
        @if ($section->kind === 'table' || $section->filters !== [] || $toolbar !== [])
            <div class="flex flex-wrap items-end gap-2">
                @if ($section->kind === 'table' && ! ($pinned && ! $parent))
                    <div class="w-full sm:w-64">
                        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.250ms="search" :placeholder="__('Search')" clearable />
                    </div>
                @endif
                @foreach ($pinned && ! $parent ? [] : $section->filters as $filter)
                    @php($choices = $this->options($filter))
                    <div class="min-w-40">
                        <flux:select size="sm" wire:model.live="filters.{{ $filter->name }}" :aria-label="$filter->label">
                            @unless (array_key_exists('', $choices['options']))
                                <flux:select.option value="">{{ $filter->label }}: {{ $filter->help ?? __('any') }}</flux:select.option>
                            @endunless
                            @foreach ($choices['options'] as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $filter->label }}: {{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endforeach
                <div class="ms-auto flex flex-wrap gap-2">
                    @foreach ($toolbar as $action)
                        {{-- An action with a form asks for confirmation when the form is sent. --}}
                        @if (! $action->hasForm() && $action->confirm)
                            <flux:button size="sm" :icon="$action->icon" :variant="$action->primary ? 'primary' : null" wire:click="start({{ Js::from($action->key) }})" wire:confirm="{{ $action->confirm }}">{{ $action->label }}</flux:button>
                        @else
                            <flux:button size="sm" :icon="$action->icon" :variant="$action->primary ? 'primary' : null" wire:click="start({{ Js::from($action->key) }})">{{ $action->label }}</flux:button>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        @if ($error)
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __(':provider did not answer as expected', ['provider' => $definition->label()]) }}</flux:callout.heading>
                <flux:callout.text>{{ $error }}</flux:callout.text>
                @if ($pinned && ! auth()->user()->isAdmin())
                    <flux:callout.text>{{ __('It is read with the team\'s account: tell an administrator.') }}</flux:callout.text>
                @endif
            </flux:callout>
        @endif

        <div wire:loading.delay.class="opacity-50" wire:target="select, useAccount, refresh, back, filters, start, submit">
            @if ($section->kind === 'info')
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($rows as $section => $values)
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="info-{{ md5((string) $section) }}">
                            <flux:heading size="sm">{{ $section }}</flux:heading>
                            @if (is_array($values))
                                <dl class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                                    @forelse ($values as $label => $value)
                                        <div class="flex items-baseline justify-between gap-4 py-1.5">
                                            <dt class="text-zinc-500">{{ is_int($label) ? '' : $label }}</dt>
                                            <dd class="min-w-0 text-end font-mono text-xs break-all">{{ match (true) { is_bool($value) => $value ? __('yes') : __('no'), $value === null => '—', is_scalar($value) => $value, default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) } }}</dd>
                                        </div>
                                    @empty
                                        <flux:text class="py-1.5 text-xs">{{ __('Nothing here.') }}</flux:text>
                                    @endforelse
                                </dl>
                            @else
                                <div class="mt-2 font-mono text-sm">{{ $values }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif (! $error)
                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="table-cards w-full text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                @foreach ($section->columns as $column)
                                    <th class="px-4 py-2 text-start whitespace-nowrap">{{ $column->label }}</th>
                                @endforeach
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @forelse ($rows as $row)
                                @php($id = $section->rowId($row))
                                @php($actions = $this->rowActions($section, $row))
                                @php($links = $attached->get($id, collect()))
                                <tr wire:key="row-{{ md5($section->key.'|'.$id) }}">
                                    @foreach ($section->columns as $column)
                                        @php($text = $column->text($row))
                                        @php($href = $column->href($row))
                                        <td class="px-4 py-2 align-middle" data-cell="{{ $loop->first ? 'main' : 'meta' }}" @unless ($loop->first) data-label="{{ $column->label }}" @endunless>
                                            @if ($href && $text !== '')
                                                <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" @class(['underline decoration-zinc-300 underline-offset-4 hover:decoration-zinc-500 dark:decoration-zinc-600', 'font-medium' => $loop->first])>{{ \Illuminate\Support\Str::limit($text, 160) }}</a>
                                            @elseif ($text === '')
                                                <span class="text-zinc-400">—</span>
                                            @elseif ($column->format === 'badge')
                                                <flux:badge size="sm" :color="$column->color($row)">{{ $text }}</flux:badge>
                                            @elseif ($column->format === 'bool')
                                                <flux:badge size="sm" :color="$column->value($row) ? 'green' : 'zinc'">{{ $text }}</flux:badge>
                                            @elseif ($column->format === 'mono')
                                                <span @class(['font-mono text-xs', 'whitespace-nowrap' => mb_strlen($text) <= 40, 'break-all' => mb_strlen($text) > 40])>{{ \Illuminate\Support\Str::limit($text, 160) }}</span>
                                            @elseif ($column->format === 'date')
                                                <span class="whitespace-nowrap text-xs text-zinc-500" title="{{ $column->value($row) }}">{{ $text }}</span>
                                            @else
                                                <span @class(['font-medium' => $loop->first, 'xl:whitespace-nowrap' => $loop->first && mb_strlen($text) <= 32])>{{ \Illuminate\Support\Str::limit($text, 160) }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions">
                                        @foreach ($links as $link)
                                            <flux:badge size="sm" icon="link" color="sky" as="button" wire:click="attach({{ Js::from($id) }}, {{ Js::from($link->project->uuid) }})" :title="__('Attached to :project: change or detach', ['project' => $link->project->name])" class="cursor-pointer">{{ $link->project->name }}</flux:badge>
                                        @endforeach
                                        @foreach ($actions as $action)
                                            @if ($action->opens)
                                                <flux:button size="xs" variant="ghost" :icon="$action->icon" wire:click="start({{ Js::from($action->key) }}, {{ Js::from($id) }})">{{ $action->label }}</flux:button>
                                            @endif
                                        @endforeach
                                        @php($menu = array_filter($actions, fn ($action) => ! $action->opens))
                                        @if ($menu !== [] || $canAttach)
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                                                <flux:menu>
                                                    @if ($canAttach)
                                                        <flux:menu.item icon="link" wire:click="attach({{ Js::from($id) }})">{{ __('Attach to a project…') }}</flux:menu.item>
                                                        @if ($menu !== [])<flux:menu.separator />@endif
                                                    @endif
                                                    @foreach ($menu as $action)
                                                        @if ($action->danger && ! $loop->first)
                                                            <flux:menu.separator />
                                                        @endif
                                                        @if (! $action->hasForm() && $action->confirm)
                                                            <flux:menu.item :icon="$action->icon" :variant="$action->danger ? 'danger' : null" wire:click="start({{ Js::from($action->key) }}, {{ Js::from($id) }})" wire:confirm="{{ $action->confirm }}">{{ $action->label }}</flux:menu.item>
                                                        @else
                                                            <flux:menu.item :icon="$action->icon" :variant="$action->danger ? 'danger' : null" wire:click="start({{ Js::from($action->key) }}, {{ Js::from($id) }})">{{ $action->label }}</flux:menu.item>
                                                        @endif
                                                    @endforeach
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($section->columns) + 1 }}" class="px-4 py-8 text-center text-zinc-500">
                                        {{ trim($search) !== '' ? __('Nothing matches the search.') : __('Nothing here yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (count($rows) > 0)
                    <flux:text class="mt-2 text-xs">{{ trans_choice(':count item|:count items', count($rows)) }} · {{ __('read from :provider, fresh for two minutes', ['provider' => $definition->label()]) }}</flux:text>
                @endif
            @endif
        </div>
    @endif

    {{-- The form of an action --}}
    <flux:modal name="infrastructure-action" class="md:w-[34rem]">
        <form wire:submit="submit" class="space-y-5" @if ($formConfirm) wire:confirm="{{ $formConfirm }}" @endif>
            <flux:heading size="lg">{{ $formLabel }}</flux:heading>
            @foreach ($formFields as $field)
                <div wire:key="form-{{ $formAction }}-{{ $field->name }}">
                    @include('livewire.infrastructure.partials.field', ['field' => $field, 'model' => 'form.'.$field->name])
                </div>
            @endforeach
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Go') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- What an action left to read --}}
    <flux:modal name="infrastructure-result" class="w-full md:w-[48rem]">
        <div class="space-y-4" x-data="{ copied: false }">
            <flux:heading size="lg" class="pe-8">{{ $resultTitle }}</flux:heading>
            @if ($resultPoll)
                <flux:callout icon="clock" variant="secondary">
                    <flux:callout.text>{{ __(':provider is still working on it in the background.', ['provider' => $definition->label()]) }}</flux:callout.text>
                </flux:callout>
            @endif
            @if (filled($resultText))
                <pre x-ref="result" class="max-h-[60vh] overflow-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs whitespace-pre-wrap break-all dark:bg-zinc-900">{{ $resultText }}</pre>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                @if (filled($resultText))
                    <flux:button variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.result.innerText); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </flux:button>
                @endif
                @if ($resultPoll)
                    <flux:button icon="arrow-path" wire:click="poll">{{ __('Check again') }}</flux:button>
                @endif
                <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Attach a row to a project, for administrators --}}
    @if ($canAttach || $attachRow)
        <flux:modal name="infrastructure-attach" class="w-full md:w-[40rem]">
            <form wire:submit="saveAttachment" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Attach to a project') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('It shows in the Infrastructure tab of the project, read with this account, to the roles that see it; the roles that act on it run only the actions ticked. Nobody but the administrators sees the account, its credentials or the rest of :provider.', ['provider' => $definition->label()]) }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="attach_project" :label="__('Project')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($projects as $project)
                            <flux:select.option value="{{ $project->uuid }}">{{ $project->name }} · {{ $project->slug }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="attach_label" :label="__('Name in the project')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:checkbox.group wire:model="attach_view" :label="__('Who sees it')">
                        @foreach (\App\Enums\UserRole::projectRoles() as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:checkbox.group wire:model="attach_act" :label="__('Who acts on it')">
                        @foreach (\App\Enums\UserRole::projectRoles() as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </flux:checkbox.group>
                </div>
                @if ($attachActions !== [])
                    <flux:checkbox.group wire:model="attach_actions" :label="__('What they may do')" :description="__('Lists it opens (the records of a zone, the exceptions of a project) come with it to whoever sees it; changing anything is one of these actions.')">
                        <div class="grid max-h-64 gap-x-4 gap-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 sm:grid-cols-2 dark:border-zinc-700">
                            @foreach ($attachActions as $key => $item)
                                <flux:checkbox :value="$key" :label="$item['label']" :description="collect([str_contains($key, '.') ? $item['list'] : null, $item['danger'] ? __('destructive') : null])->filter()->implode(' · ') ?: null" wire:key="attach-action-{{ md5($key) }}" />
                            @endforeach
                        </div>
                    </flux:checkbox.group>
                @else
                    <flux:text class="text-xs">{{ __('This list has no actions to hand over: whoever sees it reads it.') }}</flux:text>
                @endif
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        @if ($attachRow && $attach_project && $attached->get($attachRow, collect())->contains(fn ($r) => $r->project->uuid === $attach_project))
                            <flux:button variant="ghost" icon="link-slash" wire:click="detach({{ Js::from($attachRow) }}, {{ Js::from($attach_project) }})" wire:confirm="{{ __('Take it off the project? Nothing changes at :provider.', ['provider' => $definition->label()]) }}">{{ __('Detach') }}</flux:button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Accounts, for administrators --}}
    @if ($isAdmin)
        <flux:modal name="infrastructure-accounts" class="w-full md:w-[40rem]">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $personal ? __('My :provider accounts', ['provider' => $definition->label()]) : __(':provider accounts', ['provider' => $definition->label()]) }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Credentials are stored encrypted and never shown again; Studio checks them before saving.') }}
                        @if ($personal)
                            {{ __('Only you see your accounts; each one commits with its own name and email.') }}
                        @endif
                        <a href="{{ $definition->guide() }}" target="_blank" rel="noopener" class="underline decoration-zinc-400 underline-offset-4">{{ __('Which credentials, where, with which permissions') }}</a>
                        · <a href="{{ $definition->docs() }}" target="_blank" rel="noopener" class="underline decoration-zinc-400 underline-offset-4">{{ __(':provider\'s documentation', ['provider' => $definition->label()]) }}</a>
                    </flux:text>
                </div>

                @if ($this->accounts->isNotEmpty())
                    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($this->accounts as $item)
                            <li class="flex flex-wrap items-center gap-3 px-4 py-2" wire:key="account-{{ $item->uuid }}">
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    @if ($personal && ($item->login || $item->git_email))
                                        <div class="truncate text-xs text-zinc-500">{{ collect([$item->login ? '@'.$item->login : null, $item->host, $item->git_email ? ($item->git_name ?: '').' <'.$item->git_email.'>' : null])->filter()->implode(' · ') }}</div>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                                        <flux:badge size="sm" :color="$item->isHealthy() ? 'green' : 'red'">{{ $item->isHealthy() ? __('working') : __('failing') }}</flux:badge>
                                        <span class="truncate">{{ \Illuminate\Support\Str::limit((string) $item->status, 120) }}</span>
                                        @if ($item->checked_at)<span>· {{ $item->checked_at->diffForHumans() }}</span>@endif
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <flux:button size="xs" variant="ghost" icon="signal" :tooltip="__('Check')" wire:click="testAccount({{ Js::from($item->uuid) }})" />
                                    <flux:button size="xs" variant="ghost" icon="pencil" :tooltip="__('Edit')" wire:click="editAccount({{ Js::from($item->uuid) }})" />
                                    <flux:button size="xs" variant="ghost" icon="trash" :tooltip="__('Remove')" wire:click="removeAccount({{ Js::from($item->uuid) }})" wire:confirm="{{ __('Remove :name from Studio?', ['name' => $item->name]) }}" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($accountEditing)
                    <form wire:submit="saveAccount" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading>{{ $accountEditing === 'new' ? __('New account') : __('Edit :name', ['name' => $account_name]) }}</flux:heading>
                        <flux:input wire:model="account_name" :label="__('Name')" :placeholder="$personal ? __('Personal, Work, Client X…') : __('Production, Client X…')" required />
                        @foreach ($definition->fields() as $field)
                            <div wire:key="credential-{{ $field->name }}">
                                @include('livewire.infrastructure.partials.field', ['field' => $accountEditing !== 'new' && $field->secret ? (clone $field)->help(__('Leave empty to keep the stored one.')) : $field, 'model' => 'credentials.'.$field->name])
                            </div>
                        @endforeach
                        @if ($personal)
                            @include('livewire.tools.partials.git-token-help', ['forge' => $definition->key(), 'admin' => auth()->user()->isAdmin()])
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="git_name" :label="__('Commit name')" :placeholder="__('From the provider')" />
                                <flux:input type="email" wire:model="git_email" :label="__('Commit email')" :placeholder="__('From the provider')" />
                            </div>
                            <flux:text class="-mt-2 text-xs">{{ __('What commits made with this account carry. Empty: the name and the primary email the provider gives, or yours.') }}</flux:text>
                        @endif
                        @error('credentials')
                            <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
                        @enderror
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelAccount">{{ __('Cancel') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ __('Check and save') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex justify-between gap-2">
                        <flux:button icon="plus" wire:click="editAccount">{{ __('Add an account') }}</flux:button>
                        <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif
</div>
