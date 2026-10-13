<div class="h-full overflow-y-auto">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('studio.show', $project) }}" class="truncate text-sm font-medium" wire:navigate>{{ $project->name }}</a>
            <x-studio.project-tabs :project="$project" current="access" />
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-5xl space-y-8 p-6 max-md:p-4">
        <div>
            <flux:heading size="xl">{{ __('Access') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The passwords, SSH connections and folders of :project that your role on it opens. They are tied to the project from their own tools, by whoever owns them or by an administrator.', ['project' => $project->name]) }}</flux:text>
        </div>

        {{-- Passwords --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <flux:icon.key class="size-5 text-zinc-400" />
                    <flux:heading size="lg" class="truncate">{{ __('Password manager') }}</flux:heading>
                    <flux:badge size="sm">{{ $passwords->count() }}</flux:badge>
                </div>
                <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('vault', ['view' => 'project', 'project' => $project->uuid])" wire:navigate><span class="max-sm:hidden">{{ __('Open in the password manager') }}</span><span class="sm:hidden">{{ __('Open') }}</span></flux:button>
            </div>
            @if ($passwords->isEmpty())
                <flux:text class="text-sm">{{ __('None tied to this project for your role.') }}</flux:text>
            @else
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($passwords as $item)
                        <a href="{{ route('vault', ['view' => 'project', 'project' => $project->uuid]) }}" wire:navigate class="rounded-lg border border-zinc-200 px-3 py-2 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500" wire:key="password-{{ $item->uuid }}">
                            <div class="truncate text-sm font-medium">{{ $item->title }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ collect([$item->username, $item->url ? parse_url($item->url, PHP_URL_HOST) ?: $item->url : null])->filter()->implode(' · ') ?: '—' }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- SSH --}}
        @if (auth()->user()->canUseSsh())
            <section class="space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <flux:icon.command-line class="size-5 text-zinc-400" />
                        <flux:heading size="lg" class="truncate">{{ __('SSH connections') }}</flux:heading>
                        <flux:badge size="sm">{{ $connections->count() }}</flux:badge>
                    </div>
                    <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('ssh')" wire:navigate><span class="max-sm:hidden">{{ __('Open the SSH terminal') }}</span><span class="sm:hidden">{{ __('Open') }}</span></flux:button>
                </div>
                @if ($connections->isEmpty())
                    <flux:text class="text-sm">{{ __('None tied to this project for your role.') }}</flux:text>
                @else
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($connections as $connection)
                            <div class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700" wire:key="ssh-{{ $connection->uuid }}">
                                <div class="truncate text-sm font-medium">{{ $connection->name }}</div>
                                <div class="truncate font-mono text-xs text-zinc-500">{{ $connection->address() }}</div>
                                <div class="mt-1 text-xs {{ $connection->canConnect(auth()->user()) ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400' }}">
                                    {{ $connection->canConnect(auth()->user()) ? __('ready: your credential is set') : __('add your key or password on the SSH page') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        {{-- Drive --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <flux:icon.cloud class="size-5 text-zinc-400" />
                    <flux:heading size="lg" class="truncate">{{ __('Folders') }}</flux:heading>
                    <flux:badge size="sm">{{ $folders->count() }}</flux:badge>
                </div>
                <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('drive')" wire:navigate><span class="max-sm:hidden">{{ __('Open the drive') }}</span><span class="sm:hidden">{{ __('Open') }}</span></flux:button>
            </div>
            @if ($folders->isEmpty())
                <flux:text class="text-sm">{{ __('None tied to this project for your role.') }}</flux:text>
            @else
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($folders as $folder)
                        <a href="{{ route('drive', ['folder' => $folder->uuid]) }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500" wire:key="folder-{{ $folder->uuid }}">
                            <flux:icon.folder class="size-5 shrink-0 text-sky-500" />
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">{{ $folder->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $drive->canWrite($folder->id) ? __('view and edit') : __('view') }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
