<div
    class="flex h-full w-full flex-1 flex-col gap-6"
    {{-- A site being set up or a workspace on its way: keep the badges current. --}}
    @if ($projects->contains(fn ($p) => $p->site_status->isTransitional()) || $workspaces->contains(fn ($w) => $w->status->isTransitional())) wire:poll.4s @else wire:poll.30s.visible @endif
>
    <div class="flex items-start justify-between gap-4 max-sm:flex-col max-sm:gap-3">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every project opens your workspace: a chat with Claude, the preview of your branch, the backlog; its repository, its infrastructure and its access in the tabs next to it. Each one runs on the Claude account and the git identity shown on its card, with the role you have there.') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="folder-plus" wire:click="newGroup">{{ __('New group') }}</flux:button>
            @if (auth()->user()->isAdmin())
                <flux:button :href="route('admin.projects')" icon="plus" variant="primary" wire:navigate>{{ __('Manage projects') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($projects->count() > 6 || $search !== '')
        <flux:input wire:model.live.debounce.250ms="search" icon="magnifying-glass" :placeholder="__('Search projects')" clearable class="max-w-sm" />
    @endif

    @if ($projects->isEmpty() && $search === '')
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No projects yet') }}</flux:heading>
            <flux:text class="mt-2">
                @if (auth()->user()->isAdmin())
                    {{ __('Create the first project from Administration and add the members.') }}
                @else
                    {{ __('Ask an administrator to add you to a project.') }}
                @endif
            </flux:text>
        </div>
    @else
        {{-- The person's groups in their order, then the projects in none --}}
        @foreach ([...$groups->all(), null] as $group)
            @php($list = $byGroup->get($group?->id ?? 0, collect()))
            @continue($group === null && $list->isEmpty() && $groups->isNotEmpty())
            @continue($group !== null && $list->isEmpty() && $search !== '')

            <section class="space-y-3" wire:key="group-{{ $group?->uuid ?? 'none' }}">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($group)
                        <span @class(['size-2.5 rounded-full', match ($group->color) {
                            'red' => 'bg-red-500', 'orange' => 'bg-orange-500', 'amber' => 'bg-amber-500', 'lime' => 'bg-lime-500',
                            'emerald' => 'bg-emerald-500', 'teal' => 'bg-teal-500', 'sky' => 'bg-sky-500', 'blue' => 'bg-blue-500',
                            'indigo' => 'bg-indigo-500', 'violet' => 'bg-violet-500', 'pink' => 'bg-pink-500', default => 'bg-zinc-400',
                        }])></span>
                        <flux:heading size="lg">{{ $group->name }}</flux:heading>
                    @else
                        <flux:heading size="lg" class="text-zinc-500">{{ $groups->isEmpty() ? __('All projects') : __('Other projects') }}</flux:heading>
                    @endif
                    <flux:badge size="sm">{{ $list->count() }}</flux:badge>

                    @if ($group)
                        {{-- what the group gives its projects --}}
                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            @if ($group->claudeAccount)
                                <flux:badge size="sm" :color="$group->claudeAccount->isSubscription() ? 'violet' : 'amber'" :icon="$group->claudeAccount->isSubscription() ? 'sparkles' : 'key'">{{ $group->claudeAccount->name }}</flux:badge>
                            @endif
                            @if ($group->gitAccount)
                                <flux:badge size="sm" icon="code-bracket-square">{{ $group->gitAccount->name }}</flux:badge>
                            @endif
                        </div>
                        <flux:dropdown position="bottom" align="end" class="ms-auto">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Group actions')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil" wire:click="editGroup({{ Js::from($group->uuid) }})">{{ __('Edit the group') }}</flux:menu.item>
                                @unless ($loop->first)
                                    <flux:menu.item icon="arrow-up" wire:click="moveGroup({{ Js::from($group->uuid) }}, -1)">{{ __('Move up') }}</flux:menu.item>
                                @endunless
                                @if ($loop->index < $groups->count() - 1)
                                    <flux:menu.item icon="arrow-down" wire:click="moveGroup({{ Js::from($group->uuid) }}, 1)">{{ __('Move down') }}</flux:menu.item>
                                @endif
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteGroup({{ Js::from($group->uuid) }})" wire:confirm="{{ __('Delete the group :group? Its projects stay, in Other projects.', ['group' => $group->name]) }}">{{ __('Delete the group') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>

                @if ($list->isEmpty())
                    <div class="rounded-xl border border-dashed border-zinc-300 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700">
                        {{ __('No projects here yet: use “Move to group” in a project’s menu.') }}
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($list as $project)
                            @php($workspace = $workspaces->get($project->id))
                            @php($claude = $runs[$project->id]['claude'])
                            @php($git = $runs[$project->id]['git'])
                            <div class="group relative flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500" wire:key="project-{{ $project->uuid }}">
                                <div class="flex items-start justify-between gap-3">
                                    <a href="{{ route('studio.show', $project) }}" wire:navigate class="min-w-0 flex-1 after:absolute after:inset-0 after:rounded-xl">
                                        <flux:heading class="truncate">{{ $project->name }}</flux:heading>
                                        <flux:text class="truncate text-xs">{{ parse_url($project->repo_url, PHP_URL_HOST) ?: $project->repo_url }} · {{ $project->default_branch }}</flux:text>
                                    </a>
                                    <div class="relative z-10 flex items-center gap-1">
                                        @if ($workspace)
                                            <flux:badge size="sm" :color="$workspace->isRunning() ? 'green' : ($workspace->status->isTransitional() ? 'yellow' : 'zinc')">{{ $workspace->status->label() }}</flux:badge>
                                        @endif
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" :aria-label="__('Project actions')" />
                                            <flux:menu>
                                                <flux:menu.item icon="user-circle" wire:click="editAccounts({{ Js::from($project->uuid) }})">{{ __('Accounts for this project') }}</flux:menu.item>
                                                <flux:menu.submenu :heading="__('Move to group')" icon="folder">
                                                    @foreach ($groups as $target)
                                                        <flux:menu.item wire:click="moveProject({{ Js::from($project->uuid) }}, {{ Js::from($target->uuid) }})" :disabled="$group?->id === $target->id">{{ $target->name }}</flux:menu.item>
                                                    @endforeach
                                                    @if ($group)
                                                        <flux:menu.item wire:click="moveProject({{ Js::from($project->uuid) }})">{{ __('No group') }}</flux:menu.item>
                                                    @endif
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="folder-plus" wire:click="newGroup">{{ __('New group…') }}</flux:menu.item>
                                                </flux:menu.submenu>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($role = $roles->get($project->id))
                                        <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $role->label() }}</span>
                                        <span>·</span>
                                    @endif
                                    <span>PHP {{ $project->php_version }}</span>
                                    <span>·</span>
                                    <span>{{ $project->db_engine }}</span>
                                    <span>·</span>
                                    <span>{{ trans_choice(':count member|:count members', $project->members_count) }}</span>
                                    <span>·</span>
                                    <span class="font-mono">{{ $project->siteHost() }}</span>
                                </div>

                                {{-- What it runs on, and why: a click changes it --}}
                                <button type="button" wire:click="editAccounts({{ Js::from($project->uuid) }})" class="relative z-10 -mx-1 flex flex-wrap items-center gap-1.5 rounded-md px-1 py-0.5 text-left text-[11px] hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    @if ($claude)
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 ring-1 ring-inset',
                                            'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-950/50 dark:text-violet-300 dark:ring-violet-800' => $claude->account->isSubscription() && $claude->account->isUsable(),
                                            'bg-amber-50 text-amber-800 ring-amber-300 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-800' => ! $claude->account->isSubscription() && $claude->account->isUsable(),
                                            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-800' => ! $claude->account->isUsable(),
                                        ]) title="{{ $claude->reason() }}">
                                            <flux:icon :name="$claude->account->isSubscription() ? 'sparkles' : 'key'" variant="micro" class="size-3" />{{ $claude->account->name }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-md bg-red-50 px-1.5 py-0.5 text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-800"><flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('No Claude account') }}</span>
                                    @endif
                                    @if ($git)
                                        <span class="inline-flex min-w-0 items-center gap-1 rounded-md bg-zinc-50 px-1.5 py-0.5 text-zinc-600 ring-1 ring-inset ring-zinc-200 dark:bg-zinc-800/60 dark:text-zinc-300 dark:ring-zinc-700" title="{{ $git->reason() }}">
                                            <flux:icon.code-bracket-square variant="micro" class="size-3 shrink-0" /><span class="truncate">{{ $git->account->gitCredentials()['email'] }}</span>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-md bg-red-50 px-1.5 py-0.5 text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-800"><flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('No account on :host', ['host' => parse_url($project->repo_url, PHP_URL_HOST) ?: '?']) }}</span>
                                    @endif
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        @if ($projects->isEmpty())
            <flux:text class="text-center">{{ __('No project matches “:search”.', ['search' => $search]) }}</flux:text>
        @endif
    @endif

    {{-- A group: its name, its colour, the accounts its projects use --}}
    <flux:modal name="project-group" class="md:w-[32rem]">
        <form wire:submit="saveGroup" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $groupUuid ? __('Edit the group') : __('New group') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Personal, Open source, Work, Clients…: your own order of the projects, and the accounts they run on unless a project picks others.') }}</flux:text>
            </div>
            <flux:input wire:model="group_name" :label="__('Name')" :placeholder="__('Clients')" required />
            <flux:field>
                <flux:label>{{ __('Colour') }}</flux:label>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Models\ProjectGroup::COLORS as $color)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model="group_color" value="{{ $color }}" class="peer sr-only" />
                            <span @class(['block size-6 rounded-full ring-2 ring-transparent ring-offset-2 peer-checked:ring-zinc-900 dark:ring-offset-zinc-900 dark:peer-checked:ring-white', match ($color) {
                                'red' => 'bg-red-500', 'orange' => 'bg-orange-500', 'amber' => 'bg-amber-500', 'lime' => 'bg-lime-500',
                                'emerald' => 'bg-emerald-500', 'teal' => 'bg-teal-500', 'sky' => 'bg-sky-500', 'blue' => 'bg-blue-500',
                                'indigo' => 'bg-indigo-500', 'violet' => 'bg-violet-500', 'pink' => 'bg-pink-500', default => 'bg-zinc-400',
                            }])></span>
                        </label>
                    @endforeach
                </div>
            </flux:field>
            <flux:select wire:model="group_claude" :label="__('Claude account')" :description="__('What the projects of the group run Claude Code on.')">
                <flux:select.option value="">{{ __('Your default') }}</flux:select.option>
                @foreach ($claudeAccounts as $account)
                    <flux:select.option value="{{ $account->uuid }}">{{ $account->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="group_git" :label="__('Git account')" :description="__('Used by the projects of the group whose repository is on its host; the others keep the account on their own host.')">
                <flux:select.option value="">{{ __('The account on each repository’s host') }}</flux:select.option>
                @foreach ($gitAccounts as $account)
                    <flux:select.option value="{{ $account->uuid }}">{{ $account->name }} · {{ $account->host }} · {{ $account->gitCredentials()['email'] }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($claudeAccounts->isEmpty() || $gitAccounts->isEmpty())
                <flux:text class="text-xs">{{ __('Add more accounts in') }} <flux:link :href="route('connections.edit')" wire:navigate>{{ __('Settings → Accounts') }}</flux:link>.</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- One project: the accounts it runs on, when not the group's --}}
    <flux:modal name="project-accounts" class="md:w-[32rem]">
        @if ($accountsFor)
            @php($running = $workspaces->get($accountsFor->id)?->isRunning())
            <form wire:submit="saveAccounts" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Accounts for :project', ['project' => $accountsFor->name]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Only for you: the other members work on it with their own accounts.') }}</flux:text>
                </div>
                <flux:select wire:model="project_claude" :label="__('Claude account')">
                    <flux:select.option value="">{{ __('Automatic: :account', ['account' => $accountsInherited['claude']]) }}</flux:select.option>
                    @foreach ($claudeAccounts as $account)
                        <flux:select.option value="{{ $account->uuid }}">{{ $account->label() }}{{ $account->isUsable() ? '' : ' · '.__('not signed in') }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text class="-mt-3 text-xs">{{ __('Takes effect with the next message, no restart.') }}</flux:text>

                <flux:select wire:model="project_git" :label="__('Git account')">
                    <flux:select.option value="">{{ __('Automatic: :account', ['account' => $accountsInherited['git']]) }}</flux:select.option>
                    @foreach ($accountsGitOptions as $account)
                        <flux:select.option value="{{ $account->uuid }}">{{ $account->name }} · {{ $account->gitCredentials()['name'] }} &lt;{{ $account->gitCredentials()['email'] }}&gt;</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text class="-mt-3 text-xs">
                    @if ($accountsGitOptions->isEmpty())
                        {{ __('You have no account on :host yet: add one in', ['host' => parse_url($accountsFor->repo_url, PHP_URL_HOST) ?: $accountsFor->repo_url]) }} <flux:link :href="route('connections.edit', ['tab' => 'git'])" wire:navigate>{{ __('Settings → Accounts') }}</flux:link>.
                    @else
                        {{ __('Only your accounts on :host can push to this repository; commits carry the name and email of the account.', ['host' => parse_url($accountsFor->repo_url, PHP_URL_HOST) ?: $accountsFor->repo_url]) }}
                        @if ($running){{ __('Changing it restarts your workspace: a running conversation picks up where it was.') }}@endif
                    @endif
                </flux:text>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
