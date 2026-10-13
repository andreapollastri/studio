@props(['conversation', 'canChat' => false])

@php
    $user = $conversation->user;
    // what the next message runs on, and what the last one really ran on (Claude Code's own word)
    $account = $user->claudeAccount();
    $ran = $conversation->runtime['auth'] ?? null;
    $chosen = \App\Claude\ModelName::label($conversation->model);
    $actual = \App\Claude\ModelName::label($conversation->runtime['model'] ?? null);
    $effort = $conversation->effort;
    $mode = $conversation->permission_mode;
    $usage = $account === 'subscription' ? $user->claude_usage : null;
    $resets = filled($usage['resets_at'] ?? null) ? \Illuminate\Support\Carbon::parse($usage['resets_at'])->timezone(config('app.timezone')) : null;
    $cost = $conversation->last_result['total_cost_usd'] ?? null;
    // a cost only means something on an API key: on a subscription it is a list-price estimate of nothing you pay
    $showCost = $cost !== null && ($ran ?? $account) === 'api_key';
    $chip = 'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 ring-1 ring-inset whitespace-nowrap';
    $plain = 'bg-zinc-50 text-zinc-600 ring-zinc-200 dark:bg-zinc-800/60 dark:text-zinc-300 dark:ring-zinc-700';
@endphp

<div {{ $attributes->class('flex flex-wrap items-center gap-1.5 text-[11px] leading-4') }}>
    {{-- Where the conversation is --}}
    <span @class([$chip, $plain])>
        <x-studio.conversation-dot :conversation="$conversation" class="size-1.5!" />
        {{ $conversation->status->label() }}
    </span>

    {{-- Which Claude: an API key is billed by the token, so it shows plainly --}}
    <a href="{{ route('connections.edit') }}" wire:navigate @class([
        $chip,
        'bg-amber-50 text-amber-800 ring-amber-300 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-800' => $account === 'api_key',
        'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-950/50 dark:text-violet-300 dark:ring-violet-800' => $account === 'subscription',
        'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-800' => $account === null,
    ]) title="{{ $account === 'api_key' ? __('Billed by the token on your Anthropic Console account') : ($account === 'subscription' ? __('Your Claude plan, within its limits') : __('Connect Claude in Settings → Connections')) }}">
        <flux:icon :name="$account === 'api_key' ? 'key' : ($account === 'subscription' ? 'sparkles' : 'exclamation-triangle')" variant="micro" class="size-3" />
        {{ $account === 'api_key' ? __('API key') : ($account === 'subscription' ? __('Subscription') : __('No Claude account')) }}
    </a>

    @if ($account === 'subscription' && $usage)
        @php($percent = $usage['percent'] ?? null)
        <span @class([
            $chip,
            $plain => ($usage['status'] ?? '') === 'allowed' || ($usage['status'] ?? null) === null,
            'bg-amber-50 text-amber-800 ring-amber-300 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-800' => ($usage['status'] ?? '') === 'allowed_warning',
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-800' => ($usage['status'] ?? '') === 'rejected',
        ]) title="{{ __('What Claude Code last said about your plan, :when.', ['when' => \Illuminate\Support\Carbon::parse($usage['at'] ?? now())->diffForHumans()]) }}">
            @if ($percent !== null)
                <span class="h-1 w-10 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <span @class(['block h-full rounded-full', 'bg-violet-500' => $percent < 80, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-red-500' => $percent >= 100]) style="width: {{ $percent }}%"></span>
                </span>
                {{ __('plan :percent%', ['percent' => $percent]) }}
            @elseif (($usage['status'] ?? '') === 'rejected')
                {{ __('limit reached') }}
            @elseif (($usage['status'] ?? '') === 'allowed_warning')
                {{ __('near the limit') }}
            @else
                {{ __('within limits') }}
            @endif
            @if ($resets && $resets->isFuture())
                · {{ __('resets :time', ['time' => $resets->isToday() ? $resets->format('H:i') : $resets->translatedFormat('D H:i')]) }}
            @endif
        </span>
    @elseif ($showCost)
        <span @class([$chip, $plain]) title="{{ __('Estimated by Claude Code from its price list; your Console bills the exact amount.') }}">
            ≈ {{ number_format((float) $cost, 2) }} $ {{ __('last turn') }}
        </span>
    @endif

    {{-- How it runs: model, effort, mode — a click opens Model & mode --}}
    @php($settings = $canChat ? 'button' : 'span')
    <{{ $settings }} @if ($canChat) type="button" x-on:click="$flux.modal('chat-settings').show()" @endif @class([$chip, $plain, 'cursor-pointer hover:ring-zinc-400 dark:hover:ring-zinc-500' => $canChat]) title="{{ __('Model') }}">
        <flux:icon.cpu-chip variant="micro" class="size-3" />
        @if ($chosen)
            {{ $chosen }}
        @else
            {{ __('Default') }}@if ($actual)<span class="text-zinc-400"> · {{ $actual }}</span>@endif
        @endif
    </{{ $settings }}>
    <{{ $settings }} @if ($canChat) type="button" x-on:click="$flux.modal('chat-settings').show()" @endif @class([$chip, $plain, 'cursor-pointer hover:ring-zinc-400 dark:hover:ring-zinc-500' => $canChat]) title="{{ __('Effort') }}">
        <flux:icon.light-bulb variant="micro" class="size-3" />
        {{ __('effort :level', ['level' => mb_strtolower(__((string) (config('studio.efforts')[(string) $effort] ?? $effort ?: 'Default')))]) }}
    </{{ $settings }}>
    <{{ $settings }} @if ($canChat) type="button" x-on:click="$flux.modal('chat-settings').show()" @endif @class([
        $chip,
        'cursor-pointer hover:ring-zinc-400 dark:hover:ring-zinc-500' => $canChat,
        $plain => ! in_array($mode, ['auto', 'plan'], true),
        'bg-orange-50 text-orange-800 ring-orange-300 dark:bg-orange-950/50 dark:text-orange-300 dark:ring-orange-800' => $mode === 'auto',
        'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-800' => $mode === 'plan',
    ]) title="{{ __('Mode') }}">
        <flux:icon :name="match ($mode) { 'auto' => 'rocket-launch', 'plan' => 'map', 'acceptEdits' => 'pencil-square', default => 'hand-raised' }" variant="micro" class="size-3" />
        {{ $conversation->modeLabel() }}
    </{{ $settings }}>
</div>
