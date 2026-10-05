<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $kind
 * @property string|null $content
 * @property array<string, mixed>|null $payload
 * @property string|null $agent_uuid
 * @property string|null $tool_use_id
 */
#[Fillable(['conversation_id', 'role', 'kind', 'content', 'payload', 'agent_uuid', 'tool_use_id'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_SYSTEM = 'system';

    public const KIND_TEXT = 'text';

    public const KIND_THINKING = 'thinking';

    public const KIND_TOOL_USE = 'tool_use';

    public const KIND_TOOL_RESULT = 'tool_result';

    public const KIND_RESULT = 'result';

    public const KIND_NOTICE = 'notice';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /** Rows the chat shows as prose; tool calls and results collapse into a single row each turn. */
    public function isProse(): bool
    {
        return in_array($this->kind, [self::KIND_TEXT, self::KIND_NOTICE], true);
    }
}
