<?php

namespace App\Console\Commands;

use App\Git\Providers\AzureDevOps;
use App\Git\Providers\GitProvider;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The git provider is chosen once, in the installer. While Studio has no
 * projects it can still be changed here: projects never move between
 * providers and Studio keeps no history of the previous one, so every
 * person's token is forgotten with it.
 */
class GitProviderCommand extends Command
{
    protected $signature = 'studio:git-provider
        {provider? : github, gitlab, bitbucket or azure; empty shows the current one}
        {--url= : a self-managed GitLab (https://git.example.com) or the Azure DevOps organization (https://dev.azure.com/acme)}';

    protected $description = 'Show the git provider of this Studio, or change it while there are no projects';

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

        if (($projects = Project::query()->count()) > 0) {
            $this->components->error("Studio has {$projects} project(s) on {$current->label()}. The provider is chosen once: delete the projects first, or install another Studio for {$next->label()}.");

            return self::FAILURE;
        }

        $this->writeEnv(['STUDIO_GIT_PROVIDER' => $next->key(), 'STUDIO_GIT_URL' => is_string($url) && $url !== '' ? $next->baseUrl() : '']);
        $forgotten = User::query()->whereNotNull('git_token')->update(['git_token' => null, 'git_login' => null]);

        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:cache');
        }

        $this->components->info("The git provider is now {$next->label()} ({$next->baseUrl()}).");

        if ($forgotten > 0) {
            $this->components->warn("{$forgotten} token(s) of the previous provider were removed: everyone connects again in Settings → Connections.");
        }

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
