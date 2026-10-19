{{--
    The terminals of the sshTerminals Alpine component (resources/js/ssh.js): the SSH page and
    the workspace's remote terminal. $class sizes the frame, $empty is what shows before the first.
--}}
<section wire:ignore x-ref="terminals" class="flex min-w-0 flex-1 flex-col overflow-hidden bg-zinc-950 {{ $class ?? '' }}">
    <div class="flex shrink-0 items-center gap-1 border-b border-zinc-800 px-2 py-1.5">
        <div class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
            <template x-for="session in sessions" :key="session.id">
                <div
                    class="group flex shrink-0 cursor-pointer items-center gap-2 rounded-md px-2.5 py-1 text-xs"
                    :class="active === session.id ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-200'"
                    x-on:click="focus(session.id)"
                    x-on:auxclick.prevent="$event.button === 1 && close(session.id)"
                    :title="session.address"
                >
                    <span class="size-1.5 shrink-0 rounded-full" :class="dot(session)"></span>
                    <span class="max-w-40 truncate" x-text="session.title"></span>
                    <button type="button" class="rounded text-zinc-500 hover:text-zinc-100" x-on:click.stop="close(session.id)" title="{{ __('Close') }}">
                        <flux:icon.x-mark variant="micro" class="size-3.5" />
                    </button>
                </div>
            </template>
            <span x-show="sessions.length === 0" class="px-2 text-xs text-zinc-500">{{ __('No terminals open') }}</span>
        </div>
        <div class="flex shrink-0 items-center gap-0.5" x-show="sessions.length > 1">
            <button type="button" class="rounded p-1" :class="layout === 'tabs' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-500 hover:text-zinc-200'" x-on:click="setLayout('tabs')" title="{{ __('One at a time') }}">
                <flux:icon.window variant="micro" class="size-4" />
            </button>
            <button type="button" class="rounded p-1" :class="layout === 'grid' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-500 hover:text-zinc-200'" x-on:click="setLayout('grid')" title="{{ __('All side by side') }}">
                <flux:icon.squares-2x2 variant="micro" class="size-4" />
            </button>
        </div>
    </div>

    <div class="relative min-h-0 flex-1">
        <div x-show="sessions.length === 0" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-8 text-center text-zinc-500">
            <flux:icon.command-line class="size-8" />
            <div class="text-sm">{{ $empty }}</div>
        </div>

        <div
            class="grid h-full gap-px bg-zinc-800"
            :class="{
                'grid-cols-1': layout === 'tabs' || sessions.length === 1,
                'grid-cols-1 md:grid-cols-2': layout === 'grid' && sessions.length >= 2 && sessions.length <= 4,
                'grid-cols-1 md:grid-cols-2 xl:grid-cols-3': layout === 'grid' && sessions.length > 4,
            }"
            x-show="sessions.length > 0"
        >
            <template x-for="session in sessions" :key="session.id">
                <div
                    class="relative flex min-h-0 min-w-0 flex-col bg-zinc-950"
                    x-show="layout === 'grid' || active === session.id"
                    x-on:mousedown="active = session.id"
                    :class="layout === 'grid' && active === session.id && sessions.length > 1 ? 'ring-1 ring-inset ring-zinc-600' : ''"
                >
                    <div x-show="layout === 'grid'" class="flex shrink-0 items-center gap-2 border-b border-zinc-800 px-2 py-1 text-[11px] text-zinc-400">
                        <span class="size-1.5 rounded-full" :class="dot(session)"></span>
                        <span class="truncate" x-text="session.title + ' · ' + session.address"></span>
                    </div>
                    <div class="min-h-0 flex-1 p-1.5" :id="'ssh-term-' + session.id"></div>

                    {{-- A host seen for the first time --}}
                    <div x-show="session.status === 'hostkey'" class="absolute inset-0 flex items-center justify-center bg-zinc-950/90 p-6">
                        <div class="max-w-md space-y-3 rounded-xl border border-amber-500/40 bg-zinc-900 p-5 text-sm text-zinc-200">
                            <div class="flex items-center gap-2 font-medium text-amber-400">
                                <flux:icon.shield-exclamation class="size-5" />
                                {{ __('First connection to this host') }}
                            </div>
                            <p class="text-zinc-400">{{ __('Check that the key fingerprint is the server’s (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub on the server). Once accepted, a different key will be refused.') }}</p>
                            <div class="break-all rounded bg-zinc-950 px-2 py-1.5 font-mono text-xs text-zinc-100" x-text="session.fingerprint"></div>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" x-on:click="answerHostKey(session.id, false)">{{ __('Cancel') }}</flux:button>
                                <flux:button size="sm" variant="primary" x-on:click="answerHostKey(session.id, true)">{{ __('Trust and connect') }}</flux:button>
                            </div>
                        </div>
                    </div>

                    {{-- Ended or failed --}}
                    <div x-show="session.status === 'closed' || session.status === 'error'" class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 border-t border-zinc-800 bg-zinc-900/95 px-3 py-2 text-xs">
                        <span :class="session.status === 'error' ? 'text-red-400' : 'text-zinc-400'" x-text="session.error || @js(__('Session ended.'))"></span>
                        <div class="flex shrink-0 gap-2">
                            <flux:button size="xs" variant="filled" icon="arrow-path" x-on:click="reconnect(session.id)">{{ __('Reconnect') }}</flux:button>
                            <flux:button size="xs" variant="ghost" x-on:click="close(session.id)">{{ __('Close') }}</flux:button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    @include('livewire.ssh.partials.keybar', ['visible' => 'sessions.length > 0'])
</section>
