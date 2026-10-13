<?php

namespace App\Backups;

use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupTarget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs backups with rclone (S3 and SFTP on both ends, any direction) and ssh (the archive of
 * a folder, made with tar on the source host and streamed to the destination without touching
 * Studio's disk). Every secret goes through a Workbench: files only Studio reads, and the
 * environment of the command.
 */
class Engine
{
    /** Make the snapshot, measure it, keep what the retention keeps. */
    public function backup(BackupRun $run): void
    {
        $job = $run->job()->with(['source', 'destination'])->firstOrFail();
        $bench = new Workbench;
        $stamp = now($job->timezone)->format('Y-m-d_His');
        $base = trim($job->destination_path, '/');

        try {
            if ($job->preCommand() !== '') {
                if (! $job->source->isSsh()) {
                    throw new RuntimeException(__('A command before the copy needs an SSH source.'));
                }

                $this->log($run, '$ '.$job->preCommand()."\n");
                $this->run($run, $bench, $bench->ssh($job->source, $job->preCommand()));
            }

            $path = match ($job->mode) {
                'archive' => ltrim($base.'/'.Str::slug($job->name).'-'.$stamp.'.tar.gz', '/'),
                'snapshot' => ltrim($base.'/'.$stamp, '/'),
                default => $base,
            };
            $run->forceFill(['path' => $path, 'destination_id' => $job->destination_id])->save();
            $destination = $bench->remote('dst', $job->destination, $path);

            if ($job->mode === 'archive') {
                if (! $job->source->isSsh()) {
                    throw new RuntimeException(__('An archive is made on the source host: the source must be SSH.'));
                }

                $excludes = implode('', array_map(fn (string $e) => ' --exclude='.escapeshellarg($e), $job->excludes()));
                // tar says 1 when a file changed while it was read (a log being written): the archive is still good
                $tar = 'tar -czf - -C '.escapeshellarg($job->source_path !== '' ? $job->source_path : '.').$excludes.' . ; rc=$?; if [ $rc -gt 1 ]; then exit $rc; fi';
                $this->log($run, __('Archiving :source to :path', ['source' => $job->sourceLabel(), 'path' => $path])."\n");
                $this->run($run, $bench, ['bash', '-c', 'set -o pipefail; '.self::line($bench->ssh($job->source, $tar)).' | '.self::line($this->rclone(['rcat', $destination]))]);
            } else {
                $source = $bench->remote('src', $job->source, $job->source_path);
                $excludes = collect($job->excludes())->flatMap(fn (string $e) => ['--exclude', $e])->all();
                $verb = $job->mode === 'mirror' ? 'sync' : 'copy';
                $this->log($run, __(':verb :source to :path', ['verb' => ucfirst($verb), 'source' => $job->sourceLabel(), 'path' => $path ?: '/'])."\n");
                $this->run($run, $bench, $this->rclone([$verb, $source, $destination, '--transfers', '8', '--checkers', '16', '--create-empty-src-dirs', ...$excludes]));
            }

            $size = $this->size($bench, $destination);
            $run->forceFill(['bytes' => $size['bytes'], 'files' => $size['count']])->save();
        } finally {
            $bench->clean();
        }
    }

    /** Thin out the job's snapshots: delete at the destination what no rule keeps any more. */
    public function prune(BackupJob $job, ?BackupRun $log = null): int
    {
        if (! $job->keepsVersions()) {
            return 0;
        }

        $runs = $job->runs()->with('destination')->where('status', 'success')->where('trigger', '!=', 'restore')
            ->whereNull('pruned_at')->whereNotNull('path')->whereNotNull('destination_id')->get()->keyBy('id');
        $keep = Retention::keep($runs->map(fn (BackupRun $run) => $run->started_at ?? $run->created_at)->all(), $job->retentionPolicy(), $job->timezone);
        $pruned = 0;

        foreach ($runs as $id => $run) {
            if (isset($keep[$id])) {
                $run->forceFill(['kept_by' => $keep[$id]])->save();

                continue;
            }

            try {
                $this->delete($run);
                $pruned++;
                $log && $this->log($log, __('Pruned :path', ['path' => $run->path])."\n");
            } catch (Throwable $e) {
                // it stays listed, and the next run tries again
                Log::warning('backup prune failed', ['run' => $run->uuid, 'error' => $e->getMessage()]);
                $log && $this->log($log, __('Could not prune :path: :error', ['path' => $run->path, 'error' => $e->getMessage()])."\n");
            }
        }

        return $pruned;
    }

    /** Delete one snapshot at its destination. */
    public function delete(BackupRun $run): void
    {
        if (! $run->hasSnapshot() || $run->job->mode === 'mirror') {
            throw new RuntimeException(__('There is no snapshot of its own to delete.'));
        }

        $bench = new Workbench;

        try {
            $remote = $bench->remote('dst', $run->destination, (string) $run->path);
            $this->run(null, $bench, $this->rclone([$run->isArchive() ? 'deletefile' : 'purge', $remote], verbose: false));
        } finally {
            $bench->clean();
        }

        $run->forceFill(['pruned_at' => now(), 'kept_by' => null])->save();
    }

    /** Put a snapshot back: an archive is unpacked on an SSH host, a folder copied anywhere. */
    public function restore(BackupRun $restore): void
    {
        $snapshot = $restore->restoreOf ?? throw new RuntimeException(__('The snapshot to restore is gone.'));
        $target = BackupTarget::query()->find($restore->restore_to['target'] ?? 0) ?? throw new RuntimeException(__('Where to restore is gone.'));
        $path = trim((string) ($restore->restore_to['path'] ?? ''));
        $bench = new Workbench;

        try {
            $from = $bench->remote('src', $snapshot->destination ?? throw new RuntimeException(__('The storage of the snapshot is gone.')), (string) $snapshot->path);
            $this->log($restore, __('Restoring :snapshot to :target', ['snapshot' => $snapshot->path, 'target' => $target->name.':'.($path ?: '~')])."\n");

            if ($snapshot->isArchive() && $target->isSsh()) {
                $dir = escapeshellarg($path !== '' ? $path : '.');
                $this->run($restore, $bench, ['bash', '-c', 'set -o pipefail; '.self::line($this->rclone(['cat', $from], verbose: false)).' | '.self::line($bench->ssh($target, "mkdir -p {$dir} && tar -xzf - -C {$dir}"))]);
            } elseif ($snapshot->isArchive()) {
                $this->run($restore, $bench, $this->rclone(['copyto', $from, $bench->remote('dst', $target, ltrim($path.'/'.basename((string) $snapshot->path), '/'))]));
            } else {
                $this->run($restore, $bench, $this->rclone(['copy', $from, $bench->remote('dst', $target, $path), '--transfers', '8']));
            }
        } finally {
            $bench->clean();
        }
    }

    /** A link to download an archive kept in a bucket, valid for an hour. */
    public function link(BackupRun $run): string
    {
        if (! $run->hasSnapshot() || ! $run->isArchive() || ! $run->destination?->isS3()) {
            throw new RuntimeException(__('Only an archive kept in a bucket can be downloaded.'));
        }

        $bench = new Workbench;

        try {
            $url = trim($this->run(null, $bench, $this->rclone(['link', '--expire', '1h', $bench->remote('dst', $run->destination, (string) $run->path)], verbose: false)));
        } finally {
            $bench->clean();
        }

        return Str::startsWith($url, 'http') ? $url : throw new RuntimeException(__('The storage did not give a link.'));
    }

    /** Can Studio reach it? "ok · …" or the reason it cannot, stored on the target. */
    public function test(BackupTarget $target): string
    {
        $bench = new Workbench;

        try {
            if ($target->isS3()) {
                $items = $this->run(null, $bench, $this->rclone(['lsf', '--max-depth', '1', $bench->remote('dst', $target)], verbose: false));
                $count = count(array_filter(explode("\n", $items)));
                $status = 'ok · '.trans_choice(':count item at the top|:count items at the top', $count);
            } else {
                $answer = $this->run(null, $bench, $bench->ssh($target, 'echo studio-ok; uname -sr; df -Ph . | tail -n 1'));

                if (! str_contains($answer, 'studio-ok')) {
                    throw new RuntimeException(__('The host answered, but not as expected.'));
                }

                $lines = array_values(array_filter(array_map('trim', explode("\n", $answer))));
                $disk = preg_split('/\s+/', (string) ($lines[2] ?? '')) ?: [];
                $status = 'ok · '.($lines[1] ?? '').(isset($disk[3]) ? ' · '.__(':free free', ['free' => $disk[3]]) : '');
            }
        } catch (Throwable $e) {
            $status = Str::limit($e->getMessage(), 250);
        } finally {
            $bench->clean();
        }

        $target->forceFill(['status' => $status, 'checked_at' => now()])->save();

        return $status;
    }

    /**
     * The host keys a host shows, "type base64" each, to pin before the first connection.
     *
     * @return list<string>
     */
    public function hostKeys(string $host, int $port = 22): array
    {
        $result = Process::timeout(30)->run([(string) config('studio.backups.ssh_keyscan', 'ssh-keyscan'), '-T', '10', '-p', (string) $port, $host]);
        $keys = [];

        foreach (preg_split('/\r\n|\r|\n/', $result->output()) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) >= 3 && ! str_starts_with($parts[0], '#') && preg_match('/^(ssh-|ecdsa-)/', $parts[1])) {
                $keys[] = $parts[1].' '.$parts[2];
            }
        }

        if ($keys === []) {
            throw new RuntimeException(__(':host (port :port) shows no SSH host key: is it reachable from this server?', ['host' => $host, 'port' => $port]));
        }

        return array_values(array_unique($keys));
    }

    /** SHA256:… as ssh shows it, of a "type base64" host key. */
    public static function fingerprint(string $key): string
    {
        $blob = base64_decode((string) (explode(' ', trim($key))[1] ?? ''), true);

        return $blob === false ? '?' : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /** @return array{bytes: int|null, count: int|null} */
    private function size(Workbench $bench, string $remote): array
    {
        try {
            $size = json_decode($this->run(null, $bench, $this->rclone(['size', '--json', $remote], verbose: false)), true);
        } catch (Throwable) {
            return ['bytes' => null, 'count' => null];
        }

        return ['bytes' => isset($size['bytes']) ? (int) $size['bytes'] : null, 'count' => isset($size['count']) ? (int) $size['count'] : null];
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function rclone(array $arguments, bool $verbose = true): array
    {
        return [
            (string) config('studio.backups.rclone', 'rclone'),
            ...$arguments,
            '--retries', '3',
            '--low-level-retries', '10',
            '--contimeout', '30s',
            ...($verbose ? ['--stats', '1m', '--stats-one-line', '-v'] : []),
        ];
    }

    /** @param  list<string>  $command */
    private static function line(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * Run a command with the workbench's environment, its output into the run's log; its
     * standard output back, or an exception that says why it failed.
     *
     * @param  list<string>  $command
     */
    private function run(?BackupRun $run, Workbench $bench, array $command): string
    {
        $saved = microtime(true);
        $stdout = '';
        $stderr = '';

        $process = Process::env($bench->env())
            ->timeout((int) config('studio.backups.timeout', 21600))
            ->start($command, function (string $type, string $output) use ($run, &$stdout, &$stderr) {
                $type === 'err' ? $stderr .= $output : $stdout .= $output;
                $run?->appendOutput($output);
            });

        // the log reaches the page as it grows; a quiet command (a long dump) still says it is alive
        while ($process->running()) {
            usleep(250_000);
            $process->ensureNotTimedOut();

            if ($run !== null && microtime(true) - $saved > 5) {
                $run->isDirty('output') ? $run->save() : $run->touch();
                $saved = microtime(true);
            }
        }

        $result = $process->wait();

        if ($run !== null && $run->isDirty('output')) {
            $run->save();
        }

        if (! $result->successful()) {
            $error = trim($stderr !== '' ? $stderr : $result->errorOutput()) ?: trim($result->output());
            $lines = array_slice(array_values(array_filter(explode("\n", $error), fn (string $l) => trim($l) !== '')), -4);

            throw new RuntimeException(Str::limit(implode("\n", $lines) ?: __('The command failed with exit code :code.', ['code' => $result->exitCode()]), 600));
        }

        return $stdout !== '' ? $stdout : $result->output();
    }

    private function log(BackupRun $run, string $text): void
    {
        $run->appendOutput($text);
        $run->save();
    }
}
