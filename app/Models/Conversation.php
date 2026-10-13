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
 * @property array{status: string, name: string, branch: string, url?: string|null, path?: string|null, error?: string|null, merged_at?: string|null, pushed_at?: string|null}|null $sandbox
 * @property string|null $sandbox_host
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'title', 'agent_session_id', 'status', 'permission_mode', 'model', 'effort', 'sandbox', 'sandbox_host', 'runtime', 'last_result', 'last_activity_at'])]
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
            'sandbox' => 'array',
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

    public const SANDBOX_CREATING = 'creating';

    public const SANDBOX_READY = 'ready';

    public const SANDBOX_FAILED = 'failed';

    public const SANDBOX_REMOVING = 'removing';

    public const SANDBOX_REMOVED = 'removed';

    /** Started in a sandbox of its own: a worktree on its own branch, with its own database and preview. */
    public function isSandboxed(): bool
    {
        return $this->sandbox !== null;
    }

    /** The sandbox's name on the bridge and the server: c<id>. */
    public function sandboxName(): string
    {
        return 'c'.$this->id;
    }

    public function sandboxStatus(): ?string
    {
        return $this->sandbox['status'] ?? null;
    }

    public function sandboxReady(): bool
    {
        return $this->sandboxStatus() === self::SANDBOX_READY;
    }

    /** A sandbox still taking room on the server: it counts against the person's limit. */
    public function sandboxAlive(): bool
    {
        return in_array($this->sandboxStatus(), [self::SANDBOX_CREATING, self::SANDBOX_READY, self::SANDBOX_FAILED, self::SANDBOX_REMOVING], true);
    }

    /**
     * Whether the person can write here: always in the workspace; in a sandbox only once it is ready.
     * A removed sandbox took the Claude session's directory with it: that conversation is history.
     */
    public function acceptsMessages(): bool
    {
        return ! $this->isSandboxed() || $this->sandboxReady();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateSandbox(array $changes): void
    {
        $this->forceFill(['sandbox' => array_merge($this->sandbox ?? [], $changes)])->save();
    }

    /** The app this conversation works on: its sandbox's preview, or the workspace's. */
    public function previewUrl(): ?string
    {
        if ($this->isSandboxed()) {
            return $this->sandboxReady() ? ($this->sandbox['url'] ?? null) : null;
        }

        return $this->workspace->isRunning() ? $this->workspace->app_url : null;
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
