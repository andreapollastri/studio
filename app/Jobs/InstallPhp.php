<?php

namespace App\Jobs;

use App\Server\PhpVersions;
use App\Server\ServerException;
use App\Server\StudioAdmin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One of the release's optional PHP versions onto the server (Administration → System):
 * its packages, extensions and PHP-FPM, through studio-admin php-install, which also
 * rebuilds Studio's cached configuration so the project forms offer it.
 */
class InstallPhp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(public string $version) {}

    public function handle(StudioAdmin $admin, PhpVersions $php): void
    {
        try {
            $admin->run('php-install', [$this->version]);
            $php->finished($this->version);
        } catch (ServerException $e) {
            Log::warning('PHP install failed', ['version' => $this->version, 'error' => $e->getMessage()]);
            $php->finished($this->version, $e->getMessage());
        }
    }
}
