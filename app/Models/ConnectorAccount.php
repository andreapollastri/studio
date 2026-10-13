<?php

namespace App\Models;

use App\Infrastructure\Connector;
use App\Infrastructure\Connectors;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ConnectorAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One account of a connector. Shared, for the administrators (no user): an
 * AWS access key, a DigitalOcean or Hetzner token, a Cloudflare API token, a
 * Cipi server, a Boogle or Hackly instance. Personal (a user's): a token on
 * a git host, with the identity its commits carry. The credentials are
 * stored encrypted and never go back to the page: secret fields are left
 * blank to keep them.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $connector
 * @property string $name
 * @property string|null $login
 * @property string|null $host
 * @property string|null $git_name
 * @property string|null $git_email
 * @property array<string, mixed> $credentials
 * @property string|null $status
 * @property Carbon|null $checked_at
 */
#[Fillable(['user_id', 'connector', 'name', 'login', 'host', 'git_name', 'git_email', 'credentials', 'status', 'checked_at'])]
class ConnectorAccount extends Model
{
    /** @use HasFactory<ConnectorAccountFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (ConnectorAccount $account) => $account->forget());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function definition(): Connector
    {
        return $this->isGit() ? Forges::make($this->connector) : Connectors::make($this->connector);
    }

    public function isGit(): bool
    {
        return isset(Forges::ALL[$this->connector]);
    }

    /**
     * A person's accounts on the git hosts.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function gitFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->whereIn('connector', array_keys(Forges::ALL));
    }

    /**
     * What git needs to work as this account: the token and the user name it
     * goes with over HTTPS, the host its repositories live on, and the name and
     * email its commits carry.
     *
     * @return array{provider: string, token: string, username: string, login: string, name: string, email: string, host: string}
     */
    public function gitCredentials(): array
    {
        /** @var Forge $forge */
        $forge = $this->definition();
        $login = (string) $this->login;

        return [
            'provider' => $this->connector,
            'token' => (string) $this->credential('token', ''),
            'username' => $forge->gitUsername($login),
            'login' => $login,
            'name' => (string) ($this->git_name ?: $this->user?->name ?: $login),
            'email' => (string) ($this->git_email ?: $this->user?->email),
            'host' => (string) ($this->host ?: $forge->host($this->credentials ?? [])),
        ];
    }

    /** Whether a repository remote (HTTPS or SSH) lives where this git account works. */
    public function matchesRemote(string $url): bool
    {
        return $this->isGit() && Forge::remoteOn($this->gitCredentials()['host'], $url);
    }

    /** The API client of the connector, signed in with this account. */
    public function client(): object
    {
        return $this->definition()->client($this->credentials ?? []);
    }

    public function credential(string $name, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$name] ?? $default;
    }

    public function isHealthy(): bool
    {
        return $this->status === 'ok' || str_starts_with((string) $this->status, 'ok ');
    }

    /**
     * What the pages read from this account is cached for a little while
     * under a version number: a change made from Studio bumps it.
     */
    public function cacheKey(string ...$parts): string
    {
        return 'infrastructure:'.$this->uuid.':'.Cache::get($this->versionKey(), 0).':'.md5(implode('|', $parts));
    }

    public function forget(): void
    {
        Cache::forever($this->versionKey(), (int) Cache::get($this->versionKey(), 0) + 1);
    }

    private function versionKey(): string
    {
        return 'infrastructure:'.$this->uuid.':version';
    }
}
