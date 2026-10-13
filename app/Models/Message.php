<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /**
     * The name of a tool as the chat shows it: Claude Code's own as they are, an MCP tool as
     * "server · tool" (the agent's browser: "browser · take screenshot").
     */
    public function toolLabel(): string
    {
        $name = (string) $this->content;

        if (preg_match('/^mcp__([^_]+(?:_[^_]+)*?)__(.+)$/', $name, $m)) {
            $tool = $m[1] === 'browser' ? preg_replace('/^browser_/', '', $m[2]) : $m[2];

            return $m[1].' · '.str_replace('_', ' ', (string) $tool);
        }

        return $name;
    }

    /** One line per tool call: the command, the file, the pattern, the page, or what a subagent was asked to do. */
    public function toolSummary(): string
    {
        $input = (array) ($this->payload['input'] ?? []);

        return (string) match (true) {
            $this->content === 'Bash' => $input['command'] ?? '',
            in_array($this->content, ['Read', 'Edit', 'Write', 'MultiEdit'], true) => $input['file_path'] ?? '',
            in_array($this->content, ['Grep', 'Glob'], true) => $input['pattern'] ?? '',
            $this->isAgentCall() => trim(($input['description'] ?? '').(isset($input['subagent_type']) ? ' · '.$input['subagent_type'] : '')),
            $this->content === 'AskUserQuestion' => implode(' · ', array_filter(array_column((array) ($input['questions'] ?? []), 'header'))),
            str_starts_with((string) $this->content, 'mcp__browser__') => $input['url'] ?? $input['element'] ?? $input['text'] ?? $input['filename'] ?? '',
            default => Str::limit(json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 120),
        };
    }

    /** A call that started a subagent: the chat draws it as the agent's card. */
    public function isAgentCall(): bool
    {
        return $this->kind === self::KIND_TOOL_USE && in_array($this->content, ['Task', 'Agent'], true);
    }

    /**
     * The images of a tool result that Studio kept (screenshots), with the address the chat shows them at.
     * The chat hands its conversation in, so drawing many results asks the database nothing.
     *
     * @return list<array{url: string, type: string, bytes: int}>
     */
    public function images(?Conversation $conversation = null): array
    {
        $images = [];
        $conversation ??= $this->conversation;

        foreach ((array) ($this->payload['images'] ?? []) as $image) {
            if (is_array($image) && is_string($image['file'] ?? null)) {
                $images[] = [
                    'url' => route('studio.conversation.image', ['project' => $conversation->workspace->project, 'conversation' => $conversation, 'file' => $image['file']]),
                    'type' => (string) ($image['type'] ?? ''),
                    'bytes' => (int) ($image['bytes'] ?? 0),
                ];
            }
        }

        return $images;
    }

    /** Rows the chat shows as prose; tool calls and results collapse into a single row each turn. */
    public function isProse(): bool
    {
        return in_array($this->kind, [self::KIND_TEXT, self::KIND_NOTICE], true);
    }
}
