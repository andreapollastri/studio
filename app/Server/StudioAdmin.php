<?php

namespace App\Server;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The one door to root on this server: `studio-admin`, the helper the
 * installer places in /usr/local/sbin and lets the Studio user run through
 * sudo for a fixed list of commands. Arguments are names (a handle, a slug);
 * anything secret travels on stdin as JSON; the answer is one JSON object.
 */
final class StudioAdmin
{
    /**
     * @param  list<string>  $args
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $command, array $args = [], array $input = []): array
    {
        foreach ($args as $arg) {
            if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $arg)) {
                throw new ServerException("Refusing to pass [{$arg}] to the server helper.");
            }
        }

        $cmd = array_merge(
            config('studio.native.sudo') ? ['sudo', '-n'] : [],
            [(string) config('studio.native.helper'), $command],
            $args,
        );

        // Always a JSON object on stdin: the helper indexes it by key, and `[]` is what an empty PHP array would become.
        $stdin = json_encode($input === [] ? new stdClass : $input, JSON_THROW_ON_ERROR).PHP_EOL;
        $process = new Process($cmd, null, ['LC_ALL' => 'C.UTF-8', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'], $stdin);
        $process->setTimeout((int) config('studio.native.timeout', 1200));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ServerException("The server helper did not finish [{$command}] in time.", previous: $e);
        }

        $stdout = trim($process->getOutput());
        $decoded = $this->answer($stdout);

        if (! $process->isSuccessful() || ! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $stderr = trim($process->getErrorOutput());
            $reason = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : Str::limit($stderr ?: $stdout ?: 'no output', 1200);

            // The helper stopped at a command it did not expect to fail: what that command said is on stderr.
            if (($decoded['unexpected'] ?? false) === true && $stderr !== '') {
                $reason .= ' — '.Str::limit(implode(' ', array_slice(preg_split('/\R+/', $stderr) ?: [], -4)), 600);
            }

            // The page shows the one-line reason; the helper's own log lines (what git,
            // composer or artisan said) go to storage/logs for whoever digs.
            Log::warning("studio-admin {$command} failed", ['args' => $args, 'reason' => $reason, 'stderr' => Str::limit($stderr, 4000)]);

            throw new ServerException("studio-admin {$command} failed: {$reason}");
        }

        return $decoded;
    }

    /**
     * The helper's answer: stdout as one JSON object (jq prints it over several lines), or
     * else the first line that is one. fail() inside a $(…) answers first, and the helper
     * then adds a generic line of its own; the first one is the reason.
     *
     * @return array<string, mixed>|null
     */
    private function answer(string $stdout): ?array
    {
        $decoded = json_decode($stdout === '' ? 'null' : $stdout, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
