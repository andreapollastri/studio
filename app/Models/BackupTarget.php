<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where backups come from and go to: an S3 bucket (AWS, a Johnny bucket, any S3-compatible
 * storage) or a folder on a host over SSH, reached with one of the system keys administrators
 * manage in System (the keys they share with projects). The bucket's keys and the host key
 * accepted on the first check are stored encrypted and never go back to the page.
 *
 * S3 config: provider, endpoint, region, bucket, access_key_id, secret_access_key, path_style.
 * SSH config: host, port, username, ssh_key_id, known_hosts, fingerprint.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $project_id
 * @property string $name
 * @property string $kind s3 or ssh
 * @property array<string, mixed> $config
 * @property string|null $status
 * @property Carbon|null $checked_at
 * @property int|null $user_id
 * @property-read Project|null $project
 */
#[Fillable(['project_id', 'name', 'kind', 'config', 'status', 'checked_at', 'user_id'])]
class BackupTarget extends Model
{
    use HasPublicUuid;

    public const KINDS = ['s3', 'ssh'];

    /** S3 flavours, for the form's defaults and the list. */
    public const PROVIDERS = ['aws' => 'Amazon S3', 'johnny' => 'Johnny', 'other' => 'S3-compatible'];

    protected $hidden = ['config'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<BackupJob, $this> */
    public function sourceOf(): HasMany
    {
        return $this->hasMany(BackupJob::class, 'source_id');
    }

    /** @return HasMany<BackupJob, $this> */
    public function destinationOf(): HasMany
    {
        return $this->hasMany(BackupJob::class, 'destination_id');
    }

    public function isS3(): bool
    {
        return $this->kind === 's3';
    }

    public function isSsh(): bool
    {
        return $this->kind === 'ssh';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    public function sshKey(): ?SshKey
    {
        $id = $this->setting('ssh_key_id');

        return $id ? SshKey::query()->system()->whereKey($id)->first() : null;
    }

    /** What the lists show: the bucket at its endpoint, or user@host:port. */
    public function address(): string
    {
        if ($this->isS3()) {
            $endpoint = (string) $this->setting('endpoint', '');
            $host = $endpoint !== '' ? (string) (parse_url($endpoint, PHP_URL_HOST) ?: $endpoint) : 's3.'.$this->setting('region', 'us-east-1').'.amazonaws.com';

            return 's3://'.$this->setting('bucket').' · '.$host;
        }

        $port = (int) $this->setting('port', 22);

        return $this->setting('username').'@'.$this->setting('host').($port !== 22 ? ':'.$port : '');
    }

    public function isHealthy(): bool
    {
        return $this->status === 'ok' || str_starts_with((string) $this->status, 'ok ');
    }

    public function inUse(): bool
    {
        return $this->sourceOf()->exists() || $this->destinationOf()->exists();
    }
}
