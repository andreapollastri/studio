<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Users') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Studio is invite-only: create the account here and share the initial password once. The person then connects :provider and Claude in their settings.', ['provider' => $git->label()]) }}</flux:text>
        </div>
        <flux:button icon="plus" variant="primary" wire:click="create">{{ __('New user') }}</flux:button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Role') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Connections') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Projects') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $user->name }}</div>
                            @if ($user->handle)<div class="font-mono text-xs text-zinc-500">{{ $user->handle }}</div>@endif
                        </td>
                        <td class="px-4 py-2 text-xs">{{ $user->email }}</td>
                        <td class="px-4 py-2">
                            <flux:select size="sm" wire:change="setRole({{ $user->id }}, $event.target.value)">
                                @foreach ($roles as $role)
                                    <flux:select.option value="{{ $role->value }}" :selected="$user->role === $role">{{ $role->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <div class="flex flex-wrap gap-1">
                                <flux:badge size="sm" :color="$user->hasGit() ? 'green' : 'zinc'">{{ $git->label() }}{{ $user->git_login ? ' · '.$user->git_login : '' }}</flux:badge>
                                <flux:badge size="sm" :color="$user->hasClaude() ? 'green' : 'zinc'">Claude</flux:badge>
                                @if ($user->two_factor_confirmed_at)<flux:badge size="sm" color="green">2FA</flux:badge>@endif
                            </div>
                        </td>
                        <td class="px-4 py-2 text-end tabular-nums">{{ $user->projects_count }}</td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            @unless ($user->is(auth()->user()))
                                <flux:button size="xs" variant="ghost" icon="key" :tooltip="__('Reset password')" wire:click="resetPassword({{ $user->id }})" wire:confirm="{{ __('Give :name a new password? Their sessions are closed and their 2FA is removed; they set it up again after logging in.', ['name' => $user->name]) }}" />
                            @endunless
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove({{ $user->id }})" wire:confirm="{{ __('Delete the account? Their workspaces and conversations are removed from Studio.') }}" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <flux:modal name="user-form" class="md:w-[32rem]">
        @if ($generatedPassword && $resetFor)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('New password for :name', ['name' => $resetFor]) }}</flux:heading>
                <flux:text>{{ __('It will not be shown again: hand it over in person or on a channel you trust. Their sessions are closed and 2FA is off; they choose their own password in Settings and set 2FA up again.') }}</flux:text>
                <flux:input :value="$generatedPassword" readonly copyable />
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @elseif ($generatedPassword)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Account created') }}</flux:heading>
                <flux:text>{{ __('This initial password will not be shown again. The person can change it in Settings, then connect :provider and Claude.', ['provider' => $git->label()]) }}</flux:text>
                <flux:input :value="$generatedPassword" readonly copyable />
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @else
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">{{ __('New user') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />
                <flux:select wire:model="role" :label="__('Role')">
                    @foreach ($roles as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                {{-- full width: its description would push it out of line with a field beside it --}}
                <flux:input wire:model="handle" :label="__('Handle')" :description="__('Optional; set from the :provider login when the person connects', ['provider' => $git->label()])" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
