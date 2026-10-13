<div
    class="flex h-full min-h-0 flex-col"
    @if ($conversation->isBusy()) wire:poll.4s="poll" @endif
>
    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
        <div class="min-w-0">
            <div class="truncate text-sm font-medium">{{ $conversation->displayTitle() }}</div>
            <x-studio.status-bar :conversation="$conversation" :can-chat="$canChat" class="mt-1" />
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
            @php($questions = $this->pending->questions())
            @php($total = count($questions))
            {{-- One question at a time: the tabs step through them, the answers stay in one form. --}}
            <div wire:key="questions-{{ $this->pending->uuid }}" class="max-w-3xl rounded-xl border border-sky-300 bg-sky-50 p-4 dark:border-sky-700/60 dark:bg-sky-900/20"
                x-data="{
                    step: 0,
                    total: {{ $total }},
                    answered(i) {
                        const choice = $wire.choices?.[i];
                        const own = $wire.others?.[i];
                        return (Array.isArray(choice) ? choice.length > 0 : !! choice) || (typeof own === 'string' && own.trim() !== '');
                    },
                    allAnswered() { return [...Array(this.total).keys()].every((i) => this.answered(i)); },
                }"
                x-on:question-step.window="step = $event.detail.step">
                <div class="flex items-start gap-3">
                    <flux:icon.chat-bubble-left-ellipsis class="mt-0.5 size-5 shrink-0 text-sky-600" />
                    <form wire:submit="answer('{{ $this->pending->uuid }}')" class="min-w-0 flex-1 space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold">{{ $total > 1 ? __('Claude has :count questions for you', ['count' => $total]) : __('Claude has a question for you') }}</div>
                            @if ($total > 1)
                                <div class="shrink-0 text-xs tabular-nums text-zinc-500" x-text="`${step + 1} / ${total}`"></div>
                            @endif
                        </div>

                        @if ($total > 1)
                            <div class="flex flex-wrap gap-1 border-b border-sky-200 dark:border-sky-800/60" role="tablist">
                                @foreach ($questions as $i => $question)
                                    <button type="button" role="tab" x-on:click="step = {{ $i }}" :aria-selected="step === {{ $i }}"
                                        class="-mb-px flex items-center gap-1.5 rounded-t-md border-b-2 px-2.5 py-1.5 text-xs font-medium transition"
                                        :class="step === {{ $i }} ? 'border-sky-600 text-sky-700 dark:text-sky-300' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'">
                                        @if ($errors->has("choices.{$i}"))
                                            <span class="size-1.5 rounded-full bg-red-500"></span>
                                        @else
                                            <flux:icon.check x-show="answered({{ $i }})" x-cloak class="size-3 text-sky-600" />
                                        @endif
                                        {{ $question['header'] !== '' ? $question['header'] : __('Question :n', ['n' => $i + 1]) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($questions as $i => $question)
                            <div wire:key="question-{{ $this->pending->uuid }}-{{ $i }}" x-show="step === {{ $i }}" @if ($i > 0) x-cloak @endif role="tabpanel">
                                @if ($question['header'] !== '' && $total === 1)
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
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($total > 1)
                                    <flux:button size="sm" variant="ghost" icon="chevron-left" x-show="step > 0" x-cloak x-on:click="step--">{{ __('Back') }}</flux:button>
                                    <flux:button size="sm" variant="filled" icon:trailing="chevron-right" x-show="step < total - 1" x-on:click="step++">{{ __('Next') }}</flux:button>
                                @endif
                                <span @if ($total > 1) x-show="step === total - 1 || allAnswered()" x-cloak @endif>
                                    <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">{{ __('Answer') }}</flux:button>
                                </span>
                                <flux:button size="sm" variant="ghost" wire:click="skip('{{ $this->pending->uuid }}')" class="ms-auto">{{ __('Skip') }}</flux:button>
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
                                <flux:button size="sm" variant="primary" icon="check" wire:click="allow('{{ $this->pending->uuid }}')">{{ __('Allow') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="deny('{{ $this->pending->uuid }}')">{{ __('Deny') }}</flux:button>
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
