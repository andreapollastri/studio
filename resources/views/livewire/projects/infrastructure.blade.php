<div class="h-full overflow-y-auto">
    <x-slot:header>
        <x-studio.project-title :project="$project" />
    </x-slot:header>

    <x-slot:subnav>
        <x-studio.project-tabs :project="$project" current="infrastructure" />
    </x-slot:subnav>

    <div class="mx-auto flex max-w-7xl gap-6 p-6 max-lg:flex-col max-md:p-4">
        {{-- What is attached to the project --}}
        <aside class="w-72 shrink-0 max-lg:w-full">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Infrastructure') }}</flux:heading>
                @if ($connectors !== [])
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" icon="plus" icon:trailing="chevron-down">{{ __('Attach') }}</flux:button>
                        <flux:menu>
                            <flux:menu.item icon="link" wire:click="startAttach">{{ __('Pick from an account…') }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.heading>{{ __('Or browse a connector') }}</flux:menu.heading>
                            @foreach ($connectors as $connector)
                                <flux:menu.item :icon="$connector->icon()" :href="route('infrastructure', $connector->key())" wire:navigate>{{ $connector->label() }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
            <flux:text class="mt-1 text-xs">{{ __('The servers and services of this project, read with the team\'s accounts. You see what your role sees, and run what it may run.') }}</flux:text>

            <button type="button" wire:click="open('overview')" @class([
                'mt-4 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition',
                'bg-zinc-100 font-medium dark:bg-zinc-800' => $overview,
                'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' => ! $overview,
            ])>
                <flux:icon name="squares-2x2" variant="micro" class="size-4 text-zinc-500" />{{ __('Overview') }}
            </button>

            @if ($resources->isEmpty())
                <div class="mt-4 rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 dark:border-zinc-700">
                    @if (auth()->user()->isAdmin())
                        {{ __('Nothing attached yet. “Attach” picks the server, zone, Cipi app, bucket or tracker of this project from the team\'s accounts.') }}
                    @else
                        {{ __('Nothing here for your role yet.') }}
                    @endif
                </div>
            @else
                <ul class="mt-4 space-y-1">
                    @foreach ($resources->groupBy(fn ($r) => $r->account->connector) as $key => $group)
                        @php($connector = $group->first()->connector())
                        <li class="pt-2 first:pt-0">
                            <div class="flex items-center gap-1.5 px-2 text-xs font-medium uppercase tracking-wide text-zinc-500">
                                <flux:icon :name="$connector->icon()" variant="micro" class="size-3.5" />{{ $connector->label() }}
                            </div>
                        </li>
                        @foreach ($group as $item)
                            <li wire:key="resource-{{ $item->uuid }}">
                                <button type="button" wire:click="open('{{ $item->uuid }}')" @class([
                                    'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition',
                                    'bg-zinc-100 font-medium dark:bg-zinc-800' => $current?->uuid === $item->uuid,
                                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' => $current?->uuid !== $item->uuid,
                                ])>
                                    <span class="min-w-0 flex-1 truncate">{{ $item->label }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ $item->definition()?->label }}</span>
                                </button>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            @endif
        </aside>

        <div class="min-w-0 flex-1">
            @if ($overview)
                @include('livewire.projects.partials.infrastructure-overview')
            @elseif ($current)
                @can('manage', $project)
                    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800/60">
                        <span class="text-zinc-500">{{ __('Sees it') }}:</span>
                        @forelse ($current->view_roles as $role)<flux:badge size="sm">{{ \App\Enums\UserRole::from($role)->label() }}</flux:badge>@empty<span>—</span>@endforelse
                        <span class="ms-2 text-zinc-500">{{ __('Acts on it') }}:</span>
                        @forelse ($current->act_roles as $role)<flux:badge size="sm" color="amber">{{ \App\Enums\UserRole::from($role)->label() }}</flux:badge>@empty<span>—</span>@endforelse
                        <span class="text-zinc-500">· {{ trans_choice(':count action|:count actions', count($current->actions)) }}</span>
                        <flux:spacer />
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit('{{ $current->uuid }}')">{{ __('Who sees and does what') }}</flux:button>
                    </div>
                @endcan
                @if ($current->isCipiApp())
                    <livewire:projects.cipi-app-hub :resource="$current->uuid" :key="'cipi-'.$current->uuid" />
                @else
                    <livewire:projects.resource-console :resource="$current->uuid" :key="'resource-'.$current->uuid" />
                @endif
            @endif
        </div>
    </div>

    @can('manage', $project)
        <flux:modal name="resource-roles" class="w-full md:w-[40rem]">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Who sees and does what') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('The roles of :project. The account and its credentials stay the administrators\'.', ['project' => $project->name]) }}</flux:text>
                </div>
                <flux:input wire:model="label" :label="__('Name in the project')" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:checkbox.group wire:model="view_roles" :label="__('Who sees it')">
                        @foreach (\App\Enums\UserRole::projectRoles() as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:checkbox.group wire:model="act_roles" :label="__('Who acts on it')">
                        @foreach (\App\Enums\UserRole::projectRoles() as $role)
                            <flux:checkbox :value="$role->value" :label="$role->label()" />
                        @endforeach
                    </flux:checkbox.group>
                </div>
                @if ($editingActions !== [])
                    <flux:checkbox.group wire:model="actions" :label="__('What they may do')">
                        <div class="grid max-h-64 gap-x-4 gap-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 sm:grid-cols-2 dark:border-zinc-700">
                            @foreach ($editingActions as $key => $item)
                                <flux:checkbox :value="$key" :label="$item['label']" :description="collect([str_contains($key, '.') ? $item['list'] : null, $item['danger'] ? __('destructive') : null])->filter()->implode(' · ') ?: null" wire:key="edit-action-{{ md5($key) }}" />
                            @endforeach
                        </div>
                    </flux:checkbox.group>
                @endif
                <div class="flex flex-wrap justify-between gap-2">
                    @if ($editing)
                        <flux:button variant="ghost" icon="link-slash" wire:click="detach('{{ $editing }}')" wire:confirm="{{ __('Take it off the project? Nothing changes at the provider.') }}">{{ __('Detach') }}</flux:button>
                    @endif
                    <div class="flex gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('manage', $project)
        <flux:modal name="resource-attach" class="w-full md:w-[44rem]">
            <form wire:submit="saveAttach" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Attach to :project', ['project' => $project->name]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('A server, a zone, a Cipi app, a bucket, a Boogle or Hackly project, from the team\'s accounts of Administration → Connectors. Members see it with their role; the account stays the administrators\'.') }}</flux:text>
                </div>
                @if ($this->attachAccounts->isEmpty())
                    <flux:callout icon="information-circle"><flux:callout.text>{{ __('No account yet: add one in Administration → Connectors.') }}</flux:callout.text></flux:callout>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="attachAccount" :label="__('Account')">
                            <flux:select.option value="">{{ __('Pick…') }}</flux:select.option>
                            @foreach ($this->attachAccounts->groupBy('connector') as $key => $accounts)
                                <optgroup label="{{ \App\Infrastructure\Connectors::find($key)?->label() }}">
                                    @foreach ($accounts as $account)
                                        <flux:select.option value="{{ $account->uuid }}">{{ $account->name }}</flux:select.option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="attachSection" :label="__('Kind')" :disabled="$this->attachSections === []">
                            @foreach ($this->attachSections as $section)
                                <flux:select.option value="{{ $section->key }}">{{ $section->group ? $section->group.' · ' : '' }}{{ $section->label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    @if ($attachSection !== '')
                        @php($rows = $this->attachRows)
                        <div>
                            <flux:input wire:model.live.debounce.300ms="attachSearch" icon="magnifying-glass" size="sm" :placeholder="__('Search')" />
                            <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-60" wire:target="attachAccount,attachSection,attachSearch">
                                @if ($rows['error'])
                                    <div class="p-3 text-sm text-red-600">{{ $rows['error'] }}</div>
                                @else
                                    @forelse ($rows['rows'] as $id => [$rowLabel, $detail])
                                        <button type="button" wire:click="pickAttachRow(@js((string) $id))" wire:key="attach-row-{{ md5((string) $id) }}" @class([
                                            'flex w-full items-center gap-3 border-b border-zinc-100 px-3 py-2 text-start text-sm last:border-0 dark:border-zinc-800',
                                            'bg-zinc-100 dark:bg-zinc-800' => $attachRow === (string) $id,
                                            'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' => $attachRow !== (string) $id,
                                        ])>
                                            <flux:icon :name="$attachRow === (string) $id ? 'check-circle' : 'stop'" variant="micro" @class(['size-4 shrink-0', 'text-emerald-600' => $attachRow === (string) $id, 'text-zinc-300' => $attachRow !== (string) $id]) />
                                            <span class="min-w-0 flex-1 truncate font-medium">{{ $rowLabel }}</span>
                                            <span class="truncate text-xs text-zinc-500">{{ $detail }}</span>
                                        </button>
                                    @empty
                                        <div class="p-3 text-sm text-zinc-500">{{ __('Nothing here that is not attached already.') }}</div>
                                    @endforelse
                                @endif
                            </div>
                            @error('attachRow')<flux:text class="mt-1 text-xs text-red-600">{{ $message }}</flux:text>@enderror
                        </div>
                    @endif

                    @if ($attachRow)
                        <flux:input wire:model="attach_label" :label="__('Name in the project')" />
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
                        @if ($this->attachActionChoices !== [])
                            <div>
                                <div class="flex items-center justify-between">
                                    <flux:label>{{ __('What they may do') }}</flux:label>
                                    <flux:button size="xs" variant="ghost" wire:click="safeActions">{{ __('Everything but destructive') }}</flux:button>
                                </div>
                                <flux:checkbox.group wire:model="attach_actions" class="mt-2">
                                    <div class="grid max-h-56 gap-x-4 gap-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 sm:grid-cols-2 dark:border-zinc-700">
                                        @foreach ($this->attachActionChoices as $key => $choice)
                                            <flux:checkbox :value="$key" :label="$choice['label']" :description="collect([str_contains($key, '.') ? $choice['list'] : null, $choice['danger'] ? __('destructive') : null])->filter()->implode(' · ') ?: null" wire:key="attach-action-{{ md5($key) }}" />
                                        @endforeach
                                    </div>
                                </flux:checkbox.group>
                            </div>
                        @endif
                    @endif
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="link" :disabled="! $attachRow">{{ __('Attach') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
