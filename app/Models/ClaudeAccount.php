<?php

namespace App\Models;

use App\Enums\ClaudeAuthMode;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ClaudeAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One of a person's Claude accounts. An API key is stored encrypted and goes
 * with every turn that uses it. A subscription is a sign-in to the unmodified
 * Claude Code in a folder of the person's home on the server (`dir`: .claude
 * for the first, .claude-<slug> for the others), through Anthropic's own flow:
 * Studio never sees it, it only asks the CLI whether it is signed in.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property ClaudeAuthMode $kind
 * @property string|null $token
 * @property string|null $dir
 * @property bool $signed_in
 * @property Carbon|null $checked_at
 * @property array{status?: string|null, percent?: int|null, resets_at?: string|null, week_percent?: int|null, week_resets_at?: string|null, plan?: string|null, at?: string}|null $usage the CLI's last word on the subscription's limits: the five-hour window, the week
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'kind', 'token', 'dir', 'signed_in', 'checked_at', 'usage'])]
#[Hidden(['token'])]
class ClaudeAccount extends Model
{
    /** @use HasFactory<ClaudeAccountFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** The folder of the first subscription, where Claude Code signs in by default. */
    public const DEFAULT_DIR = '.claude';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ClaudeAuthMode::class,
            'token' => 'encrypted',
            'signed_in' => 'boolean',
            'checked_at' => 'datetime',
            'usage' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSubscription(): bool
    {
        return $this->kind === ClaudeAuthMode::Subscription;
    }

    /** Ready to run Claude Code: a key saved, or a subscription signed in as last checked. */
    public function isUsable(): bool
    {
        return $this->isSubscription() ? $this->signed_in : filled($this->token);
    }

    /** A free folder for a new subscription of this person: .claude while none uses it, then .claude-<slug>. */
    public static function freeDir(User $user): string
    {
        $taken = $user->claudeAccounts()->pluck('dir')->filter()->all();

        if (! in_array(self::DEFAULT_DIR, $taken, true)) {
            return self::DEFAULT_DIR;
        }

        do {
            $dir = self::DEFAULT_DIR.'-'.Str::lower(Str::random(10));
        } while (in_array($dir, $taken, true));

        return $dir;
    }

    /**
     * What the bridge needs to run a turn on this account: how it signs in, which account (a
     * change restarts the CLI on the same session), the key, or the folder of the sign-in.
     *
     * @return array{auth: string, account: string, api_key: string|null, claude_dir: string|null}
     */
    public function forTurn(): array
    {
        return [
            'auth' => $this->kind->value,
            'account' => $this->uuid,
            'api_key' => $this->isSubscription() ? null : $this->token,
            'claude_dir' => $this->isSubscription() ? ($this->dir ?? self::DEFAULT_DIR) : null,
        ];
    }

    /** The name with what it is, for pickers: "Work · subscription". */
    public function label(): string
    {
        return $this->name.' · '.($this->isSubscription() ? __('subscription') : __('API key'));
    }
}
