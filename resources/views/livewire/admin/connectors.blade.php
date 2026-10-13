<div class="flex h-full w-full flex-1 flex-col gap-8 max-sm:gap-6">
    <div>
        <flux:heading size="xl">{{ __('Connectors') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The services Studio drives through their APIs, with accounts that administrators keep. Turn on the infrastructure the team uses; then, from each connector\'s page, attach the servers, zones and apps of a project to it, for the roles that should see them and act on them.') }}</flux:text>
    </div>

    <section class="flex flex-col gap-3">
        <div>
            <flux:heading size="lg">{{ __('Infrastructure') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('The clouds and services your servers run on. Their pages are the administrators\'; the members of a project see only what is attached to it, in its Infrastructure tab.') }}</flux:text>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($connectors as $key => $connector)
                @php($on = (bool) ($enabled[$key] ?? false))
                @php($count = (int) ($accounts[$key] ?? 0))
                @php($linked = (int) ($attached[$key] ?? 0))
                <div wire:key="connector-{{ $key }}" @class([
                    'flex flex-col gap-3 rounded-xl border p-4',
                    'border-zinc-200 dark:border-zinc-700' => ! $on,
                    'border-zinc-400 dark:border-zinc-500' => $on,
                ])>
                    <div class="flex items-start gap-3">
                        <flux:icon :name="$connector->icon()" class="mt-0.5 size-6 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading>{{ $connector->label() }}</flux:heading>
                                <flux:badge size="sm" :color="$count > 0 ? 'blue' : 'zinc'">{{ trans_choice(':count account|:count accounts', $count) }}</flux:badge>
                                @if ($linked > 0)
                                    <flux:badge size="sm" color="sky" icon="link">{{ trans_choice(':count attached|:count attached', $linked) }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="mt-1 text-xs">{{ $connector->description() }}</flux:text>
                        </div>
                        <flux:switch :checked="$on" wire:click="toggle({{ Js::from($key) }})" :aria-label="__('Enable :connector', ['connector' => $connector->label()])" />
                    </div>

                    @if ($on)
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                            <flux:text class="text-xs">{{ __('Administrators; projects by role') }}</flux:text>
                            <flux:button size="xs" variant="ghost" icon:trailing="arrow-right" class="ms-auto" :href="route('infrastructure', $key)" wire:navigate>{{ $count > 0 ? __('Open') : __('Add an account') }}</flux:button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="flex flex-col gap-3">
        <div>
            <flux:heading size="lg">{{ __('Git hosting') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('GitHub, GitLab (gitlab.com or self-managed), Bitbucket Cloud and Azure DevOps are there for everyone: each person adds their own accounts in Settings → Accounts and works as themselves. A project can live on any of them.') }}</flux:text>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <livewire:settings.git-accounts :shared="true" />
        </div>
    </section>

    <flux:callout icon="shield-check" variant="secondary">
        <flux:callout.text>{{ __('Every administrator can do on an infrastructure connector\'s accounts everything their credentials allow, after confirming their password: give each account the narrowest token the job needs. Project members never see an account or its token, only what is attached to their project and the actions handed to their role. On the git hosts everyone acts as themselves, with their own tokens. Every action is written to Studio\'s log with who did it.') }}</flux:callout.text>
    </flux:callout>
</div>
