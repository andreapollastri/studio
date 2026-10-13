<?php

namespace App\Models;

use App\Infrastructure\Connector;
use App\Infrastructure\Connectors;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ConnectorAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One account of an infrastructure connector: an AWS access key, a
 * DigitalOcean or Hetzner token, a Cloudflare API token, a Cipi server, a
 * Boogle or Hackly instance. The credentials are stored encrypted and never
 * go back to the page: secret fields are left blank to keep them.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connector
 * @property string $name
 * @property array<string, mixed> $credentials
 * @property string|null $status
 * @property Carbon|null $checked_at
 */
#[Fillable(['connector', 'name', 'credentials', 'status', 'checked_at'])]
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

    public function definition(): Connector
    {
        return Connectors::make($this->connector);
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
