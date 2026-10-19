<?php

namespace App\Models;

use App\Enums\PermissionStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PermissionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property string $request_id
 * @property string|null $tool_use_id the tool call the request is about
 * @property string $tool_name
 * @property string|null $description
 * @property array<string, mixed>|null $input
 * @property list<string>|null $suggestions the rules Claude Code suggested to allow it from now on
 * @property PermissionStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property-read Conversation $conversation
 */
#[Fillable(['conversation_id', 'request_id', 'tool_use_id', 'tool_name', 'description', 'input', 'suggestions', 'status', 'decided_by', 'decided_at'])]
class PermissionRequest extends Model
{
    /** @use HasFactory<PermissionRequestFactory> */
    use HasFactory;

    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'suggestions' => 'array',
            'status' => PermissionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isPending(): bool
    {
        return $this->status === PermissionStatus::Pending;
    }

    /** Claude asking the person questions (AskUserQuestion): answered with choices, not allowed or denied. */
    public function isQuestion(): bool
    {
        return $this->tool_name === 'AskUserQuestion';
    }

    /**
     * The questions of an AskUserQuestion request, each with its options.
     *
     * @return list<array{question: string, header: string, multiSelect: bool, options: list<array{label: string, description: string}>}>
     */
    public function questions(): array
    {
        $questions = [];

        foreach ((array) ($this->input['questions'] ?? []) as $question) {
            if (! is_array($question) || ! is_string($question['question'] ?? null)) {
                continue;
            }

            $options = [];

            foreach ((array) ($question['options'] ?? []) as $option) {
                if (is_array($option) && is_string($option['label'] ?? null) && $option['label'] !== '') {
                    $options[] = ['label' => $option['label'], 'description' => is_string($option['description'] ?? null) ? $option['description'] : ''];
                }
            }

            $questions[] = [
                'question' => $question['question'],
                'header' => is_string($question['header'] ?? null) ? $question['header'] : '',
                'multiSelect' => ($question['multiSelect'] ?? false) === true,
                'options' => $options,
            ];
        }

        return $questions;
    }

    /** A one-line, human reading of what the tool is about to do. */
    public function summary(): string
    {
        $input = $this->input ?? [];

        return match ($this->tool_name) {
            'AskUserQuestion' => implode(' · ', array_column($this->questions(), 'question')),
            'Bash' => (string) ($input['command'] ?? $this->description ?? ''),
            'Edit', 'Write', 'MultiEdit', 'Read' => (string) ($input['file_path'] ?? $this->description ?? ''),
            default => (string) ($this->description ?? json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        };
    }
}
