<?php

namespace App\Console\Commands;

use App\Git\Providers\AzureDevOps;
use App\Git\Providers\GitProvider;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The installation's own git host, chosen in the installer: on until an
 * administrator turns it off, the first one offered for new projects, and the
 * one whose CLI the server installs for the agents first. Projects and
 * accounts may be on any host that is on (Administration → Connectors), so
 * changing it moves nothing and forgets nobody's accounts.
 */
class GitProviderCommand extends Command
{
    protected $signature = 'studio:git-provider
        {provider? : github, gitlab, bitbucket or azure; empty shows the current one}
        {--url= : a self-managed GitLab (https://git.example.com) or the Azure DevOps organization (https://dev.azure.com/acme)}';

    protected $description = 'Show the default git host of this Studio, or change it';

    public function handle(): int
    {
        $current = GitProvider::current();
        $key = $this->argument('provider');

        if (! is_string($key) || $key === '') {
            $this->components->twoColumnDetail('Provider', $current->label());
            $this->components->twoColumnDetail('Address', $current->baseUrl());
            $this->components->twoColumnDetail('CLI for the agents', $current->cli() ?? 'none (REST API)');

            return self::SUCCESS;
        }

        $url = $this->option('url');

        try {
            $next = GitProvider::make($key, $url);

            if ($next instanceof AzureDevOps) {
                $next->organization();
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->writeEnv(['STUDIO_GIT_PROVIDER' => $next->key(), 'STUDIO_GIT_URL' => is_string($url) && $url !== '' ? $next->baseUrl() : '']);

        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:cache');
        }

        $this->components->info("The default git host is now {$next->label()} ({$next->baseUrl()}). Projects and accounts on {$current->label()} stay as they are while it is on.");

        if ($next->cli()) {
            $this->line("  On the server, install the agents' {$next->cli()} with:  <comment>sudo studio-update ensure</comment>");
        }

        return self::SUCCESS;
    }

    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $path = $this->laravel->environmentFilePath();
        $env = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $env = preg_match("/^{$key}=.*$/m", $env)
                ? (string) preg_replace("/^{$key}=.*$/m", $line, $env)
                : rtrim($env, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $env);
    }
}
