<div
    class="flex h-full min-h-0 flex-col"
    @if ($conversation->isBusy()) wire:poll.4s="poll" @endif
>
    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
        <div class="min-w-0">
            <div class="truncate text-sm font-medium">{{ $conversation->displayTitle() }}</div>
            <div class="text-xs text-zinc-500">
                {{ $conversation->status->label() }}
                · {{ $conversation->modeLabel() }}
                @if ($conversation->model) · {{ $conversation->model }} @endif
                @if ($conversation->effort) · {{ __('effort') }} {{ $conversation->effort }} @endif
                @if ($conversation->last_result['total_cost_usd'] ?? null) · {{ number_format((float) $conversation->last_result['total_cost_usd'], 2) }} $ {{ __('last turn') }} @endif
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if ($canChat)
                <flux:modal.trigger name="chat-settings">
                    <flux:button size="xs" variant="ghost" icon="adjustments-horizontal">{{ __('Model & mode') }}</flux:button>
                </flux:modal.trigger>
            @endif
            <flux:button size="xs" variant="ghost" :icon="$showTools ? 'eye-slash' : 'eye'" wire:click="$toggle('showTools')">{{ $showTools ? __('Hide details') : __('Details') }}</flux:button>
            @if ($canChat && $conversation->isBusy())
                <flux:button size="xs" variant="ghost" icon="stop" wire:click="interrupt">{{ __('Interrupt') }}</flux:button>
            @endif
        </div>
    </div>

    <div
        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4"
        x-data
        x-init="$el.scrollTop = $el.scrollHeight"
        x-on:chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
    >
        @forelse ($this->messages as $message)
            <x-studio.message :message="$message" :result="$this->toolResults->get($message->tool_use_id)" :show-tools="$showTools" />
        @empty
            <div class="py-10 text-center text-sm text-zinc-500">
                {{ __('Write what you want to achieve. Claude works in your workspace and asks you before sensitive actions.') }}
            </div>
        @endforelse

        @if ($streaming !== '')
            <div class="max-w-3xl whitespace-pre-wrap text-sm leading-relaxed">{{ $streaming }}<span class="animate-pulse">▍</span></div>
        @elseif ($conversation->status === \App\Enums\ConversationStatus::Running)
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <flux:icon.loading class="size-3" /> {{ __('Claude is working…') }}
            </div>
        @endif

        @if ($this->pending?->isQuestion())
            <div class="max-w-3xl rounded-xl border border-sky-300 bg-sky-50 p-4 dark:border-sky-700/60 dark:bg-sky-900/20">
                <div class="flex items-start gap-3">
                    <flux:icon.chat-bubble-left-ellipsis class="mt-0.5 size-5 shrink-0 text-sky-600" />
                    <form wire:submit="answer({{ $this->pending->id }})" class="min-w-0 flex-1 space-y-5">
                        <div class="text-sm font-semibold">{{ __('Claude has a question for you') }}</div>
                        @foreach ($this->pending->questions() as $i => $question)
                            <div wire:key="question-{{ $this->pending->id }}-{{ $i }}">
                                @if ($question['header'] !== '')
                                    <flux:badge size="sm" color="sky">{{ $question['header'] }}</flux:badge>
                                @endif
                                <div class="mt-1 text-sm font-medium">{{ $question['question'] }}</div>
                                @if ($canChat)
                                    @if ($question['multiSelect'])
                                        <flux:checkbox.group wire:model="choices.{{ $i }}" class="mt-2">
                                            @foreach ($question['options'] as $option)
                                                <flux:checkbox :value="$option['label']" :label="$option['label']" :description="$option['description'] ?: null" />
                                            @endforeach
                                        </flux:checkbox.group>
                                    @else
                                        <flux:radio.group wire:model="choices.{{ $i }}" class="mt-2">
                                            @foreach ($question['options'] as $option)
                                                <flux:radio :value="$option['label']" :label="$option['label']" :description="$option['description'] ?: null" />
                                            @endforeach
                                        </flux:radio.group>
                                    @endif
                                    <flux:input size="sm" wire:model="others.{{ $i }}" class="mt-2" :placeholder="__('Or write your own answer')" />
                                    <flux:error name="choices.{{ $i }}" />
                                @else
                                    <ul class="mt-1 list-disc ps-5 text-xs text-zinc-600 dark:text-zinc-300">
                                        @foreach ($question['options'] as $option)
                                            <li>{{ $option['label'] }}@if ($option['description'] !== '') — {{ $option['description'] }}@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                        @if ($canChat)
                            <div class="flex flex-wrap gap-2">
                                <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">{{ __('Answer') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="skip({{ $this->pending->id }})">{{ __('Skip') }}</flux:button>
                            </div>
                        @endif
                    </form>
                </div>
            </div>
        @elseif ($this->pending)
            <div class="max-w-3xl rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-900/20">
                <div class="flex items-start gap-3">
                    <flux:icon.hand-raised class="mt-0.5 size-5 shrink-0 text-amber-600" />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold">{{ __('Claude wants to run :tool', ['tool' => $this->pending->tool_name]) }}</div>
                        <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-white/70 p-2 font-mono text-xs dark:bg-black/30">{{ $this->pending->summary() }}</pre>
                        @if ($this->pending->description)
                            <div class="mt-2 text-xs text-zinc-600 dark:text-zinc-300">{{ $this->pending->description }}</div>
                        @endif
                        @if ($canChat)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:button size="sm" variant="primary" icon="check" wire:click="allow({{ $this->pending->id }})">{{ __('Allow') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="deny({{ $this->pending->id }})">{{ __('Deny') }}</flux:button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($canChat)
        <flux:modal name="chat-settings" class="md:w-[28rem]">
            <form wire:submit="saveSettings" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Model & mode') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('For this conversation, from the next message on. The session keeps its memory.') }}</flux:text>
                </div>
                <flux:select wire:model="model" :label="__('Model')">
                    @foreach ($models as $option)
                        <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="effort" :label="__('Effort')" :description="__('How hard the model thinks before answering; higher is slower and costs more.')">
                    @foreach ($efforts as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:radio.group wire:model="mode" :label="__('Mode')">
                    @foreach ($modes as $value => $definition)
                        <flux:radio :value="$value" :label="$definition['label']" :description="$definition['hint']" />
                    @endforeach
                </flux:radio.group>
                <flux:error name="mode" />
                <div class="rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800">
                    <div class="font-medium">{{ __('MCP servers') }}</div>
                    @if ($mcpNames !== [])
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($mcpNames as $name)<flux:badge size="sm" class="font-mono">{{ $name }}</flux:badge>@endforeach
                        </div>
                    @endif
                    <div class="mt-1 text-zinc-500">{{ $mcpNames !== [] ? __('From the project settings, plus any in the repository\'s .mcp.json.') : __('None from the project settings; the repository\'s .mcp.json still applies.') }}</div>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
            <form wire:submit="send" class="flex items-end gap-2">
                <div class="min-w-0 flex-1">
                    <flux:textarea
                        wire:model="draft"
                        rows="2"
                        resize="none"
                        :placeholder="! $conversation->workspace->isRunning() ? __('Start the workspace to write') : ($conversation->isBusy() ? __('Claude is working… your next message can wait here (interrupt to send it now)') : __('Write to Claude… (⌘⏎ to send)'))"
                        :disabled="! $conversation->workspace->isRunning()"
                        x-on:keydown.meta.enter.prevent="$wire.send()"
                        x-on:keydown.ctrl.enter.prevent="$wire.send()"
                    />
                    <flux:error name="draft" />
                </div>
                <flux:button type="submit" variant="primary" icon="paper-airplane" :disabled="! $conversation->workspace->isRunning() || $conversation->isBusy()">{{ __('Send') }}</flux:button>
            </form>
        </div>
    @endif
</div>
