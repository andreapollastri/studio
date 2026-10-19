<?php

namespace App\Livewire\Studio;

use App\Actions\Studio\DecidePermission;
use App\Actions\Studio\StartTurn;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Claude\AllowRules;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PermissionRequest;
use App\Models\PermissionRule;
use App\Workspaces\Sandboxes;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class Chat extends Component
{
    use AuthorizesRequests;

    public Conversation $conversation;

    public string $draft = '';

    public bool $showTools = false;

    public string $model = '';

    public string $effort = '';

    public string $mode = 'default';

    /**
     * Answers to Claude's questions (AskUserQuestion), by question index: a label, or labels for a multiple choice.
     *
     * @var array<int, string|list<string>>
     */
    public array $choices = [];

    /**
     * The person's own words for a question, instead of (or, on a multiple choice, besides) the options.
     *
     * @var array<int, string>
     */
    public array $others = [];

    /** The dot this conversation showed last: when it changes, the list on the left is told. */
    #[Locked]
    public string $signal = '';

    /** The sandbox's status last drawn: when it moves, the pane on the right is told. */
    #[Locked]
    public ?string $sandboxStatus = null;

    /**
     * The sandbox's working tree and its distance from the workspace's branch, read when a turn ends.
     *
     * @var array<string, mixed>|null
     */
    public ?array $sandboxInfo = null;

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);
        $this->conversation = $conversation;
        $this->model = (string) $conversation->model;
        $this->effort = (string) $conversation->effort;
        $this->mode = $conversation->permission_mode;
        $this->signal = $conversation->signal();
        $this->sandboxStatus = $conversation->sandboxStatus();
        $this->readSandbox();
    }

    /**
     * Model, effort and permission mode apply from the next message on: the
     * bridge restarts the agent with the new flags and resumes the session.
     */
    public function saveSettings(): void
    {
        $this->authorize('update', $this->conversation);

        $models = array_column((array) config('studio.models', []), 'id');
        $modes = $this->allowedModes();

        $this->validate([
            'model' => ['nullable', Rule::in($models)],
            'effort' => ['nullable', Rule::in(array_keys((array) config('studio.efforts', [])))],
            'mode' => ['required', Rule::in($modes)],
        ], [
            'mode.in' => __('Your role cannot use this mode.'),
        ]);

        $this->conversation->forceFill([
            'model' => $this->model !== '' ? $this->model : null,
            'effort' => $this->effort !== '' ? $this->effort : null,
            'permission_mode' => $this->mode,
        ])->save();

        Flux::modal('chat-settings')->close();
        Flux::toast(variant: 'success', text: __('Settings apply from the next message.'));
    }

    /** @return list<string> */
    public function allowedModes(): array
    {
        $modes = (array) config('studio.modes_by_role.'.(auth()->user()->roleIn($this->conversation->workspace->project)->value ?? 'client'), []);

        return array_values(array_unique([...$modes, $this->conversation->permission_mode]));
    }

    /**
     * Something was stored: the browser asks once per burst of the conversation's broadcasts
     * (resources/js/chat-stream.js, which also shows the text as it is typed), and every few
     * seconds while the agent works, for when no websocket is around.
     */
    public function poll(): void
    {
        $this->reload();
    }

    /** Started or stopped from the header: the composer opens or closes with it. */
    #[On('workspace-updated')]
    public function onWorkspaceUpdated(): void
    {
        $this->conversation->workspace->refresh();
    }

    public function send(StartTurn $start): void
    {
        $this->authorize('update', $this->conversation);

        $this->validate(['draft' => ['required', 'string', 'max:20000']]);

        try {
            $start->handle($this->conversation, $this->draft);
        } catch (InvalidArgumentException $e) {
            $this->addError('draft', $e->getMessage());

            return;
        }

        $this->draft = '';
        $this->reload();
    }

    /**
     * The person is about to write: the bridge starts the CLI for the next turn now, after this
     * response has gone, so Enter does not wait for it to boot. Nothing when it could not be used.
     */
    public function warm(): void
    {
        $this->authorize('update', $this->conversation);

        $conversation = $this->conversation;

        if (! $conversation->workspace->isRunning() || $conversation->isBusy() || ! $conversation->acceptsMessages()) {
            return;
        }

        app()->terminating(fn () => rescue(fn () => (new BridgeClient($conversation->workspace))->warm($conversation), report: false));
    }

    /**
     * What "/" offers in the input: the skills and commands this conversation can start, read
     * through the bridge at most once a minute, or why they could not be read (the menu says it,
     * instead of an empty list that looks like there are none).
     *
     * @return array{skills: list<array{name: string, description: string, source: string, argument_hint?: string}>, error: string|null}
     */
    public function skills(): array
    {
        $this->authorize('update', $this->conversation);

        $conversation = $this->conversation;

        if (! $conversation->workspace->isRunning()) {
            return ['skills' => [], 'error' => __('The workspace is not running.')];
        }

        // a bridge that does not answer leaves nothing in the cache: the next "/" asks again
        try {
            $skills = Cache::remember('studio.skills.'.$conversation->id, 60, fn () => (new BridgeClient(
                $conversation->workspace,
                $conversation->isSandboxed() ? $conversation->sandboxName() : null,
            ))->skills($conversation));
        } catch (Throwable $e) {
            // the bridge, or anything on the way to it (the conversation's Claude account): logged, and said
            report($e);

            return ['skills' => [], 'error' => __('The skills could not be read: :reason', ['reason' => $e->getMessage()])];
        }

        return ['skills' => $skills, 'error' => null];
    }

    public function allow(string $permissionId, DecidePermission $decide): void
    {
        $this->decide($permissionId, true, $decide);
    }

    public function deny(string $permissionId, DecidePermission $decide): void
    {
        $this->decide($permissionId, false, $decide);
    }

    /** Allowed, and never asked again on this project: the rule Claude Code suggested, or Studio's for the command. */
    public function allowAlways(string $permissionId, DecidePermission $decide): void
    {
        $request = $this->conversation->permissionRequests()->where('uuid', $permissionId)->firstOrFail();

        $this->decide($permissionId, true, $decide, AllowRules::suggest($request));
    }

    /** A rule added with "Always allow" goes: that action asks again from the next message. */
    public function forgetRule(string $ruleId): void
    {
        $this->authorize('update', $this->conversation);

        PermissionRule::query()
            ->where('uuid', $ruleId)
            ->where('user_id', $this->conversation->user_id)
            ->where('project_id', $this->conversation->workspace->project_id)
            ->delete();
    }

    /** Claude's questions answered: every question needs a choice or the person's own words. */
    public function answer(string $permissionId, DecidePermission $decide): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->where('uuid', $permissionId)->firstOrFail();
        $answers = [];
        $this->resetErrorBag();

        foreach ($request->questions() as $i => $question) {
            $labels = array_column($question['options'], 'label');
            $picked = array_values(array_intersect($labels, array_map('strval', (array) ($this->choices[$i] ?? []))));
            $own = trim((string) ($this->others[$i] ?? ''));

            if ($own !== '') {
                $picked = $question['multiSelect'] ? [...$picked, $own] : [$own];
            }

            if ($picked === []) {
                $this->addError("choices.{$i}", __('Choose an answer, or write your own.'));

                continue;
            }

            // what Claude Code itself sends: the label, or the labels comma separated
            $answers[$question['question']] = implode(', ', $question['multiSelect'] ? $picked : [$picked[0]]);
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            // the questions show one at a time: open the first one still without an answer
            $missing = (int) Str::after((string) array_key_first($this->getErrorBag()->messages()), 'choices.');
            $this->dispatch('question-step', step: $missing);

            return;
        }

        try {
            $decide->handle($request, auth()->user(), true, null, $answers);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        }

        $this->reset('choices', 'others');
        $this->reload();
    }

    /** No answer: Claude goes on with its own judgement instead of waiting. */
    public function skip(string $permissionId, DecidePermission $decide): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->where('uuid', $permissionId)->firstOrFail();

        try {
            $decide->handle($request, auth()->user(), false, 'The person skipped these questions: go on with your own judgement and say what you chose.');
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        }

        $this->reset('choices', 'others');
        $this->reload();
    }

    /** Commit what the sandbox holds and merge its branch into the branch the workspace is on. */
    public function mergeSandbox(Sandboxes $sandboxes): void
    {
        $this->sandboxAction(function () use ($sandboxes): void {
            $state = $sandboxes->merge($this->conversation);
            Flux::toast(variant: 'success', text: __('Merged :branch into :into in your workspace.', ['branch' => $this->conversation->sandbox['branch'], 'into' => $state['branch'] ?? __('your branch')]));
            $this->dispatch('chat-updated');
        });
    }

    /** Commit what the sandbox holds and push its branch, for a pull request on the git host. */
    public function pushSandbox(Sandboxes $sandboxes): void
    {
        $this->sandboxAction(function () use ($sandboxes): void {
            $sandboxes->push($this->conversation);
            Flux::toast(variant: 'success', text: __('Pushed :branch: open a pull request from it on the git host.', ['branch' => $this->conversation->sandbox['branch']]));
        });
    }

    /** Remove the sandbox: the conversation stays to read; its branch stays unless it is discarded. */
    public function removeSandbox(Sandboxes $sandboxes, bool $deleteBranch = false): void
    {
        $this->authorize('update', $this->conversation);

        if ($this->conversation->isBusy()) {
            Flux::toast(variant: 'warning', text: __('Claude is still working in the sandbox: wait for it, or interrupt it.'));

            return;
        }

        $sandboxes->discard($this->conversation, $deleteBranch);
        Flux::modal('remove-sandbox')->close();
        $this->reload();
    }

    public function retrySandbox(Sandboxes $sandboxes): void
    {
        $this->authorize('update', $this->conversation);
        $sandboxes->retry($this->conversation);
        $this->reload();
    }

    private function sandboxAction(callable $action): void
    {
        $this->authorize('update', $this->conversation);

        try {
            $action();
        } catch (InvalidArgumentException|BridgeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->reload();
    }

    private function readSandbox(): void
    {
        $this->sandboxInfo = $this->conversation->sandboxReady() && ! $this->conversation->isBusy()
            ? app(Sandboxes::class)->state($this->conversation)
            : null;
    }

    public function interrupt(): void
    {
        $this->authorize('update', $this->conversation);

        try {
            $snapshot = (new BridgeClient($this->conversation->workspace))->interrupt($this->conversation);
            Flux::toast(text: __('Asked Claude to stop.'));

            // Nothing was running on the bridge: no event will come to free the conversation, so do it here
            // (a turn Claude started on its own and Studio never saw end, for one).
            if (($snapshot['state'] ?? null) === 'idle' && $this->conversation->isBusy() && ! $this->conversation->pendingPermission()) {
                $this->conversation->setStatus(ConversationStatus::Idle);
                ConversationUpdated::dispatch($this->conversation->uuid, 'status', ['status' => ConversationStatus::Idle->value]);
            }
        } catch (BridgeException $e) {
            // The bridge is gone, so no turn is running anywhere: let the person write again.
            $this->conversation->messages()->create([
                'role' => Message::ROLE_SYSTEM,
                'kind' => Message::KIND_NOTICE,
                'content' => $e->getMessage(),
                'payload' => ['level' => 'error'],
            ]);
            $this->conversation->setStatus(ConversationStatus::Failed);

            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->reload();
    }

    /**
     * What the chat draws, in order: one row per message, except that the work of a subagent goes
     * inside its card (see children) and subagents started together sit side by side.
     *
     * @return list<array{agents: bool, messages: non-empty-list<Message>}>
     */
    #[Computed]
    public function rows(): array
    {
        $rows = [];
        $agents = [];

        foreach ($this->messages() as $message) {
            if (filled($message->payload['parent_tool_use_id'] ?? null) || $message->kind === Message::KIND_TOOL_RESULT) {
                continue;
            }

            if ($message->isAgentCall()) {
                $agents[] = $message;

                continue;
            }

            if ($agents !== []) {
                $rows[] = ['agents' => true, 'messages' => $agents];
                $agents = [];
            }

            $rows[] = ['agents' => false, 'messages' => [$message]];
        }

        if ($agents !== []) {
            $rows[] = ['agents' => true, 'messages' => $agents];
        }

        return $rows;
    }

    /**
     * The messages of each subagent, by the id of the call that started it.
     *
     * @return Collection<array-key, Collection<int, Message>>
     */
    #[Computed]
    public function children(): Collection
    {
        return $this->messages()
            ->filter(fn (Message $message) => filled($message->payload['parent_tool_use_id'] ?? null) && $message->kind !== Message::KIND_TOOL_RESULT)
            ->groupBy(fn (Message $message) => (string) $message->payload['parent_tool_use_id']);
    }

    /** @return Collection<int, Message> */
    #[Computed]
    public function messages(): Collection
    {
        $hidden = $this->showTools ? ['raw'] : ['raw', Message::KIND_THINKING];

        return $this->conversation->messages()
            ->whereNotIn('kind', $hidden)
            ->orderBy('id')
            ->get();
    }

    /**
     * Tool results by the id of the call that produced them, to fold under the call.
     *
     * @return Collection<array-key, Message>
     */
    #[Computed]
    public function toolResults(): Collection
    {
        return $this->messages()->where('kind', Message::KIND_TOOL_RESULT)->keyBy('tool_use_id');
    }

    #[Computed]
    public function pending(): ?PermissionRequest
    {
        return $this->conversation->pendingPermission();
    }

    private function decide(string $permissionId, bool $allow, DecidePermission $decide, ?string $always = null): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->where('uuid', $permissionId)->firstOrFail();

        try {
            $decide->handle($request, auth()->user(), $allow, always: $always);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        }

        $this->reload();
    }

    private function reload(): void
    {
        $wasBusy = $this->conversation->isBusy();
        $this->conversation->refresh();
        unset($this->messages, $this->toolResults, $this->pending, $this->rows, $this->children);
        $this->dispatch('chat-updated');

        if ($this->signal !== $this->conversation->signal()) {
            $this->signal = $this->conversation->signal();
            $this->dispatch('conversation-signal');
        }

        if ($this->sandboxStatus !== $this->conversation->sandboxStatus()) {
            $this->sandboxStatus = $this->conversation->sandboxStatus();
            $this->dispatch('sandbox-updated');
            $this->readSandbox();
        } elseif ($this->conversation->sandboxReady() && ($wasBusy || $this->sandboxInfo === null) && ! $this->conversation->isBusy()) {
            // a turn just ended: what it changed in the sandbox
            $this->readSandbox();
        }
    }

    public function render(): View
    {
        $owner = $this->conversation->user;
        $project = $this->conversation->workspace->project;
        $pending = $this->pending();

        return view('livewire.studio.chat', [
            'canChat' => auth()->user()->can('chat', $this->conversation->workspace->project) && $this->conversation->user_id === auth()->id(),
            'models' => (array) config('studio.models', []),
            'efforts' => (array) config('studio.efforts', []),
            'modes' => array_intersect_key((array) config('studio.modes', []), array_flip($this->allowedModes())),
            'mcpNames' => array_keys((new BridgeClient($this->conversation->workspace))->mcpServers($this->conversation) ?? []),
            'browser' => $this->conversation->workspace->project->agentSettings()['browser'],
            // "Always allow" on the card, and what runs without asking, in the settings
            'alwaysRule' => $pending !== null ? AllowRules::suggest($pending) : null,
            'roleRules' => AllowRules::forRole($owner, $project),
            'ownRules' => PermissionRule::query()->where('user_id', $owner->id)->where('project_id', $project->id)->orderBy('id')->get(),
        ]);
    }
}
