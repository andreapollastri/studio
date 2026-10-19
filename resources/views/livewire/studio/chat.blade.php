<div
    class="flex h-full min-h-0 flex-col"
    @if ($conversation->isBusy() || in_array($conversation->sandboxStatus(), [\App\Models\Conversation::SANDBOX_CREATING, \App\Models\Conversation::SANDBOX_REMOVING], true)) wire:poll.4s="poll" @endif
    x-data="{ shot: null }"
    x-on:studio-shot.window="shot = $event.detail.src"
    x-on:keydown.escape.window="shot = null"
>
    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700 max-md:gap-2 max-md:px-3">
        <div class="min-w-0">
            <div class="truncate text-sm font-medium">{{ $conversation->displayTitle() }}</div>
            <x-studio.status-bar :conversation="$conversation" :can-chat="$canChat" class="studio-status-bar mt-1" />
        </div>
        <div class="flex items-center gap-2 max-md:shrink-0 max-md:gap-1">
            @if ($canChat)
                <flux:modal.trigger name="chat-settings">
                    <flux:button size="xs" variant="ghost" icon="adjustments-horizontal" :tooltip="__('Model & mode')" :aria-label="__('Model & mode')" />
                </flux:modal.trigger>
            @endif
            <flux:button size="xs" variant="ghost" :icon="$showTools ? 'eye-slash' : 'eye'" wire:click="$toggle('showTools')" :tooltip="$showTools ? __('Hide details') : __('Details')" :aria-label="$showTools ? __('Hide details') : __('Details')" />
            @if ($canChat && $conversation->isBusy())
                <flux:button size="xs" variant="ghost" icon="stop" wire:click="interrupt" :aria-label="__('Interrupt')"><span class="max-sm:hidden">{{ __('Interrupt') }}</span></flux:button>
            @endif
        </div>
    </div>

    @if ($conversation->isSandboxed())
        @php($sandbox = $conversation->sandbox)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-violet-200 bg-violet-50/70 px-4 py-2 text-xs dark:border-violet-900/60 dark:bg-violet-950/20 max-md:px-3">
            <span class="flex items-center gap-1.5 font-medium text-violet-700 dark:text-violet-300">
                <flux:icon.beaker class="size-4" /> {{ __('Sandbox') }}
            </span>
            <span class="font-mono text-zinc-600 dark:text-zinc-300">{{ $sandbox['branch'] ?? '' }}</span>
            @switch($conversation->sandboxStatus())
                @case(\App\Models\Conversation::SANDBOX_CREATING)
                    <span class="flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3" /> {{ __('Preparing: a worktree of your workspace, a copy of its database, the build and a preview of its own. It takes a minute or two.') }}</span>
                    @break
                @case(\App\Models\Conversation::SANDBOX_READY)
                    @if ($sandboxInfo)
                        <span class="text-zinc-500">
                            @if (($sandboxInfo['files'] ?? 0) > 0){{ trans_choice(':count file changed|:count files changed', $sandboxInfo['files']) }} · @endif
                            {{ trans_choice(':count commit ahead of :branch|:count commits ahead of :branch', $sandboxInfo['ahead'] ?? 0, ['branch' => $sandboxInfo['workspace_branch'] ?? '']) }}
                        </span>
                    @endif
                    @if (filled($sandbox['merged_at'] ?? null))
                        <flux:badge size="sm" color="emerald" icon="check">{{ __('merged') }}</flux:badge>
                    @endif
                    @if (filled($sandbox['pushed_at'] ?? null))
                        <flux:badge size="sm" color="sky" icon="arrow-up">{{ __('pushed') }}</flux:badge>
                    @endif
                    @if ($canChat)
                        <span class="ms-auto flex flex-wrap items-center gap-1">
                            @if (filled($sandbox['url'] ?? null))
                                <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$sandbox['url']" target="_blank">{{ __('Preview') }}</flux:button>
                            @endif
                            <flux:button size="xs" variant="primary" icon="arrow-down-on-square" wire:click="mergeSandbox" wire:confirm="{{ __('Commit what the sandbox holds and merge :branch into the branch of your workspace? A conflict leaves both as they are.', ['branch' => $sandbox['branch'] ?? '']) }}" :disabled="$conversation->isBusy()">{{ __('Merge into my workspace') }}</flux:button>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                                <flux:menu>
                                    <flux:menu.item icon="arrow-up-tray" wire:click="pushSandbox" :disabled="$conversation->isBusy()">{{ __('Push the branch') }}</flux:menu.item>
                                    <flux:modal.trigger name="remove-sandbox">
                                        <flux:menu.item icon="trash" variant="danger">{{ __('Remove the sandbox') }}</flux:menu.item>
                                    </flux:modal.trigger>
                                </flux:menu>
                            </flux:dropdown>
                        </span>
                    @endif
                    @break
                @case(\App\Models\Conversation::SANDBOX_FAILED)
                    <span class="text-red-600 dark:text-red-400">{{ $sandbox['error'] ?? __('The sandbox could not be prepared.') }}</span>
                    @if ($canChat)
                        <span class="ms-auto flex gap-1">
                            <flux:button size="xs" variant="primary" icon="arrow-path" wire:click="retrySandbox">{{ __('Try again') }}</flux:button>
                            <flux:modal.trigger name="remove-sandbox"><flux:button size="xs" variant="ghost" icon="trash">{{ __('Remove') }}</flux:button></flux:modal.trigger>
                        </span>
                    @endif
                    @break
                @case(\App\Models\Conversation::SANDBOX_REMOVING)
                    <span class="flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3" /> {{ __('Removing the sandbox…') }}</span>
                    @break
                @default
                    <span class="text-zinc-500">{{ __('The sandbox was removed: this conversation stays to read. Start a new one to go on.') }}</span>
            @endswitch
        </div>

        @if ($canChat)
            <flux:modal name="remove-sandbox" class="md:w-[28rem]">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ __('Remove the sandbox?') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Its worktree, database and preview go. This conversation stays, to read. Work not committed is lost.') }}</flux:text>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button wire:click="removeSandbox(false)">{{ __('Remove, keep the branch') }}</flux:button>
                        <flux:button variant="danger" wire:click="removeSandbox(true)">{{ __('Discard with its branch') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    @endif

    <div
        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 max-xl:overscroll-contain max-md:px-3"
        x-data
        x-init="$el.scrollTop = $el.scrollHeight"
        x-on:chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
    >
        @forelse ($this->rows as $row)
            @if ($row['agents'])
                {{-- subagents started together ran in parallel: side by side --}}
                <div class="max-w-3xl space-y-1.5" wire:key="agents-{{ $row['messages'][0]->id }}">
                    @if (count($row['messages']) > 1)
                        <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-zinc-400">
                            <flux:icon.squares-2x2 class="size-3.5" /> {{ __(':count subagents in parallel', ['count' => count($row['messages'])]) }}
                        </div>
                    @endif
                    <div @class(['grid gap-2', 'sm:grid-cols-2' => count($row['messages']) > 1])>
                        @foreach ($row['messages'] as $call)
                            <x-studio.agent :call="$call" :result="$this->toolResults->get($call->tool_use_id)" :steps="$this->children->get($call->tool_use_id, collect())" :results="$this->toolResults" :conversation="$conversation" :show-tools="$showTools" />
                        @endforeach
                    </div>
                </div>
            @else
                <x-studio.message :message="$row['messages'][0]" :result="$this->toolResults->get($row['messages'][0]->tool_use_id)" :show-tools="$showTools" :conversation="$conversation" wire:key="message-{{ $row['messages'][0]->id }}" />
            @endif
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
                                @if ($alwaysRule)
                                    <flux:button size="sm" icon="check-badge" wire:click="allowAlways('{{ $this->pending->uuid }}')" :title="__('Allowed now and from now on, for you on this project; remove it in Model & mode.')">
                                        {{ __('Always allow') }} <span class="ms-1 font-mono text-xs">{{ \App\Claude\AllowRules::label($alwaysRule) }}</span>
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="deny('{{ $this->pending->uuid }}')">{{ __('Deny') }}</flux:button>
                            </div>
                            @if (! $alwaysRule && $conversation->permission_mode === 'default')
                                <flux:text class="mt-2 text-xs">{{ __('Asked too often? In Model & mode, "Ask, edits allowed" lets file edits run, and Autopilot lets a classifier decide instead of you.') }}</flux:text>
                            @endif
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
                    <div class="font-medium">{{ __('Runs without asking') }}</div>
                    @if ($ownRules->isNotEmpty())
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($ownRules as $rule)
                                <flux:badge size="sm" class="font-mono" wire:key="rule-{{ $rule->uuid }}" :title="$rule->rule">
                                    {{ \App\Claude\AllowRules::label($rule->rule) }}
                                    <flux:badge.close wire:click="forgetRule('{{ $rule->uuid }}')" :aria-label="__('Ask again for :rule', ['rule' => \App\Claude\AllowRules::label($rule->rule)])" />
                                </flux:badge>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-1 text-zinc-500">
                        {{ $ownRules->isNotEmpty()
                            ? __('Yours on this project, from "Always allow"; × asks again from the next message. Plan only still changes nothing.')
                            : __('Nothing of yours yet: "Always allow" on a request adds it here. Plan only still changes nothing.') }}
                    </div>
                    @if ($roleRules !== [])
                        <details class="mt-1.5">
                            <summary class="cursor-pointer text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">{{ trans_choice('And :count everyday command of your role|And :count everyday commands of your role', count($roleRules), ['count' => count($roleRules)]) }}</summary>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($roleRules as $rule)<flux:badge size="sm" variant="pill" class="font-mono" :title="$rule">{{ \App\Claude\AllowRules::label($rule) }}</flux:badge>@endforeach
                            </div>
                        </details>
                    @endif
                </div>
                <div class="rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800">
                    <div class="font-medium">{{ __('MCP servers') }}</div>
                    @if ($mcpNames !== [])
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($mcpNames as $name)<flux:badge size="sm" class="font-mono">{{ $name }}</flux:badge>@endforeach
                        </div>
                    @endif
                    <div class="mt-1 text-zinc-500">{{ $mcpNames !== [] ? __('From the project settings, plus any in the repository\'s .mcp.json.') : __('None from the project settings; the repository\'s .mcp.json still applies.') }}</div>
                </div>
                <div class="rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800">
                    <div class="font-medium">{{ __('Studio tools') }}</div>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @if ($browser)
                            <flux:badge size="sm" icon="globe-alt" color="sky">{{ __('browser') }}</flux:badge>
                            <flux:badge size="sm" icon="cpu-chip" class="font-mono">browser-qa</flux:badge>
                        @endif
                        <flux:badge size="sm" icon="cpu-chip" class="font-mono">code-reviewer</flux:badge>
                    </div>
                    <div class="mt-1 text-zinc-500">
                        {{ $browser
                            ? __('Claude opens the preview in a browser signed in as you, takes screenshots and reads the console; looking never asks, clicking asks unless edits are allowed. Subagents run in parallel; the repository\'s .claude/agents apply too.')
                            : __('The browser is off for this project. Subagents run in parallel; the repository\'s .claude/agents apply too.') }}
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700 max-md:p-2">
            <form wire:submit="send" class="flex items-end gap-2">
                <div class="min-w-0 flex-1">
                    <flux:textarea
                        wire:model="draft"
                        rows="2"
                        resize="none"
                        :placeholder="! $conversation->workspace->isRunning() ? __('Start the workspace to write') : (! $conversation->acceptsMessages() ? ($conversation->sandboxStatus() === \App\Models\Conversation::SANDBOX_CREATING ? __('The sandbox is being prepared…') : __('This conversation is history: its sandbox is gone')) : ($conversation->isBusy() ? __('Claude is working… your next message can wait here (interrupt to send it now)') : ($conversation->isSandboxed() ? __('Write to Claude in the sandbox… (⌘⏎ to send)') : __('Write to Claude… (⌘⏎ to send)'))))"
                        :disabled="! $conversation->workspace->isRunning() || ! $conversation->acceptsMessages()"
                        x-on:keydown.meta.enter.prevent="$wire.send()"
                        x-on:keydown.ctrl.enter.prevent="$wire.send()"
                    />
                    <flux:error name="draft" />
                </div>
                <flux:button type="submit" variant="primary" icon="paper-airplane" :disabled="! $conversation->workspace->isRunning() || $conversation->isBusy() || ! $conversation->acceptsMessages()" :aria-label="__('Send')"><span class="max-sm:hidden">{{ __('Send') }}</span></flux:button>
            </form>
        </div>
    @endif

    {{-- a screenshot full size, over everything; a click or Esc closes it --}}
    <div x-show="shot" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 sm:p-10" x-on:click="shot = null" role="dialog" aria-modal="true" aria-label="{{ __('Screenshot') }}">
        <img x-bind:src="shot" alt="{{ __('Screenshot') }}" class="max-h-full max-w-full rounded-lg shadow-2xl">
        <a x-bind:href="shot" target="_blank" class="absolute right-4 top-4 rounded-md bg-white/10 p-2 text-white hover:bg-white/20" x-on:click.stop aria-label="{{ __('Open in a new tab') }}">
            <flux:icon.arrow-top-right-on-square class="size-5" />
        </a>
    </div>
</div>
