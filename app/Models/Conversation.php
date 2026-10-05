<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'title', 'agent_session_id', 'status', 'permission_mode', 'model', 'effort', 'last_result', 'last_activity_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_result' => 'array',
            'last_activity_at' => 'datetime',
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
