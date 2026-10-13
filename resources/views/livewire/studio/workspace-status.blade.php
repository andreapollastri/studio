<div
    class="flex items-center gap-2"
    {{-- On its way somewhere: look every 3 seconds; otherwise now and then, in case it changed elsewhere. --}}
    @if ($workspace->status->isTransitional()) wire:poll.3s="refreshStatus" @else wire:poll.30s.visible="refreshStatus" @endif
>
    <flux:badge size="sm" :color="$workspace->isRunning() ? 'green' : ($workspace->status->isTransitional() ? 'yellow' : ($workspace->status === \App\Enums\WorkspaceStatus::Failed ? 'red' : 'zinc'))">
        @if ($workspace->status->isTransitional())
            <flux:icon.loading class="me-1 size-3" />
        @endif
        {{ __('workspace') }} · {{ $workspace->status->label() }}
    </flux:badge>
    @if ($workspace->isRunning())
        <flux:button size="xs" variant="ghost" icon="stop" wire:click="stop" wire:confirm="{{ __('Stop the workspace? Conversations stay, the agent stops.') }}">{{ __('Stop') }}</flux:button>
    @elseif ($workspace->status === \App\Enums\WorkspaceStatus::New)
        <flux:button size="xs" variant="ghost" icon="play" wire:click="provision">{{ __('Create') }}</flux:button>
    @elseif (! $workspace->status->isTransitional())
        <flux:button size="xs" variant="ghost" icon="play" wire:click="start">{{ __('Start') }}</flux:button>
    @endif
    @if ($workspace->last_error && $workspace->status === \App\Enums\WorkspaceStatus::Failed)
        <flux:tooltip :content="$workspace->last_error">
            <flux:icon.exclamation-triangle class="size-4 text-red-500" />
        </flux:tooltip>
    @endif
</div>
