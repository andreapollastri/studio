<div class="flex h-full w-full flex-1 flex-col gap-6" @if ($projects->contains(fn ($p) => $p->site_status->isTransitional())) wire:poll.5s @endif>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('A project is a :provider repository with Larapilot. Its deploy branch runs at :host; every member gets a workspace and a preview of their own.', ['provider' => $git->label(), 'host' => '<project>.'.$domain]) }}</flux:text>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="create">{{ __('New project') }}</flux:button>
    </div>

    @unless ($deployerHasGit)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Connect your :provider account first', ['provider' => $git->label()]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Project sites are cloned and deployed with the token of the administrator who creates them.') }} <flux:link :href="route('connections.edit')" wire:navigate>{{ __('Settings → Connections') }}</flux:link></flux:callout.text>
        </flux:callout>
    @endunless

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Project') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Repository') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Stack') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Site') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Members') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($projects as $project)
                    <tr wire:key="project-{{ $project->id }}">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $project->name }}</div>
                            <div class="font-mono text-xs text-zinc-500">{{ $project->slug }}</div>
                        </td>
                        <td class="max-w-xs truncate px-4 py-2 font-mono text-xs">{{ $project->repositoryPath() ?? $project->repo_url }} <span class="text-zinc-500">@ {{ $project->default_branch }}</span></td>
                        <td class="px-4 py-2 text-xs">PHP {{ $project->php_version }} · {{ $project->db_engine === 'pgsql' ? 'PostgreSQL' : 'MariaDB' }}</td>
                        <td class="px-4 py-2 text-xs">
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
                        <td class="px-4 py-2 text-end tabular-nums">{{ $project->members_count }}</td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <flux:button size="xs" variant="ghost" icon="rocket-launch" wire:click="deploy({{ $project->id }})" :disabled="$project->site_status->isTransitional()">{{ $project->site_status === \App\Enums\SiteStatus::New ? __('Create site') : __('Deploy') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $project->id }})" :disabled="$project->site_status === \App\Enums\SiteStatus::Deleting">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="cog-6-tooth" wire:click="settings({{ $project->id }})" :disabled="$project->site_status === \App\Enums\SiteStatus::Deleting">{{ __('Settings') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $project->id }})" :disabled="$project->site_status === \App\Enums\SiteStatus::Deleting" wire:confirm="{{ __('Delete the project, its site and every workspace on this server? The :provider repository is not touched.', ['provider' => $git->label()]) }}" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">{{ __('No projects. Create one.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="project-form" class="md:w-[40rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit project') : __('New project') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The deploy branch runs at :host; each member gets :preview.', ['host' => ($slug ?: '<slug>').'.'.$domain, 'preview' => '<user>-'.($slug ?: '<slug>').'.'.$domain]) }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required />
            {{-- full width: its description would push it out of line with a field beside it --}}
            <flux:input wire:model.blur="slug" :label="__('Slug')" :description="__('Hostname label; empty = from the name')" />
            @unless ($editingId)
                <flux:radio.group wire:model.live="source" :label="__('Repository')" variant="segmented">
                    <flux:radio value="existing" :label="__('Existing on :provider', ['provider' => $git->label()])" />
                    <flux:radio value="new" :label="__('Create it now')" />
                </flux:radio.group>
            @endunless

            @if ($editingId || $source === 'existing')
                <flux:input wire:model="repo_url" :label="__(':provider repository (HTTPS)', ['provider' => $git->label()])" :placeholder="$git->repositoryExample()" :required="$source === 'existing'" />
            @else
                <div class="grid gap-4 sm:grid-cols-[14rem_1fr_auto]">
                    <flux:select wire:model="new_owner" :label="$git->ownerLabel()">
                        @if ($git->hasPersonalNamespace())
                            <flux:select.option value="">{{ auth()->user()->git_login ?: __('your account') }}</flux:select.option>
                        @else
                            <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @endif
                        @foreach ($owners as $owner)
                            <flux:select.option value="{{ $owner['value'] }}">{{ $owner['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="new_name" :label="__('Repository name')" :placeholder="$slug ?: 'my-app'" />
                    <flux:field class="self-end pb-2">
                        <flux:checkbox wire:model="new_private" :label="__('Private')" />
                    </flux:field>
                </div>
                <flux:text class="text-xs">{{ __('The repository is created empty on :provider, then the server installs the latest Laravel, Laravel Boost and Larapilot, turns on Larapilot\'s :provider integration, commits and pushes the deploy branch. Your token needs the right to create repositories there: see Settings → Connections.', ['provider' => $git->label()]) }}</flux:text>
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

            <flux:checkbox.group wire:model="members" :label="__('Members')">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($users as $user)
                        <flux:checkbox :value="$user->id" :label="$user->name" :description="$user->email.' · '.$user->role->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            @unless ($editingId)
                <flux:text class="text-xs">{{ __('On save the server clones the repository, creates the database, serves the site and registers a :provider push webhook on the deploy branch.', ['provider' => $git->label()]) }}</flux:text>
            @endunless

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal name="project-settings" class="md:w-[46rem]">
        <form wire:submit="saveSettings" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Project settings') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Applied on the server to the project site and to every workspace preview of the project.') }}</flux:text>
            </div>

            <flux:radio.group wire:model.live="settingsTab" variant="segmented">
                <flux:radio value="server" :label="__('Server')" />
                <flux:radio value="access" :label="__('Access')" />
                <flux:radio value="mcp" :label="__('MCP')" />
            </flux:radio.group>

            @if ($settingsTab === 'server')
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Server features') }}</flux:heading>
                <flux:checkbox wire:model="scheduler" :label="__('Scheduler')" :description="__('php artisan schedule:run every minute, from cron. On by default.')" />
                <flux:input wire:model="queues" :label="__('Queues')" :description="__('One queue:work worker per name, comma separated. The default queue is always on.')" placeholder="default, mail, exports" :disabled="$horizon" />
                <flux:checkbox wire:model.live="horizon" :label="__('Horizon')" :description="__('php artisan horizon in place of the queue workers; the queues come from config/horizon.php. Needs laravel/horizon.')" />
                <flux:checkbox wire:model="reverb" :label="__('Reverb websockets')" :description="__('php artisan reverb:start on a loopback port, proxied under /app of the host. Needs laravel/reverb.')" />
                <flux:checkbox wire:model="pulse" :label="__('Pulse server metrics')" :description="__('php artisan pulse:check, so the Pulse dashboard shows CPU, memory and disk. Needs laravel/pulse.')" />
            </div>
            @endif

            @if ($settingsTab === 'access')
            <div class="space-y-4">
                <div>
                    <flux:heading size="sm">{{ __('Who can open the hosts') }}</flux:heading>
                    <flux:text class="mt-1 text-xs">{{ __('Every address of the project (the site, its /larapilot dashboard, every workspace preview) asks for a person logged in to Studio who is assigned to the project, in any role, or an administrator. An address list narrows it further: one IP or CIDR per line, and from anywhere else nobody gets in. HTTPS is automatic on every host, previews included.') }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:textarea wire:model="site_ips" :label="__('Site (deploy branch): only from')" :description="__('Empty: from anywhere, after the login.')" rows="3" placeholder="203.0.113.0/24" class="font-mono text-xs" />
                    <flux:textarea wire:model="previews_ips" :label="__('Workspace previews: only from')" :description="__('Empty: from anywhere, after the login.')" rows="3" placeholder="203.0.113.9" class="font-mono text-xs" />
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Branch overrides') }}</flux:heading>
                        <flux:button size="xs" variant="ghost" icon="plus" wire:click="addBranchRule">{{ __('Add branch') }}</flux:button>
                    </div>
                    @foreach ($branch_rules as $i => $rule)
                        <div class="mb-2 grid gap-2 rounded-lg border border-zinc-200 p-2 sm:grid-cols-[1fr_1fr_auto] dark:border-zinc-700" wire:key="branch-rule-{{ $i }}">
                            <flux:input wire:model="branch_rules.{{ $i }}.branch" placeholder="feature/secret" class="font-mono text-xs" :aria-label="__('Branch')" />
                            <flux:input wire:model="branch_rules.{{ $i }}.ips" placeholder="203.0.113.9, 10.0.0.0/8" class="font-mono text-xs" :aria-label="__('Only from')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeBranchRule({{ $i }})">{{ __('Remove') }}</flux:button>
                        </div>
                    @endforeach
                    <flux:text class="text-xs">{{ __('A branch rule replaces the address list of the site when that is the deploy branch, and of every workspace that is on that branch. The login is always asked.') }}</flux:text>
                </div>
            </div>
            @endif

            @if ($settingsTab === 'mcp')
                <div class="space-y-4">
                    <div>
                        <flux:heading size="sm">{{ __('MCP servers in the chat') }}</flux:heading>
                        <flux:text class="mt-1 text-xs">{{ __('Remote MCP servers (HTTP) that every chat of this project gets, for the roles you tick: documentation, error tracking, a CRM, your own tools. Their tools appear as mcp__name__tool and ask for permission like any other tool. Servers that run as a command belong in the repository\'s .mcp.json, which Claude Code loads in every workspace.') }}</flux:text>
                    </div>

                    @if ($mcpServers->isNotEmpty())
                        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($mcpServers as $server)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-xs" wire:key="mcp-{{ $server->id }}">
                                    <span @class(['font-mono font-semibold', 'text-zinc-400 line-through' => ! $server->enabled])>{{ $server->name }}</span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-zinc-500" title="{{ $server->url }}">{{ $server->url }}</span>
                                    <span class="flex gap-1">
                                        @foreach ($server->roles as $role)
                                            <flux:badge size="sm">{{ \App\Enums\UserRole::from($role)->label() }}</flux:badge>
                                        @endforeach
                                    </span>
                                    <span class="w-full text-zinc-500">
                                        @if ($server->headerNames())<span>{{ __('header :names', ['names' => $server->headerNames()]) }} · </span>@endif
                                        @if ($server->status)
                                            <span @class(['text-green-700 dark:text-green-400' => $server->isHealthy(), 'text-amber-700 dark:text-amber-400' => ! $server->isHealthy()])>{{ $server->status }}</span>
                                            <span>· {{ $server->checked_at?->diffForHumans() }}</span>
                                        @else
                                            <span>{{ __('not tested yet') }}</span>
                                        @endif
                                    </span>
                                    <span class="flex w-full gap-1">
                                        <flux:button size="xs" variant="ghost" icon="signal" wire:click="testMcp({{ $server->id }})">{{ __('Test') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" :icon="$server->enabled ? 'pause' : 'play'" wire:click="toggleMcp({{ $server->id }})">{{ $server->enabled ? __('Turn off') : __('Turn on') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editMcp({{ $server->id }})">{{ __('Edit') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteMcp({{ $server->id }})" wire:confirm="{{ __('Remove :name from the chats of this project?', ['name' => $server->name]) }}">{{ __('Remove') }}</flux:button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="space-y-3 rounded-lg border border-dashed border-zinc-300 p-3 dark:border-zinc-600">
                        <flux:heading size="sm">{{ $mcp_id ? __('Edit :name', ['name' => $mcp_name]) : __('Add a server') }}</flux:heading>
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <flux:input wire:model="mcp_name" :label="__('Name')" placeholder="docs" class="font-mono" />
                            <flux:input wire:model="mcp_url" :label="__('URL')" placeholder="https://mcp.example.com/mcp" class="font-mono" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <flux:input wire:model="mcp_header_name" :label="__('Header')" placeholder="Authorization" class="font-mono" />
                            <flux:input wire:model="mcp_header_value" type="password" :label="__('Value')" :placeholder="$mcp_id && $mcp_has_secret ? __('unchanged; type to replace') : 'Bearer …'" viewable />
                        </div>
                        <flux:checkbox.group wire:model="mcp_roles" :label="__('Who gets it in the chat')">
                            <div class="flex flex-wrap gap-4">
                                @foreach (\App\Enums\UserRole::cases() as $role)
                                    <flux:checkbox :value="$role->value" :label="$role->label()" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="filled" :icon="$mcp_id ? 'check' : 'plus'" wire:click="saveMcp">{{ $mcp_id ? __('Save server') : __('Add server') }}</flux:button>
                            @if ($mcp_id)
                                <flux:button size="sm" variant="ghost" wire:click="cancelMcp">{{ __('Cancel') }}</flux:button>
                            @endif
                        </div>
                        <flux:text class="text-xs">{{ __('Changes reach each chat with its next message. The value of the header is stored encrypted and shown only as its name.') }}</flux:text>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
