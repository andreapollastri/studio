<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array{model?: string|null, auth?: string|null, started_at?: string}|null $runtime what Claude Code said it runs on, at the start of the last turn
 * @property string $uuid
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $agent_session_id
 * @property ConversationStatus $status
 * @property string $permission_mode
 * @property string|null $model
 * @property string|null $effort
 * @property array<string, mixed>|null $last_result
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $seen_at when the person last opened it
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'title', 'agent_session_id', 'status', 'permission_mode', 'model', 'effort', 'runtime', 'last_result', 'last_activity_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runtime' => 'array',
            'status' => ConversationStatus::class,
            'last_result' => 'array',
            'last_activity_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<PermissionRequest, $this> */
    public function permissionRequests(): HasMany
    {
        return $this->hasMany(PermissionRequest::class);
    }

    public function pendingPermission(): ?PermissionRequest
    {
        return $this->permissionRequests()->where('status', 'pending')->oldest()->first();
    }

    public function isBusy(): bool
    {
        return $this->status !== ConversationStatus::Idle && $this->status !== ConversationStatus::Failed;
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->forceFill(['status' => $status, 'last_activity_at' => now()])->save();
    }

    /**
     * What the dot next to it says: working, asking (a question or a permission
     * waits for the person), failed, done (a turn finished since the person last
     * opened it) or quiet.
     */
    public function signal(): string
    {
        return match ($this->status) {
            ConversationStatus::Running => 'working',
            ConversationStatus::WaitingPermission => 'asking',
            ConversationStatus::Failed => 'failed',
            ConversationStatus::Idle => $this->last_result !== null && $this->last_activity_at !== null
                && ($this->seen_at === null || $this->last_activity_at->gt($this->seen_at)) ? 'done' : 'quiet',
        };
    }

    public function signalLabel(): string
    {
        return match ($this->signal()) {
            'working' => __('working'),
            'asking' => __('waiting for you'),
            'failed' => __('failed'),
            'done' => __('finished, not opened yet'),
            default => __('nothing new'),
        };
    }

    /** Opened by the person: its green dot goes grey. Not an update, so the list keeps its order. */
    public function markSeen(): void
    {
        static::withoutTimestamps(fn () => $this->forceFill(['seen_at' => now()])->save());
    }

    /** The label of the permission mode as the chat shows it. */
    public function modeLabel(): string
    {
        return (string) (config("studio.modes.{$this->permission_mode}.label") ?? $this->permission_mode);
    }

    public function displayTitle(): string
    {
        return $this->title ?: __('New conversation');
    }
}
