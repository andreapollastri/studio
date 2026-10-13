<?php

namespace App\Backups;

use App\Models\BackupTarget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What one backup command needs on Studio's disk for as long as it runs, in a folder only
 * Studio's user can read: the private key of each SSH end, the host keys accepted for it, an
 * empty rclone config. The bucket keys travel in the environment, never on a command line.
 * Gone when the command is.
 */
final class Workbench
{
    public readonly string $dir;

    /** @var array<string, string> */
    private array $env = [];

    public function __construct()
    {
        $this->dir = storage_path('app/private/backup-work/'.Str::random(24));
        File::ensureDirectoryExists($this->dir, 0700);
        @chmod($this->dir, 0700);
        $this->env['RCLONE_CONFIG'] = $this->dir.'/rclone.conf';
    }

    public function __destruct()
    {
        $this->clean();
    }

    public function clean(): void
    {
        if (is_dir($this->dir)) {
            File::deleteDirectory($this->dir);
        }
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return $this->env;
    }

    /**
     * An rclone remote named $name for the target, and the remote path of $path in it:
     * `src:bucket/prefix/path` or `src:path` (an SSH path without a slash is under the home).
     */
    public function remote(string $name, BackupTarget $target, string $path = ''): string
    {
        $prefix = 'RCLONE_CONFIG_'.strtoupper($name).'_';
        $path = trim($path);

        if ($target->isS3()) {
            $provider = (string) $target->setting('provider', 'other');
            $endpoint = trim((string) $target->setting('endpoint', ''));
            $this->env += [
                $prefix.'TYPE' => 's3',
                $prefix.'PROVIDER' => $provider === 'aws' ? 'AWS' : 'Other',
                $prefix.'ACCESS_KEY_ID' => (string) $target->setting('access_key_id', ''),
                $prefix.'SECRET_ACCESS_KEY' => (string) $target->setting('secret_access_key', ''),
                $prefix.'REGION' => (string) ($target->setting('region') ?: 'us-east-1'),
                $prefix.'FORCE_PATH_STYLE' => $target->setting('path_style', $provider !== 'aws') ? 'true' : 'false',
                // a key that may write to the bucket need not be allowed to create buckets
                $prefix.'NO_CHECK_BUCKET' => 'true',
                ...($endpoint !== '' ? [$prefix.'ENDPOINT' => $endpoint] : []),
            ];

            return $name.':'.trim((string) $target->setting('bucket'), '/').($path !== '' ? '/'.trim($path, '/') : '');
        }

        $this->env += [
            $prefix.'TYPE' => 'sftp',
            $prefix.'HOST' => (string) $target->setting('host'),
            $prefix.'PORT' => (string) (int) $target->setting('port', 22),
            $prefix.'USER' => (string) $target->setting('username'),
            $prefix.'KEY_FILE' => $this->keyFile($target),
            $prefix.'KNOWN_HOSTS_FILE' => $this->knownHosts($target),
            $prefix.'SHELL_TYPE' => 'unix',
        ];

        return $name.':'.($path === '' ? '' : rtrim($path, '/'));
    }

    /**
     * The ssh command line to run $command on the target, with its key and its pinned host key.
     *
     * @return list<string>
     */
    public function ssh(BackupTarget $target, string $command): array
    {
        return [
            (string) config('studio.backups.ssh', 'ssh'),
            '-i', $this->keyFile($target),
            '-p', (string) (int) $target->setting('port', 22),
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$this->knownHosts($target),
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=20',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=6',
            '-o', 'LogLevel=ERROR',
            $target->setting('username').'@'.$target->setting('host'),
            $command,
        ];
    }

    private function keyFile(BackupTarget $target): string
    {
        $file = $this->dir.'/key-'.$target->id;

        if (! is_file($file)) {
            $key = $target->sshKey() ?? throw new RuntimeException(__('The SSH key of :name is gone: pick another one in its settings.', ['name' => $target->name]));
            file_put_contents($file, rtrim((string) $key->private_key)."\n");
            chmod($file, 0600);
        }

        return $file;
    }

    private function knownHosts(BackupTarget $target): string
    {
        $file = $this->dir.'/known-hosts-'.$target->id;

        if (! is_file($file)) {
            $lines = array_values(array_filter((array) $target->setting('known_hosts', [])));

            if ($lines === []) {
                throw new RuntimeException(__('No host key accepted for :name yet: check it once from its settings.', ['name' => $target->name]));
            }

            $host = (string) $target->setting('host');
            $port = (int) $target->setting('port', 22);
            $name = $port === 22 ? $host : '['.$host.']:'.$port;
            file_put_contents($file, implode('', array_map(fn (string $line) => $name.' '.trim($line)."\n", $lines)));
            chmod($file, 0600);
        }

        return $file;
    }
}
