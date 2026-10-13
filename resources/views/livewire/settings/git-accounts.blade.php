<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ $shared ? __('Service accounts') : __('Git accounts') }}</flux:heading>
        <flux:badge size="sm" :color="$this->accounts->isNotEmpty() ? 'green' : 'yellow'">{{ trans_choice(':count account|:count accounts', $this->accounts->count()) }}</flux:badge>
        <flux:dropdown position="bottom" align="end" class="ms-auto">
            <flux:button size="sm" icon="plus" icon:trailing="chevron-down">{{ __('Add an account') }}</flux:button>
            <flux:menu>
                @foreach ($forges as $item)
                    <flux:menu.item :icon="$item->icon()" wire:click="add('{{ $item->key() }}')">{{ $item->label() }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>
    <flux:text>
        @if ($shared)
            {{ __('The team\'s own tokens (a bot user, a project access token): they create repositories, register the push webhooks and deploy the project sites, and keep doing it when an administrator leaves. Choose one in a project\'s settings. Nobody commits with them: people work with their own accounts.') }}
        @else
            {{ __('As many as you work with: your personal GitHub, the company’s Azure DevOps, a client’s GitLab. Each one keeps its token and the name and email its commits carry; a project uses the account on the host of its repository, the one of its group, or the one you choose for it. Only you see them.') }}
        @endif
    </flux:text>

    @if ($this->accounts->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
            <flux:icon.code-bracket-square class="mx-auto size-8 text-zinc-400" />
            <flux:text class="mt-2">{{ $shared ? __('No service account yet: project sites deploy with the token of the administrator who created them.') : __('No git account yet: add the one of the projects you work on.') }}</flux:text>
        </div>
    @else
        <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
            @foreach ($this->accounts as $account)
                @php($definition = \App\Infrastructure\Git\Forges::make($account->connector))
                @php($used = $usage[$account->id] ?? null)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="git-{{ $account->uuid }}">
                    <flux:icon :name="$definition->icon()" class="size-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $account->name }}</span>
                            <flux:badge size="sm">{{ $account->host ?: $definition->label() }}</flux:badge>
                            @unless ($account->isHealthy())
                                <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('check the token') }}</flux:badge>
                            @endunless
                            @if ($shared && ($deploys[$account->id] ?? 0) > 0)
                                <flux:badge size="sm" color="green">{{ trans_choice('deploys :count project|deploys :count projects', $deploys[$account->id]) }}</flux:badge>
                            @endif
                        </div>
                        <div class="truncate font-mono text-xs text-zinc-500">
                            @if ($account->login)<span class="font-sans">{{ '@'.$account->login }}</span>@endif
                            @unless ($shared) · {{ $account->gitCredentials()['name'] }} &lt;{{ $account->gitCredentials()['email'] }}&gt;@endunless
                        </div>
                        @if ($used)
                            <div class="text-xs text-zinc-500">
                                {{ collect([
                                    ($used['groups'] ?? []) ? __('default of :groups', ['groups' => implode(', ', $used['groups'])]) : null,
                                    ($used['projects'] ?? 0) ? trans_choice('chosen for :count project|chosen for :count projects', $used['projects']) : null,
                                ])->filter()->implode(' · ') }}
                            </div>
                        @endif
                    </div>
                    <div class="flex gap-1">
                        @unless ($shared)
                            <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :tooltip="__('Pull requests, issues, pipelines')" :href="route('git', ['connector' => $account->connector, 'account' => $account->uuid])" wire:navigate />
                        @endunless
                        <flux:button size="xs" variant="ghost" icon="signal" :tooltip="__('Check')" wire:click="check('{{ $account->uuid }}')" />
                        <flux:button size="xs" variant="ghost" icon="pencil" :tooltip="__('Edit')" wire:click="edit('{{ $account->uuid }}')" />
                        <flux:button size="xs" variant="ghost" icon="trash" :tooltip="__('Remove')" wire:click="remove('{{ $account->uuid }}')" wire:confirm="{{ __('Remove :name from Studio?', ['name' => $account->name]) }}" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal :name="$modal" class="w-full md:w-[36rem]">
        <form wire:submit="save" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editing === 'new' ? ($shared ? __('New service account') : __('New git account')) : __('Edit :name', ['name' => $name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('The token is stored encrypted and never shown again; Studio checks it before saving.') }} <a href="{{ $forge->guide() }}" target="_blank" rel="noopener" class="underline decoration-zinc-400 underline-offset-4">{{ __('Which token, with which scopes') }}</a></flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="host" :label="__('Host')" :disabled="$editing !== 'new'">
                    @foreach ($forges as $item)
                        <flux:select.option value="{{ $item->key() }}">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="name" :label="__('Name')" :placeholder="$shared ? __('Deploy bot') : __('Personal, Work, Client X…')" required />
            </div>
            @foreach ($forge->fields() as $field)
                <div wire:key="git-credential-{{ $host }}-{{ $field->name }}">
                    @include('livewire.infrastructure.partials.field', ['field' => $editing !== 'new' && $field->secret ? (clone $field)->help(__('Leave empty to keep the stored one.')) : $field, 'model' => 'credentials.'.$field->name])
                </div>
            @endforeach
            @include('livewire.tools.partials.git-token-help', ['forge' => $forge->key(), 'admin' => $shared || auth()->user()->isAdmin()])
            @unless ($shared)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="git_name" :label="__('Commit name')" :placeholder="__('From the provider')" />
                    <flux:input type="email" wire:model="git_email" :label="__('Commit email')" :placeholder="__('From the provider')" />
                </div>
                <flux:text class="-mt-2 text-xs">{{ __('What commits made with this account carry. Empty: the name and the primary email the provider gives, or yours.') }}</flux:text>
            @endunless
            @error('credentials')
                <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
            @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Check and save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
