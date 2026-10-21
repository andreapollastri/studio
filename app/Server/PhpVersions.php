<?php

namespace App\Server;

use App\Jobs\InstallPhp;
use Illuminate\Support\Facades\Cache;

/**
 * The PHP versions of the server. Every server gets the release's own (8.5, which runs
 * Studio and which new projects start on); the release's optional ones (8.3, 8.4) are
 * added from Administration → System, one at a time, by studio-admin php-install on the
 * queue. What the release offers is in installer/requirements.env, what is installed
 * in STUDIO_PHP_VERSIONS (the system step and php-install keep it up to date).
 */
final class PhpVersions
{
    /** The install under way or the last one that failed: version, status (running|failed), error, at. */
    private const STATE = 'studio:php-install';

    /** @return list<string> the PHP versions installed here, oldest first */
    public function installed(): array
    {
        $versions = array_values(array_unique(array_filter(array_map('strval', (array) config('studio.php_versions')))));
        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /** The PHP new projects start on: the release's own where the server has it, otherwise the newest it has. */
    public function default(): string
    {
        $installed = $this->installed();
        $own = $this->requirement('STUDIO_PHP') ?: '8.5';

        if ($installed === [] || in_array($own, $installed, true)) {
            return $own;
        }

        return (string) end($installed);
    }

    /** @return list<string> the release's optional versions, installed or not, oldest first */
    public function optional(): array
    {
        $versions = array_values(array_filter(preg_split('/\s+/', $this->requirement('PHP_OPTIONAL')) ?: [], fn (string $v): bool => (bool) preg_match('/^\d+\.\d+$/', $v)));
        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /** @return list<string> the optional versions this server does not have yet */
    public function installable(): array
    {
        return array_values(array_diff($this->optional(), $this->installed()));
    }

    /** @return array{version: string, status: string, error: ?string, at: string}|null */
    public function state(): ?array
    {
        $state = Cache::get(self::STATE);

        if (! is_array($state) || ! is_string($state['version'] ?? null) || ! is_string($state['status'] ?? null)) {
            return null;
        }

        return [
            'version' => $state['version'],
            'status' => $state['status'],
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'at' => is_string($state['at'] ?? null) ? $state['at'] : '',
        ];
    }

    public function installing(): ?string
    {
        $state = $this->state();

        return ($state['status'] ?? null) === 'running' ? (string) $state['version'] : null;
    }

    /** Queue the install of an optional version; one at a time, as apt is. */
    public function install(string $version): void
    {
        if (! in_array($version, $this->installable(), true)) {
            throw new ServerException(__('PHP :version is not one this server can add.', ['version' => $version]));
        }

        if (($running = $this->installing()) !== null) {
            throw new ServerException(__('PHP :version is being installed: one at a time.', ['version' => $running]));
        }

        // a worker that dies leaves "running" behind: it expires with the job's own timeout
        Cache::put(self::STATE, ['version' => $version, 'status' => 'running', 'error' => null, 'at' => now()->toIso8601String()], now()->addMinutes(30));
        InstallPhp::dispatch($version);
    }

    public function finished(string $version, ?string $error = null): void
    {
        if ($error === null) {
            Cache::forget(self::STATE);

            return;
        }

        Cache::put(self::STATE, ['version' => $version, 'status' => 'failed', 'error' => $error, 'at' => now()->toIso8601String()], now()->addDay());
    }

    /** One KEY=VALUE of the running release's installer/requirements.env, unquoted. */
    private function requirement(string $key): string
    {
        $file = base_path('installer/requirements.env');
        $lines = is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        foreach ($lines as $line) {
            if (str_starts_with($line, $key.'=')) {
                return trim(substr($line, strlen($key) + 1), "\"' ");
            }
        }

        return '';
    }
}
