<div class="flex h-full w-full flex-1 flex-col gap-6 max-sm:gap-4" @if ($projects->contains(fn ($p) => $p->site_status->isTransitional())) wire:poll.3s @endif>
    <div class="flex items-start justify-between gap-4 max-sm:flex-col max-sm:gap-3">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('A project is a repository with Larapilot, on any git host. Its deploy branch runs at :host; every member gets a workspace and a preview of their own, and works with their own accounts. Who is on it, with which role, and what they see of the infrastructure is set in its Settings.', ['host' => '<key>.'.$domain]) }}</flux:text>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="create">{{ __('New project') }}</flux:button>
    </div>

    @if ($gitAccounts->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Add a git account first') }}</flux:callout.heading>
            <flux:callout.text>{{ __('A project site is cloned and deployed with a git account on the host of its repository: a service account of the team (Administration → Connectors), or one of yours.') }} <flux:link :href="route('connections.edit', ['tab' => 'git'])" wire:navigate>{{ __('Settings → Accounts') }}</flux:link></flux:callout.text>
        </flux:callout>
    @endif

    @if ($projects->count() > 8 || $search !== '')
        <flux:input wire:model.live.debounce.250ms="search" icon="magnifying-glass" :placeholder="__('Search by name, key or repository')" clearable class="max-w-sm" />
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="table-cards w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Project') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Repository') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Site') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Team') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($projects as $project)
                    @php($deployer = $project->repositoryAccount)
                    <tr wire:key="project-{{ $project->uuid }}">
                        <td class="px-4 py-2" data-cell="main">
                            <a href="{{ route('studio.show', $project) }}" wire:navigate class="font-medium hover:underline">{{ $project->name }}</a>
                            <div class="font-mono text-xs text-zinc-500">{{ $project->slug }}</div>
                        </td>
                        <td class="max-w-xs px-4 py-2 text-xs" data-cell="wide">
                            <div class="truncate font-mono">{{ $project->repositoryPath() ?? $project->repo_url }} <span class="text-zinc-500">@ {{ $project->default_branch }}</span></div>
                            <div class="mt-1 flex flex-wrap items-center gap-1 text-zinc-500">
                                <span>PHP {{ $project->php_version }} · {{ $project->db_engine === 'pgsql' ? 'PostgreSQL' : 'MariaDB' }}</span>
                                @if ($deployer)
                                    <flux:badge size="sm" :color="$deployer->user_id === null ? 'green' : 'amber'" :title="__('Deployed with this account')">{{ $deployer->user_id === null ? __('service · :name', ['name' => $deployer->name]) : __(':person\'s token', ['person' => $deployer->user?->name ?? '?']) }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ __('no deploy account') }}</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs" data-cell="wide">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :color="match ($project->site_status) { \App\Enums\SiteStatus::Ready => 'green', \App\Enums\SiteStatus::Failed => 'red', \App\Enums\SiteStatus::New => 'zinc', default => 'yellow' }">{{ $project->site_status->label() }}</flux:badge>
                                @if ($project->isOnline())
                                    <flux:link :href="$project->siteUrl()" target="_blank" class="text-xs">{{ $project->siteHost() }}</flux:link>
                                @else
                                    <span class="font-mono text-zinc-500">{{ $project->siteHost() }}</span>
                                @endif
                            </div>
                            @if ($project->deployed_sha)
                                <div class="mt-1 text-zinc-500">{{ Str::limit($project->deployed_sha, 7, '') }} · {{ $project->deployed_at?->diffForHumans() }}@if ($project->webhook_id) · {{ __('webhook on') }}@endif</div>
                            @endif
                            @if ($project->last_error)
                                <div class="mt-1 max-w-md whitespace-pre-wrap break-words text-red-600">{{ $project->last_error }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-end text-xs whitespace-nowrap text-zinc-500" data-cell="meta" data-label="{{ __('Team') }}">
                            {{ trans_choice(':count member|:count members', $project->members_count) }}
                            @if ($project->resources_count)<div>{{ trans_choice(':count resource|:count resources', $project->resources_count) }}</div>@endif
                        </td>
                        <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions-row">
                            <flux:button size="xs" variant="ghost" icon="rocket-launch" wire:click="deploy('{{ $project->uuid }}')" :disabled="$project->site_status->isTransitional()">{{ $project->site_status === \App\Enums\SiteStatus::New ? __('Create site') : __('Deploy') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="cog-6-tooth" :href="route('projects.settings', $project)" wire:navigate :disabled="$project->site_status === \App\Enums\SiteStatus::Deleting">{{ __('Settings') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete('{{ $project->uuid }}')" :disabled="$project->site_status === \App\Enums\SiteStatus::Deleting" wire:confirm="{{ __('Delete :name (:key), its site and every workspace on this server? The repository is not touched.', ['name' => $project->name, 'key' => $project->slug]) }}" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">{{ $search !== '' ? __('No project matches.') : __('No projects. Create one.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="project-form" class="md:w-[44rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New project') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Studio gives it a key of its own, from the name and six random characters (shop-k2mx7p): the site runs at <key>.:domain and each member gets <person>-<key>.:domain. Two projects may share a name.', ['domain' => $domain]) }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:select wire:model.live="git_account" :label="__('Git account')" :description="__('It creates or reads the repository, registers the push webhook and deploys the site. A service account keeps deploying when an administrator leaves.')">
                @if ($gitAccounts->isEmpty())
                    <flux:select.option value="">{{ __('No git account yet') }}</flux:select.option>
                @endif
                @foreach ($gitAccounts as $account)
                    <flux:select.option value="{{ $account->uuid }}">{{ $account->user_id === null ? __('Service') : __('Yours') }} · {{ $account->name }} · {{ $account->host }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:radio.group wire:model.live="source" :label="__('Repository')" variant="segmented">
                <flux:radio value="existing" :label="__('Existing on :provider', ['provider' => $providerLabel])" />
                <flux:radio value="new" :label="__('Create it now')" />
            </flux:radio.group>

            @if ($source === 'existing')
                <flux:input wire:model.blur="repo_url" :label="__(':provider repository (HTTPS)', ['provider' => $providerLabel])" :placeholder="$git?->repositoryExample()" required />
            @else
                <div class="grid gap-4 sm:grid-cols-[14rem_1fr_auto]">
                    <flux:select wire:model="new_owner" :label="$git?->ownerLabel() ?? __('Owner')">
                        @if ($git?->hasPersonalNamespace())
                            <flux:select.option value="">{{ $gitAccounts->firstWhere('uuid', $git_account)?->login ?: __('the account itself') }}</flux:select.option>
                        @else
                            <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @endif
                        @foreach ($owners as $owner)
                            <flux:select.option value="{{ $owner['value'] }}">{{ $owner['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="new_name" :label="__('Repository name')" :placeholder="Str::slug($name) ?: 'my-app'" />
                    <flux:field class="self-end pb-2">
                        <flux:checkbox wire:model="new_private" :label="__('Private')" />
                    </flux:field>
                </div>
                <flux:text class="text-xs">{{ __('The repository is created empty on :provider, then the server installs the latest Laravel, Laravel Boost and Larapilot, turns on Larapilot\'s :provider integration, commits and pushes the deploy branch. The token of the account chosen needs the right to create repositories there.', ['provider' => $providerLabel]) }}</flux:text>
            @endif
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="default_branch" :label="__('Deploy branch')" />
                <flux:select wire:model="php_version" :label="__('PHP')">
                    @foreach ($phpVersions as $v)<flux:select.option value="{{ $v }}">{{ $v }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="db_engine" :label="__('Database')">
                    <flux:select.option value="mariadb">MariaDB</flux:select.option>
                    <flux:select.option value="pgsql">PostgreSQL</flux:select.option>
                </flux:select>
            </div>

            <flux:separator />

            <div>
                <flux:heading size="sm">{{ __('Members & roles') }}</flux:heading>
                <flux:text class="mt-1 text-xs">{{ __('The role is this project\'s. Administrators are on every project. You can change all of it later in the project\'s Settings.') }}</flux:text>
                @if ($people->isEmpty())
                    <flux:text class="mt-2 text-xs">{{ __('Nobody else yet: invite people in Administration → Users.') }}</flux:text>
                @else
                    <div class="mt-3 max-h-64 divide-y divide-zinc-100 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($people as $person)
                            <div class="flex items-center gap-3 px-3 py-1.5" wire:key="new-member-{{ $person->uuid }}">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm">{{ $person->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $person->email }}</div>
                                </div>
                                <div class="w-44">
                                    <flux:select size="sm" wire:model="roles.{{ $person->uuid }}" :aria-label="__('Role')">
                                        <flux:select.option value="">{{ __('Not on the project') }}</flux:select.option>
                                        @foreach (\App\Enums\UserRole::projectRoles() as $role)
                                            <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:text class="text-xs">{{ __('On save the server clones the repository, creates the database, serves the site and registers a push webhook on the deploy branch.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create the project') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
