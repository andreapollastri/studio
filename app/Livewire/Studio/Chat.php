<?php

namespace App\Livewire\Studio;

use App\Actions\Studio\DecidePermission;
use App\Actions\Studio\StartTurn;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PermissionRequest;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Chat extends Component
{
    use AuthorizesRequests;

    public Conversation $conversation;

    public string $draft = '';

    /** Text still being typed by the agent, assembled from `text_delta` broadcasts and never stored. */
    public string $streaming = '';

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

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);
        $this->conversation = $conversation;
        $this->model = (string) $conversation->model;
        $this->effort = (string) $conversation->effort;
        $this->mode = $conversation->permission_mode;
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
        $modes = (array) config('studio.modes_by_role.'.auth()->user()->role->value, []);

        return array_values(array_unique([...$modes, $this->conversation->permission_mode]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[On('echo-private:conversations.{conversation.id},ConversationUpdated')]
    public function onUpdated(array $payload = []): void
    {
        $kind = (string) ($payload['kind'] ?? '');

        if ($kind === 'text_delta') {
            $this->streaming .= (string) ($payload['data']['text'] ?? '');

            return;
        }

        if ($kind === 'status') {
            return;
        }

        $this->streaming = '';
        $this->reload();
    }

    /** Fallback when no websocket is around: the view polls while the agent works. */
    public function poll(): void
    {
        $this->reload();
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
        $this->streaming = '';
        $this->reload();
    }

    public function allow(int $permissionId, DecidePermission $decide): void
    {
        $this->decide($permissionId, true, $decide);
    }

    public function deny(int $permissionId, DecidePermission $decide): void
    {
        $this->decide($permissionId, false, $decide);
    }

    /** Claude's questions answered: every question needs a choice or the person's own words. */
    public function answer(int $permissionId, DecidePermission $decide): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->findOrFail($permissionId);
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
    public function skip(int $permissionId, DecidePermission $decide): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->findOrFail($permissionId);

        try {
            $decide->handle($request, auth()->user(), false, 'The person skipped these questions: go on with your own judgement and say what you chose.');
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        }

        $this->reset('choices', 'others');
        $this->reload();
    }

    public function interrupt(): void
    {
        $this->authorize('update', $this->conversation);

        try {
            (new BridgeClient($this->conversation->workspace))->interrupt($this->conversation);
            Flux::toast(text: __('Asked Claude to stop.'));
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

    private function decide(int $permissionId, bool $allow, DecidePermission $decide): void
    {
        $this->authorize('update', $this->conversation);

        $request = $this->conversation->permissionRequests()->findOrFail($permissionId);

        try {
            $decide->handle($request, auth()->user(), $allow);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        }

        $this->reload();
    }

    private function reload(): void
    {
        $this->conversation->refresh();
        unset($this->messages, $this->toolResults, $this->pending);
        $this->dispatch('chat-updated');
    }

    public function render(): View
    {
        return view('livewire.studio.chat', [
            'canChat' => auth()->user()->canChat() && $this->conversation->user_id === auth()->id(),
            'models' => (array) config('studio.models', []),
            'efforts' => (array) config('studio.efforts', []),
            'modes' => array_intersect_key((array) config('studio.modes', []), array_flip($this->allowedModes())),
            'mcpNames' => array_keys((new BridgeClient($this->conversation->workspace))->mcpServers($this->conversation) ?? []),
        ]);
    }
}
