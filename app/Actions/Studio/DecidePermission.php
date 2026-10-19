<?php

namespace App\Actions\Studio;

use App\Enums\ConversationStatus;
use App\Enums\PermissionStatus;
use App\Events\ConversationUpdated;
use App\Jobs\AnswerPermission;
use App\Models\PermissionRequest;
use App\Models\PermissionRule;
use App\Models\User;
use InvalidArgumentException;

final class DecidePermission
{
    /**
     * @param  array<string, string>|null  $answers  the person's answers to Claude's questions (AskUserQuestion)
     * @param  string|null  $always  "Always allow": the rule kept for the conversation's owner on its project, from now on
     */
    public function handle(PermissionRequest $request, User $by, bool $allow, ?string $message = null, ?array $answers = null, ?string $always = null): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException(__('This request has already been decided.'));
        }

        $request->forceFill([
            'status' => $allow ? PermissionStatus::Allowed : PermissionStatus::Denied,
            'decided_by' => $by->id,
            'decided_at' => now(),
            ...($answers ? ['input' => [...($request->input ?? []), 'answers' => $answers]] : []),
        ])->save();

        $conversation = $request->conversation;

        if ($allow && $always !== null) {
            PermissionRule::query()->firstOrCreate(['user_id' => $conversation->user_id, 'project_id' => $conversation->workspace->project_id, 'rule' => $always]);
        }

        if (! $conversation->pendingPermission()) {
            $conversation->setStatus(ConversationStatus::Running);
        }

        AnswerPermission::dispatch($request, $allow, $message, $answers, $allow && $always !== null ? [$always] : null);

        ConversationUpdated::dispatch($conversation->uuid, 'messages', ['kinds' => ['permission'], 'status' => $conversation->status->value]);
    }
}
