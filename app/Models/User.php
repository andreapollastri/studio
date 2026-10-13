<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Infrastructure\Git\Forges;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string $uuid
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $handle
 * @property int|null $claude_account_id the person's default Claude account
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'handle', 'claude_account_id', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    use HasPublicUuid;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'dev',
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

    /** @return HasMany<SshConnection, $this> */
    public function sshConnections(): HasMany
    {
        return $this->hasMany(SshConnection::class);
    }

    /** @return HasMany<SshKey, $this> */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /** @return HasMany<VaultFolder, $this> */
    public function vaultFolders(): HasMany
    {
        return $this->hasMany(VaultFolder::class);
    }

    /** @return HasMany<VaultItem, $this> */
    public function vaultItems(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }

    /** @return HasMany<DriveFolder, $this> */
    public function driveFolders(): HasMany
    {
        return $this->hasMany(DriveFolder::class);
    }

    /** @return HasMany<ClaudeAccount, $this> */
    public function claudeAccounts(): HasMany
    {
        return $this->hasMany(ClaudeAccount::class);
    }

    /** @return BelongsTo<ClaudeAccount, $this> */
    public function defaultClaudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class, 'claude_account_id');
    }

    /**
     * The person's accounts on the git hosts (personal GitHub, work Azure DevOps, a client's GitLab…).
     *
     * @return HasMany<ConnectorAccount, $this>
     */
    public function gitAccounts(): HasMany
    {
        return $this->hasMany(ConnectorAccount::class)->whereIn('connector', array_keys(Forges::ALL));
    }

    /** @return HasMany<ProjectGroup, $this> */
    public function projectGroups(): HasMany
    {
        return $this->hasMany(ProjectGroup::class)->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<ProjectPreference, $this> */
    public function projectPreferences(): HasMany
    {
        return $this->hasMany(ProjectPreference::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canChat(): bool
    {
        return $this->role->canChat();
    }

    public function canUseSsh(): bool
    {
        return $this->role->canUseSsh();
    }

    public function canAccess(Project $project): bool
    {
        return $this->isAdmin() || $project->hasMember($this);
    }

    /** At least one account on a git host: the person can clone, push and open pull requests as themselves. */
    public function hasGit(): bool
    {
        return $this->gitAccounts()->exists();
    }

    /** A Claude account ready to run Claude Code: a key, or a subscription signed in. */
    public function hasClaude(): bool
    {
        return $this->claudeAccounts()->get()->contains(fn (ClaudeAccount $account) => $account->isUsable());
    }

    /**
     * The person's handle, given on first need: their home on the server (ws-<handle>) and the
     * first half of their preview hosts. From the login of their first git account, else from
     * their e-mail address; it never changes afterwards, whatever accounts come and go.
     */
    public function ensureHandle(): ?string
    {
        if (filled($this->handle)) {
            return $this->handle;
        }

        $login = (string) ($this->gitAccounts()->orderBy('id')->value('login') ?: Str::before($this->email, '@'));
        $handle = $this->freeHandle(self::handleFromLogin($login));

        if ($handle !== null) {
            $this->forceFill(['handle' => $handle])->save();
        }

        return $handle;
    }

    /**
     * The handle becomes a Linux user and the first half of the preview hosts, so it has
     * to be unique and must not land on an existing project's host: the login as it is,
     * or with a short counter.
     */
    private function freeHandle(string $base): ?string
    {
        foreach (range(1, 9) as $attempt) {
            $handle = $attempt === 1 ? $base : rtrim(substr($base, 0, self::HANDLE_MAX - 2), '-').'-'.$attempt;

            $taken = self::query()->whereNot('id', $this->id)->where('handle', $handle)->exists()
                || Project::siteHostShadowedByHandle($handle) !== null;

            if (! $taken) {
                return $handle;
            }
        }

        return null;
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
