<?php

namespace App\Models;

use App\Enums\WorkspaceStatus;
use App\Jobs\DestroyWorkspace;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $driver
 * @property WorkspaceStatus $status
 * @property string|null $app_url
 * @property string|null $bridge_url
 * @property string|null $bridge_token
 * @property string|null $callback_token
 * @property string|null $callback_token_hash
 * @property string|null $path
 * @property string|null $branch
 * @property Carbon|null $last_seen_at
 * @property string|null $last_error
 * @property-read Project $project
 * @property-read User $user
 */
#[Fillable([
    'project_id', 'user_id', 'driver', 'status', 'app_url', 'bridge_url', 'bridge_token',
    'callback_token', 'callback_token_hash', 'path', 'branch', 'last_seen_at', 'last_error',
])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * A workspace that exists on the server is removed from it when its row
     * goes: the job carries the two names, so it runs after the row, the
     * person or the project is gone. Cascades at the database level do not
     * fire this: whoever deletes a user or a project deletes its workspaces first.
     */
    protected static function booted(): void
    {
        static::deleting(function (Workspace $workspace): void {
            if ($workspace->isNative() && $workspace->path && $workspace->user->handle) {
                DestroyWorkspace::dispatch($workspace->user->handle, $workspace->project->slug);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkspaceStatus::class,
            'bridge_token' => 'encrypted',
            'callback_token' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isRunning(): bool
    {
        return $this->status === WorkspaceStatus::Running;
    }

    public function hasBridge(): bool
    {
        return filled($this->bridge_url) && filled($this->bridge_token);
    }

    public function isNative(): bool
    {
        return $this->driver === 'native';
    }

    /** The loopback port this workspace's bridge listens on (native driver). */
    public function bridgePort(): int
    {
        return (int) config('studio.native.bridge_port_base', 42000) + $this->id;
    }

    /** The loopback port Reverb listens on for this preview, when the project has it on: one per host, never the site's. */
    public function reverbPort(): int
    {
        return (int) config('studio.native.workspace_reverb_port_base', 20000) + $this->id;
    }

    /**
     * The project's server features as the helper reads them, with this host's own Reverb port.
     *
     * @return array{scheduler: string, queues: string, reverb: string, reverb_port: int, horizon: string, pulse: string}
     */
    public function serviceInput(): array
    {
        return [...$this->project->serviceInput(), 'reverb_port' => $this->reverbPort()];
    }

    /**
     * Mint the token the bridge will present when it calls Studio back. The
     * plain value is kept encrypted (it travels with every turn), its hash is
     * what the request is matched on.
     */
    public function issueCallbackToken(): string
    {
        $plain = Str::random(48);

        $this->forceFill([
            'callback_token' => $plain,
            'callback_token_hash' => hash('sha256', $plain),
        ])->save();

        return $plain;
    }

    public static function findByCallbackToken(string $plain): ?self
    {
        return static::query()->where('callback_token_hash', hash('sha256', $plain))->first();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => WorkspaceStatus::Failed, 'last_error' => Str::limit($error, 1000)])->save();
    }

    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }
}
