<?php

namespace App\Models;

use Database\Factories\ProjectMcpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A remote (HTTP) MCP server that the chats of a project get. Its tools show
 * up in Claude Code as mcp__<name>__<tool>. The header, usually a bearer
 * token, is stored encrypted and only travels to the workspace bridge.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $url
 * @property array<string, string>|null $headers
 * @property list<string> $roles
 * @property bool $enabled
 * @property string|null $status
 * @property Carbon|null $checked_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'name', 'url', 'headers', 'roles', 'enabled', 'status', 'checked_at'])]
class ProjectMcpServer extends Model
{
    /** @use HasFactory<ProjectMcpServerFactory> */
    use HasFactory;

    /** Who gets a new server unless the administrator says otherwise: everyone but clients. */
    public const DEFAULT_ROLES = ['admin', 'pm', 'dev'];

    protected $hidden = ['headers'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'encrypted:array',
            'roles' => 'array',
            'enabled' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function allows(User $user): bool
    {
        return $this->enabled && in_array($user->role->value, $this->roles, true);
    }

    /**
     * The entry the bridge hands to Claude Code.
     *
     * @return array{type: string, url: string, headers: array<string, string>}
     */
    public function forBridge(): array
    {
        return ['type' => 'http', 'url' => $this->url, 'headers' => $this->headers ?? []];
    }

    /** Header names only, for the settings page: values are never shown again. */
    public function headerNames(): string
    {
        return implode(', ', array_keys($this->headers ?? []));
    }

    public function isHealthy(): bool
    {
        return $this->status === 'ok' || str_starts_with((string) $this->status, 'ok ');
    }
}
