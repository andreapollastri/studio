<div class="flex h-full w-full flex-1 flex-col gap-6 max-sm:gap-4">
    <div>
        <flux:heading size="xl">{{ __('Domains') }}</flux:heading>
        <flux:text class="mt-1 max-w-3xl">{{ __('Every site and preview has an address under :domain. Add a client\'s domain here, point its DNS at this server, and a project can put its site there (staging.client.com, with :domain\'s address kept as a second one) or its previews under it (<person>-<key>.client.com), in its Settings → Server. Studio checks each domain every day: one that stops pointing here gets no new certificate.', ['domain' => $studioDomain]) }}</flux:text>
    </div>

    <form wire:submit="add" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <flux:input wire:model="name" :label="__('Domain')" placeholder="client.com" autocomplete="off" />
            </div>
            <flux:button type="submit" variant="primary" icon="plus">{{ __('Add and check') }}</flux:button>
        </div>
        <flux:checkbox wire:model.live="wildcard" :label="__('With its subdomains (*.domain): sites at <name>.domain, and the previews')" />
        <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/60">
            <flux:text class="text-xs">{{ __('The records to create at the domain\'s DNS provider:') }}</flux:text>
            <div class="mt-1 grid grid-cols-[auto_auto_1fr] gap-x-4 font-mono text-xs">
                <span>{{ $name !== '' ? strtolower(trim($name)) : 'client.com' }}</span><span>A</span><span>{{ $ip }}</span>
                @if ($wildcard)
                    <span>*.{{ $name !== '' ? strtolower(trim($name)) : 'client.com' }}</span><span>A</span><span>{{ $ip }}</span>
                @endif
            </div>
            <flux:text class="mt-1 text-xs">{{ __('A CNAME to studio.:domain works as well. A domain only for previews needs just the wildcard.', ['domain' => $studioDomain]) }}</flux:text>
        </div>
    </form>

    @if ($domains->isEmpty())
        <flux:text>{{ __('No domain yet: every project lives under :domain.', ['domain' => $studioDomain]) }}</flux:text>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="table-cards w-full text-sm">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Domain') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('DNS') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Used by') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($domains as $domain)
                        <tr wire:key="domain-{{ $domain->uuid }}">
                            <td class="px-4 py-2" data-cell="main">
                                <div class="font-mono font-medium">{{ $domain->name }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <flux:badge size="sm" :color="$domain->status->color()">{{ $domain->status->label() }}</flux:badge>
                                    @if ($domain->wildcard)<flux:badge size="sm">{{ __('with subdomains') }}</flux:badge>@endif
                                </div>
                            </td>
                            <td class="px-4 py-2 text-xs" data-cell="wide">
                                <div class="font-mono">
                                    <span @class(['text-green-600 dark:text-green-400' => $domain->namePointsHere()])>{{ $domain->name }} → {{ implode(', ', $domain->records['name'] ?? []) ?: __('nothing') }}</span>
                                    @if ($domain->wildcard)
                                        <br><span @class(['text-green-600 dark:text-green-400' => $domain->wildcardPointsHere()])>*.{{ $domain->name }} → {{ implode(', ', $domain->records['wildcard'] ?? []) ?: __('nothing') }}</span>
                                    @endif
                                </div>
                                @if ($domain->last_error)<div class="mt-1 text-amber-600 dark:text-amber-400">{{ $domain->last_error }}</div>@endif
                                @if ($domain->checked_at)<div class="mt-1 text-zinc-500">{{ __('checked :when', ['when' => $domain->checked_at->diffForHumans()]) }}</div>@endif
                            </td>
                            <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Used by') }}">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($domain->sites as $project)
                                        <flux:badge size="sm" :href="route('projects.settings', ['project' => $project, 'tab' => 'server'])" wire:navigate>{{ $project->name }} · {{ __('site') }}</flux:badge>
                                    @endforeach
                                    @foreach ($domain->previewProjects as $project)
                                        <flux:badge size="sm" :href="route('projects.settings', ['project' => $project, 'tab' => 'server'])" wire:navigate>{{ $project->name }} · {{ __('previews') }}</flux:badge>
                                    @endforeach
                                    @if ($domain->sites->isEmpty() && $domain->previewProjects->isEmpty())
                                        <span class="text-zinc-500">{{ __('no project yet') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions">
                                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="check('{{ $domain->uuid }}')" :tooltip="__('Check the DNS now')" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove('{{ $domain->uuid }}')" :disabled="$domain->sites->isNotEmpty() || $domain->previewProjects->isNotEmpty()"
                                    wire:confirm="{{ __('Remove :name? Caddy gets no new certificate for it.', ['name' => $domain->name]) }}" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
