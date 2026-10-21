<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A domain other than Studio's own, for projects: a site at `name` itself or, with the
 * wildcard, at `<label>.name`, and previews at `<person>-<key>.name`. Accepted once its DNS
 * points at this server (see DomainDns); Caddy gets certificates for it only while it does.
 * Online, its hosts open to anybody, without the Studio login (SiteAccess), as a public site does;
 * Studio's own address of the same site stays behind the login.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property bool $wildcard
 * @property DomainStatus $status
 * @property array{name?: list<string>, wildcard?: list<string>, expected?: list<string>}|null $records
 * @property string|null $last_error
 * @property Carbon|null $checked_at
 * @property Carbon|null $verified_at
 * @property int|null $user_id
 * @property bool $online
 */
#[Fillable(['name', 'wildcard', 'status', 'records', 'last_error', 'checked_at', 'verified_at', 'user_id', 'online'])]
class Domain extends Model
{
    use HasPublicUuid;

    /** A host name: lowercase labels of letters, digits and dashes, a TLD of letters. */
    public const PATTERN = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** One label, as a site's subdomain on a wildcard domain. */
    public const LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /** @var array<string, mixed> */
    protected $attributes = ['online' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wildcard' => 'boolean',
            'online' => 'boolean',
            'status' => DomainStatus::class,
            'records' => 'array',
            'checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Project, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Project::class, 'site_domain_id');
    }

    /** @return HasMany<Project, $this> */
    public function previewProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'preview_domain_id');
    }

    public function inUse(): bool
    {
        return $this->sites()->exists() || $this->previewProjects()->exists();
    }

    /**
     * The projects whose site or previews live here: what changes on the server when the domain goes online or not.
     *
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return $this->sites()->get()->merge($this->previewProjects()->get())->unique('id')->values();
    }

    public function isVerified(): bool
    {
        return $this->status === DomainStatus::Verified;
    }

    /** `name` itself points at this server: a site may live there. */
    public function namePointsHere(): bool
    {
        return $this->pointsHere('name');
    }

    /** `*.name` points at this server: sites at `<label>.name`, and previews. */
    public function wildcardPointsHere(): bool
    {
        return $this->wildcard && $this->pointsHere('wildcard');
    }

    private function pointsHere(string $which): bool
    {
        $seen = (array) ($this->records[$which] ?? []);
        $expected = (array) ($this->records['expected'] ?? []);

        return $seen !== [] && $expected !== [] && array_diff($seen, $expected) === [];
    }

    /** Is the host this domain's: `name`, or one label under it with the wildcard? */
    public function covers(string $host): bool
    {
        $host = strtolower($host);

        if ($host === $this->name) {
            return true;
        }

        return $this->wildcard && str_ends_with($host, '.'.$this->name)
            && preg_match(self::LABEL, substr($host, 0, -strlen('.'.$this->name))) === 1;
    }

    /** The domain a host belongs to: its name, or the wildcard one level above it. */
    public static function covering(string $host): ?self
    {
        $host = strtolower($host);
        $parent = str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : '';

        return static::query()->whereIn('name', array_filter([$host, $parent]))->get()
            ->first(fn (self $domain) => $domain->covers($host));
    }
}
