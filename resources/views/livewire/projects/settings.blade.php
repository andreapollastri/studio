<div class="h-full overflow-y-auto">
    <x-slot:header>
        <x-studio.project-title :project="$project" />
    </x-slot:header>

    <x-slot:subnav>
        <x-studio.project-tabs :project="$project" current="settings" />
    </x-slot:subnav>

    <div class="mx-auto flex max-w-6xl gap-8 p-6 max-md:flex-col max-md:gap-4 max-md:p-4">
        {{-- Sections --}}
        <aside class="w-48 shrink-0 max-md:w-full">
            <flux:heading size="lg">{{ __('Settings') }}</flux:heading>
            <flux:text class="mt-1 font-mono text-xs">{{ $project->slug }}</flux:text>
            <nav class="mt-4 flex flex-col gap-0.5 max-md:flex-row max-md:overflow-x-auto" aria-label="{{ __('Settings') }}">
                @foreach (['general' => ['cog-6-tooth', __('General')], 'members' => ['users', __('Members & roles')], 'server' => ['server', __('Server')], 'access' => ['lock-closed', __('Who opens the hosts')], 'agent' => ['sparkles', __('Agent')], 'mcp' => ['puzzle-piece', __('MCP servers')]] as $key => [$icon, $label])
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'flex items-center gap-2 rounded-md px-2.5 py-1.5 text-start text-sm whitespace-nowrap transition',
                        'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' => $tab === $key,
                        'text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-200' => $tab !== $key,
                    ])>
                        <flux:icon :name="$icon" variant="micro" class="size-4" />{{ $label }}
                    </button>
                @endforeach
            </nav>
            <div class="mt-6 space-y-1 text-xs text-zinc-500 max-md:hidden">
                <div>{{ __('Site') }}: <span class="font-mono">{{ $project->siteHost() }}</span></div>
                <div>{{ __('Previews') }}: <span class="font-mono">&lt;person&gt;-{{ $project->slug }}.{{ $project->previewDomainName() }}</span></div>
                <flux:link :href="route('admin.projects')" wire:navigate class="text-xs">{{ __('All projects') }}</flux:link>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            @if ($tab === 'general')
                <form wire:submit="saveGeneral" class="max-w-2xl space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('General') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('The key :key names the site, the workspaces, the databases and the folders on the server: it never changes. The name is only what people read, and two projects may share it.', ['key' => $project->slug]) }}</flux:text>
                    </div>
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:input wire:model.blur="repo_url" :label="__('Repository (HTTPS)')" required />
                    <flux:select wire:model="deploy_account" :label="__('Deployed with')" :description="__('The account that reads the repository, registers the push webhook and deploys the site. A service account keeps deploying when an administrator leaves; a personal one is the administrator\'s own token.')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($deployAccounts as $account)
                            <flux:select.option value="{{ $account->uuid }}">{{ $account->user_id === null ? __('Service') : ($account->user_id === auth()->id() ? __('Yours') : $account->user?->name) }} · {{ $account->name }} · {{ $account->host }}</flux:select.option>
                        @endforeach
                    </flux:select>
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
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            @endif

            @if ($tab === 'members')
                <form wire:submit="saveMembers" class="max-w-3xl space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Members & roles') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('The role is this project\'s: someone may be a developer here and the PM or a client elsewhere. A PM and a developer get a workspace and the chat; a client follows the site and what is shared with clients. Passwords, folders, SSH connections and infrastructure attached to the project open by these roles.') }}</flux:text>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <table class="table-cards w-full text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-start">{{ __('Person') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('Role on :project', ['project' => $project->name]) }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($people as $person)
                                    <tr wire:key="member-{{ $person->uuid }}">
                                        <td class="px-4 py-2" data-cell="main">
                                            <div class="font-medium">{{ $person->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $person->email }}</div>
                                        </td>
                                        <td class="px-4 py-2" data-cell="meta">
                                            <flux:select size="sm" wire:model="roles.{{ $person->uuid }}" :aria-label="__('Role')">
                                                <flux:select.option value="">{{ __('Not on the project') }}</flux:select.option>
                                                @foreach (\App\Enums\UserRole::projectRoles() as $role)
                                                    <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach ($admins as $admin)
                                    <tr wire:key="admin-{{ $admin->uuid }}">
                                        <td class="px-4 py-2" data-cell="main">
                                            <div class="font-medium">{{ $admin->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $admin->email }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-zinc-500" data-cell="meta">{{ __('Administrator: every project') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">{{ __('Save members') }}</flux:button>
                    </div>
                </form>
            @endif

            @if (in_array($tab, ['server', 'access', 'agent'], true))
                <form wire:submit="saveSettings" class="max-w-2xl space-y-6">
                    @if ($tab === 'server')
                        <div>
                            <flux:heading size="lg">{{ __('Server') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Applied on the server to the project site and to every workspace preview of the project.') }}</flux:text>
                        </div>
                        {{-- Where the site and the previews live --}}
                        @php($siteChoice = $domains->firstWhere('uuid', $site_domain))
                        @php($previewChoice = $domains->firstWhere('uuid', $preview_domain))
                        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div>
                                <flux:heading>{{ __('Addresses') }}</flux:heading>
                                <flux:text class="mt-1 text-sm">{{ __('Under Studio\'s domain by default. A domain of Administration → Domains gives the site and the previews the client\'s own address; the usual :host keeps working for the team.', ['host' => $project->defaultSiteHost()]) }}</flux:text>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-[1fr_1fr]">
                                <flux:select wire:model.live="site_domain" :label="__('Site')">
                                    <flux:select.option value="">{{ $project->defaultSiteHost() }}</flux:select.option>
                                    @foreach ($domains as $domain)
                                        @if ($domain->namePointsHere() || $domain->wildcardPointsHere() || $domain->id === $project->site_domain_id)
                                            <flux:select.option value="{{ $domain->uuid }}">{{ $domain->name }}{{ $domain->isVerified() ? '' : ' · '.$domain->status->label() }}</flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                @if ($siteChoice && $siteChoice->wildcard)
                                    <flux:input wire:model.live.debounce.400ms="site_label" :label="__('Subdomain')" :placeholder="$siteChoice->namePointsHere() ? __('empty: :name itself', ['name' => $siteChoice->name]) : $project->slug" class="font-mono" />
                                @endif
                            </div>
                            @error('site_domain')<flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                            @error('site_label')<flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                            @if ($siteChoice)
                                <flux:text class="text-xs">{{ __('The site at :host, and at :default for the team.', ['host' => (trim($site_label) !== '' ? strtolower(trim($site_label)).'.' : '').$siteChoice->name, 'default' => $project->defaultSiteHost()]) }}</flux:text>
                                @include('livewire.projects.partials.domain-online', ['choice' => $siteChoice])
                            @endif

                            <flux:select wire:model.live="preview_domain" :label="__('Previews and sandboxes')">
                                <flux:select.option value="">&lt;person&gt;-{{ $project->slug }}.{{ config('studio.domain') }}</flux:select.option>
                                @foreach ($domains as $domain)
                                    @if ($domain->wildcardPointsHere() || $domain->id === $project->preview_domain_id)
                                        <flux:select.option value="{{ $domain->uuid }}">&lt;person&gt;-{{ $project->slug }}.{{ $domain->name }}{{ $domain->isVerified() ? '' : ' · '.$domain->status->label() }}</flux:select.option>
                                    @endif
                                @endforeach
                            </flux:select>
                            @error('preview_domain')<flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>@enderror
                            @if ($previewChoice)
                                @include('livewire.projects.partials.domain-online', ['choice' => $previewChoice])
                            @endif
                            @if (($previewChoice?->id) !== $project->preview_domain_id)
                                <flux:text class="text-xs">{{ __('Every workspace moves there when you save; sandboxes already open keep their address until they are removed.') }}</flux:text>
                            @endif
                            @if ($domains->isEmpty())
                                <flux:text class="text-xs">{{ __('No domain points here yet:') }} <flux:link :href="route('admin.domains')" wire:navigate>{{ __('add one in Administration → Domains') }}</flux:link>.</flux:text>
                            @endif
                        </div>
                        <div class="space-y-3">
                            <flux:checkbox wire:model="scheduler" :label="__('Scheduler')" :description="__('php artisan schedule:run every minute, from cron. On by default.')" />
                            <flux:input wire:model="queues" :label="__('Queues')" :description="__('One queue:work worker per name, comma separated. The default queue is always on.')" placeholder="default, mail, exports" :disabled="$horizon" />
                            <flux:checkbox wire:model.live="horizon" :label="__('Horizon')" :description="__('php artisan horizon in place of the queue workers; the queues come from config/horizon.php. Needs laravel/horizon.')" />
                            <flux:checkbox wire:model="reverb" :label="__('Reverb websockets')" :description="__('php artisan reverb:start on a loopback port, proxied under /app of the host. Needs laravel/reverb.')" />
                            <flux:checkbox wire:model="pulse" :label="__('Pulse server metrics')" :description="__('php artisan pulse:check, so the Pulse dashboard shows CPU, memory and disk. Needs laravel/pulse.')" />
                        </div>
                        <flux:select wire:model="timezone" :label="__('Project timezone')" :description="__('APP_TIMEZONE in the .env of the site and of every preview, TZ in the workspaces where Claude and the terminal run. Laravel reads it when config/app.php has env(\'APP_TIMEZONE\'), as projects created by Studio do.')">
                            @foreach ($timezones as $zone)
                                <flux:select.option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    @if ($tab === 'access')
                        <div>
                            <flux:heading size="lg">{{ __('Who opens the hosts') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Every address of the project (the site, its /larapilot dashboard, every workspace preview) asks for a person logged in to Studio who is on the project, in any role, or an administrator, unless you make it public below. An address list narrows it further: one IP or CIDR per line, and from anywhere else nobody gets in. HTTPS is automatic on every host, previews included.') }}</flux:text>
                        </div>
                        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div>
                                <flux:heading>{{ __('Public access') }}</flux:heading>
                                <flux:text class="mt-1 text-sm">{{ __('Off by default. A public host skips the Studio login: anyone with the address opens it, as a real public website. The address lists still apply, and /larapilot always asks for the login.') }}</flux:text>
                            </div>
                            <flux:switch wire:model.live="site_public" :label="__('Public site')" :description="__('The site of the deploy branch, at :host and at every address of it. It runs with APP_ENV=staging and APP_DEBUG=false while public.', ['host' => $project->site_host ?: $project->defaultSiteHost()])" />
                            <flux:switch wire:model.live="previews_public" :disabled="! $site_public" :label="__('Public workspace previews too')" :description="__('Every developer\'s preview and every sandbox of the project. While public they switch to APP_ENV=staging and APP_DEBUG=false, so no debug page or local-only tool reaches the open internet; behind the login again, they go back to APP_ENV=local and APP_DEBUG=true.')" />
                            @if ($site_public)
                                <flux:callout variant="warning" icon="globe-alt" :heading="$previews_public ? __('The site and every preview open to anyone, without the login.') : __('The site opens to anyone, without the login.')" :text="__('Saving applies it to the server: the hosts that change get their new APP_ENV and APP_DEBUG.')" />
                            @endif
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
                            <flux:text class="text-xs">{{ __('A branch rule replaces the address list of the site when that is the deploy branch, and of every workspace that is on that branch. The login is asked unless the host is public.') }}</flux:text>
                        </div>
                    @endif

                    @if ($tab === 'agent')
                        <div>
                            <flux:heading size="lg">{{ __('What the agent gets in this project') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Every chat is Claude Code with its subagents; Studio adds its own code-reviewer and, with the browser, browser-qa.') }}</flux:text>
                        </div>
                        <flux:checkbox wire:model="agent_browser" :label="__('Browser for the agent')" :description="__('A headless Chromium (Playwright MCP) signed in to the preview as the person: Claude opens pages, takes screenshots, reads the console and failed requests. The screenshots show in the chat. It sees what the person may see, through the same access rules.')" />
                        <flux:checkbox wire:model.live="agent_sandboxes" :label="__('Conversations in a sandbox')" :description="__('A git worktree of the person\'s workspace on its own branch, with a copy of its database and a preview of its own (<person>-<project>--c<id>). Several work at once without touching each other; the person merges or pushes the one they keep. Queues run inline there, and the scheduler and Reverb are off.')" />
                        <flux:input type="number" min="1" max="10" wire:model="agent_sandbox_limit" :label="__('Sandboxes per person')" :description="__('Each one is a database, a build and a few hundred MB on disk.')" :disabled="! $agent_sandboxes" class="max-w-40" />
                    @endif

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            @endif

            @if ($tab === 'mcp')
                <div class="max-w-3xl space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('MCP servers in the chat') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Remote MCP servers (HTTP) that every chat of this project gets, for the roles you tick: documentation, error tracking, a CRM, your own tools. Their tools appear as mcp__name__tool and ask for permission like any other tool. Servers that run as a command belong in the repository\'s .mcp.json, which Claude Code loads in every workspace.') }}</flux:text>
                    </div>

                    @if ($nativeMcp !== [])
                        <div>
                            <flux:heading size="sm">{{ __('From the infrastructure, automatic') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('A Cipi app attached to the project brings the Cipi agent\'s MCP server, and the server\'s own when turned on: those who act on the app get them. Change them in Infrastructure → the app → Agent & access. A server below with the same name wins.') }}</flux:text>
                            <ul class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                @foreach ($nativeMcp as $row)
                                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-xs" wire:key="native-{{ $row['name'] }}">
                                        <span @class(['font-mono font-semibold', 'text-zinc-400 line-through' => ! $row['on']])>{{ $row['name'] }}</span>
                                        <span class="min-w-0 flex-1 truncate font-mono text-zinc-500" title="{{ $row['url'] }}">{{ $row['url'] }}</span>
                                        <flux:badge size="sm" :color="! $row['on'] ? 'zinc' : ($row['ready'] ? 'green' : 'amber')">{{ ! $row['on'] ? __('off') : ($row['ready'] ? __('on') : __('not ready')) }}</flux:badge>
                                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('projects.infrastructure', ['project' => $project, 'resource' => $row['resource']->uuid, 'view' => 'agent'])" wire:navigate>{{ $row['resource']->label }}</flux:button>
                                        @if ($row['on'] && $row['reason'])<span class="w-full text-amber-700 dark:text-amber-400">{{ $row['reason'] }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($mcpServers->isNotEmpty())
                        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($mcpServers as $server)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-xs" wire:key="mcp-{{ $server->uuid }}">
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
                                        <flux:button size="xs" variant="ghost" icon="signal" wire:click="testMcp('{{ $server->uuid }}')">{{ __('Test') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" :icon="$server->enabled ? 'pause' : 'play'" wire:click="toggleMcp('{{ $server->uuid }}')">{{ $server->enabled ? __('Turn off') : __('Turn on') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editMcp('{{ $server->uuid }}')">{{ __('Edit') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteMcp('{{ $server->uuid }}')" wire:confirm="{{ __('Remove :name from the chats of this project?', ['name' => $server->name]) }}">{{ __('Remove') }}</flux:button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="space-y-3 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
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
        </div>
    </div>
</div>
