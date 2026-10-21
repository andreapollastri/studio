{{-- Whether a domain of Administration → Domains opens to anybody: online, its hosts want no Studio login. --}}
<flux:text class="flex items-center gap-1.5 text-xs">
    @if ($choice->online)
        <flux:icon.globe-alt variant="micro" class="size-3.5 shrink-0 text-green-600" />
        {{ __(':name is online: anybody opens it, without the Studio login (address lists still apply).', ['name' => $choice->name]) }}
    @else
        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-zinc-500" />
        {{ __(':name is not online: behind the Studio login, as the rest.', ['name' => $choice->name]) }}
    @endif
    @if (auth()->user()->isAdmin())
        <flux:link :href="route('admin.domains')" wire:navigate>{{ __('Domains') }}</flux:link>
    @endif
</flux:text>
