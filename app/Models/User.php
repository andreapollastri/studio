<?php

namespace App\Models;

use App\Enums\ClaudeAuthMode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $handle
 * @property string|null $git_token
 * @property string|null $git_login
 * @property ClaudeAuthMode $claude_auth_mode
 * @property string|null $claude_token
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'handle', 'git_token', 'git_login', 'claude_auth_mode', 'claude_token', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'git_token', 'claude_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'dev',
        'claude_auth_mode' => 'none',
    ];

    /** Longest handle: `ws-<handle>` is a Linux user name, `<handle>-<slug>` a host label. */
    public const HANDLE_MAX = 24;

    /**
     * The person's workspaces go one by one, so each one is removed from the
     * server too (see Workspace::booted): the database cascade alone would
     * leave their clone, tokens and bridge behind.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->workspaces()->get()->each->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'git_token' => 'encrypted',
            'claude_token' => 'encrypted',
            'claude_auth_mode' => ClaudeAuthMode::class,
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withTimestamps();
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canChat(): bool
    {
        return $this->role->canChat();
    }

    public function canAccess(Project $project): bool
    {
        return $this->isAdmin() || $project->hasMember($this);
    }

    /** A token for the git provider was saved and verified: the person can clone, push and open pull requests as themselves. */
    public function hasGit(): bool
    {
        return filled($this->git_token) && filled($this->handle);
    }

    /** A Claude credential was saved: the person's workspace can run Claude Code. */
    public function hasClaude(): bool
    {
        return $this->claude_auth_mode !== ClaudeAuthMode::None && filled($this->claude_token);
    }

    /**
     * Every project this person may open: all of them for an administrator,
     * otherwise the ones they are a member of.
     *
     * @return Builder<Project>
     */
    public function accessibleProjects(): Builder
    {
        return $this->isAdmin()
            ? Project::query()
            : Project::query()->whereHas('members', fn (Builder $q) => $q->whereKey($this->id));
    }

    /**
     * A new password handed over out of band, by an administrator or by root:
     * whoever held the old one (or the lost 2FA device) is out. Every session
     * is closed, the remember-me cookie dies with its token, 2FA is removed so
     * the person can log in again and set it up anew. Git and Claude
     * credentials stay: they are the person's, not the lock.
     */
    public function resetAccess(string $password): void
    {
        $this->forceFill([
            'password' => $password,
            'email_verified_at' => $this->email_verified_at ?? now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $sessions = (string) config('session.table', 'sessions');

        if (Schema::hasTable($sessions)) {
            DB::table($sessions)->where('user_id', $this->id)->delete();
        }
    }

    /** A login on the git provider as a hostname label and a Linux user name: lower-case, letters, digits and dashes. */
    public static function handleFromLogin(string $login): string
    {
        // Azure DevOps names people by their e-mail address: the part before @ is the name.
        $login = str_contains($login, '@') ? Str::before($login, '@') : $login;
        $handle = trim(preg_replace('/[^a-z0-9-]+/', '-', Str::lower($login)) ?? '', '-');

        return rtrim(Str::limit($handle !== '' ? $handle : 'user', self::HANDLE_MAX, ''), '-');
    }
}
