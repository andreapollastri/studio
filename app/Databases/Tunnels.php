<?php

namespace App\Databases;

use App\Backups\Engine;
use App\Models\SshConnection;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * SSH tunnels to databases, with OpenSSH: one master connection per SSH connection and
 * credential, kept open while it is used (ControlPersist), and on it a forward for each
 * database reached through it. The forward ends in a Unix socket in a folder only Studio's
 * user may enter, never in a port of 127.0.0.1 that any process of this server (a
 * workspace) could connect to. The master also runs commands on the host: SQLite's shell.
 *
 * Only a host whose key was accepted is reached: the keys it shows are matched against the
 * fingerprint pinned on the SSH connection and only the matching one is trusted.
 */
class Tunnels
{
    /**
     * The local socket that leads to $host:$port as seen from the SSH host. PostgreSQL wants
     * a folder with .s.PGSQL.<port> in it: $postgres gives the folder instead of the socket.
     */
    public function forward(SshConnection $ssh, User $as, string $host, int $port, bool $postgres = false): string
    {
        $dir = $this->master($ssh, $as);
        $forward = $dir.'/f'.substr(hash('sha256', $host.':'.$port), 0, 10);
        $socket = $postgres ? $forward.'/.s.PGSQL.'.$port : $forward.'/s';

        if (file_exists($socket)) {
            return $postgres ? $forward : $socket;
        }

        File::ensureDirectoryExists($forward, 0700);
        $target = (str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
        $result = Process::timeout(20)->run([...$this->client($ssh, $dir), '-O', 'forward', '-L', $socket.':'.$target, $this->destination($ssh)]);

        if (! $result->successful() || ! $this->waitFor($socket)) {
            throw new DatabaseException(__('The tunnel through :name could not reach :target: :reason', [
                'name' => $ssh->name, 'target' => $target, 'reason' => trim($result->errorOutput()) ?: __('no socket appeared'),
            ]));
        }

        return $postgres ? $forward : $socket;
    }

    /**
     * Run a command on the SSH host through the master, $input (text or an open file) on its
     * standard input, its standard output into $into when given (a dump of any size): the
     * result as it is, failed or not.
     *
     * @param  list<string>  $command
     * @param  string|resource|null  $input
     */
    public function exec(SshConnection $ssh, User $as, array $command, mixed $input = null, int $timeout = 120, ?string $into = null): ProcessResult
    {
        $dir = $this->master($ssh, $as);
        $line = implode(' ', array_map('escapeshellarg', $command));
        $ssh = [...$this->client($ssh, $dir), $this->destination($ssh), '--', $line];

        return Process::timeout($timeout)->input($input)
            ->env($into !== null ? ['STUDIO_EXEC_OUT' => $into] : [])
            ->run($into !== null ? ['sh', '-c', '"$@" > "$STUDIO_EXEC_OUT"', 'sh', ...$ssh] : $ssh);
    }

    /** Close the master of this SSH connection and credential, and every forward on it. */
    public function close(SshConnection $ssh, User $as): void
    {
        $dir = $this->dir($ssh, $as);

        if (file_exists($dir.'/c')) {
            Process::timeout(10)->run([...$this->client($ssh, $dir), '-O', 'exit', $this->destination($ssh)]);
        }

        File::deleteDirectory($dir);
    }

    /**
     * The fingerprints of the keys a host shows, to accept one before the first tunnel.
     *
     * @return list<string>
     */
    public function scan(SshConnection $ssh): array
    {
        return array_values(array_unique(array_map(Engine::fingerprint(...), app(Engine::class)->hostKeys($ssh->host, $ssh->port))));
    }

    /** The folder of a running master, started when there is none. */
    protected function master(SshConnection $ssh, User $as): string
    {
        if (blank($ssh->host_key)) {
            throw new DatabaseException(__('The host key of :name is not accepted yet: test the connection to see it and accept it, or open :name once in the SSH terminal.', ['name' => $ssh->name]));
        }

        // a connection not saved yet is one a pasted link brought, being tested: the person's own
        if (($ssh->exists && ! $ssh->isVisibleTo($as)) || ! $ssh->canConnect($as)) {
            throw new DatabaseException($ssh->isOwnedBy($as) || $as->is(auth()->user())
                ? __('There is no usable key or password for :name: set yours on the SSH page.', ['name' => $ssh->name])
                : __('The SSH connection :name of the tunnel is no longer usable by :owner.', ['name' => $ssh->name, 'owner' => $as->name]));
        }

        $dir = $this->dir($ssh, $as);

        if ($this->alive($ssh, $dir)) {
            return $dir;
        }

        return Cache::lock('db-tunnel:'.basename($dir), 40)->block(30, function () use ($ssh, $as, $dir) {
            if ($this->alive($ssh, $dir)) {
                return $dir;
            }

            $this->start($ssh, $as, $dir);

            return $dir;
        });
    }

    private function start(SshConnection $ssh, User $as, string $dir): void
    {
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);

        $target = $ssh->forGateway($as);
        $known = array_values(array_filter(app(Engine::class)->hostKeys($ssh->host, $ssh->port), fn (string $key) => hash_equals((string) $ssh->host_key, Engine::fingerprint($key))));

        if ($known === []) {
            throw new DatabaseException(__('The host key of :host changed: it no longer shows :fingerprint. If the server was rebuilt, forget the key on the SSH page and accept the new one.', ['host' => $ssh->host, 'fingerprint' => $ssh->host_key]));
        }

        File::put($dir.'/k', implode("\n", array_map(fn (string $key) => 'studio-tunnel '.$key, $known))."\n");
        $env = [];

        if ($target['auth'] === 'password') {
            File::put($dir.'/a', "#!/bin/sh\nprintf '%s\\n' \"\$STUDIO_TUNNEL_SECRET\"\n");
            chmod($dir.'/a', 0700);
            $env = ['SSH_ASKPASS' => $dir.'/a', 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => 'studio', 'STUDIO_TUNNEL_SECRET' => (string) ($target['password'] ?? '')];
            $auth = ['-o', 'BatchMode=no', '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'NumberOfPasswordPrompts=1'];
        } else {
            File::put($dir.'/i', rtrim((string) ($target['private_key'] ?? ''))."\n");
            chmod($dir.'/i', 0600);
            $auth = ['-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-i', $dir.'/i'];
        }

        $command = [
            (string) config('studio.databases.ssh', 'ssh'), '-F', '/dev/null',
            '-M', '-S', $dir.'/c', '-f', '-N',
            '-o', 'ControlPersist='.(int) config('studio.databases.tunnel_idle', 900),
            '-o', 'StreamLocalBindUnlink=yes', '-o', 'StreamLocalBindMask=0177',
            '-o', 'HostKeyAlias=studio-tunnel', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile='.$dir.'/k', '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=3', '-o', 'ExitOnForwardFailure=yes',
            '-o', 'LogLevel=ERROR', '-o', 'PermitLocalCommand=no',
            ...$auth,
            '-p', (string) $ssh->port, $this->destination($ssh),
        ];

        try {
            // the master goes to the background once logged in: nothing of it may hold this request's pipes
            Process::env([...$env, 'STUDIO_TUNNEL_LOG' => $dir.'/e'])->timeout(40)->run(['sh', '-c', '"$@" </dev/null >/dev/null 2>"$STUDIO_TUNNEL_LOG"', 'sh', ...$command]);

            if (! file_exists($dir.'/c') || ! $this->alive($ssh, $dir)) {
                $reason = trim((string) @file_get_contents($dir.'/e'));

                throw new DatabaseException(__('The SSH tunnel through :name did not open: :reason', ['name' => $ssh->name, 'reason' => $reason !== '' ? $reason : __('no answer')]));
            }
        } finally {
            // the master has logged in: the secret is no longer needed on disk
            @unlink($dir.'/i');
            @unlink($dir.'/a');
        }
    }

    private function alive(SshConnection $ssh, string $dir): bool
    {
        if (! file_exists($dir.'/c')) {
            return false;
        }

        try {
            return Process::timeout(10)->run([...$this->client($ssh, $dir), '-O', 'check', $this->destination($ssh)])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> ssh with the master's socket, for -O commands and remote commands */
    private function client(SshConnection $ssh, string $dir): array
    {
        return [(string) config('studio.databases.ssh', 'ssh'), '-F', '/dev/null', '-S', $dir.'/c', '-o', 'ControlMaster=no', '-o', 'BatchMode=yes', '-o', 'LogLevel=ERROR', '-p', (string) $ssh->port];
    }

    private function destination(SshConnection $ssh): string
    {
        return $ssh->username.'@'.$ssh->host;
    }

    /**
     * One folder per SSH connection and credential: a new key, password, host or user opens
     * another master. Short, so the sockets in it stay within the length a socket path may have.
     */
    public function dir(SshConnection $ssh, User $as): string
    {
        $target = $ssh->forGateway($as);
        $hash = substr(hash_hmac('sha256', implode('|', [$ssh->id, $as->id, $ssh->host, $ssh->port, $ssh->username, $ssh->host_key, $target['auth'], hash('sha256', (string) ($target['password'] ?? $target['private_key'] ?? ''))]), (string) config('app.key')), 0, 16);

        return $this->base().'/'.$hash;
    }

    private function base(): string
    {
        // a socket's path has some 104 bytes at most: folder, hash, forward, .s.PGSQL.65535
        $name = '/studio-db-'.substr(hash('sha256', (string) config('app.key')), 0, 8);
        $base = collect([(string) config('studio.databases.runtime'), rtrim(sys_get_temp_dir(), '/').$name, '/tmp'.$name])
            ->first(fn (string $dir) => $dir !== '' && strlen($dir) <= 50 && is_writable(dirname($dir))) ?? '/tmp'.$name;

        File::ensureDirectoryExists($base, 0700);
        @chmod($base, 0700);

        return $base;
    }

    private function waitFor(string $socket): bool
    {
        for ($i = 0; $i < 40; $i++) {
            if (file_exists($socket)) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }
}
