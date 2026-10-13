<?php

namespace App\Infrastructure\Git;

use App\Infrastructure\Connector;

/**
 * A git host as a connector of personal accounts: besides its dashboard, it
 * says where its repositories live (so a remote finds its account), the user
 * name git sends over HTTPS with the token, and who the token belongs to, for
 * the commit identity of the account.
 */
abstract class Forge extends Connector
{
    public function guide(): string
    {
        return 'https://studio.web.ap.it/#git-'.$this->key();
    }

    /**
     * Where the repositories of an account live: github.com, gitlab.example.com,
     * dev.azure.com/organization.
     *
     * @param  array<string, mixed>  $credentials
     */
    abstract public function host(array $credentials): string;

    /** The list of repositories (or projects), whose rows open pull requests, issues, branches, pipelines, releases. */
    abstract public function repositorySection(): string;

    /**
     * One repository as a row of that list, by its path on the host (owner/name, a
     * GitLab namespace, organization/project/name on Azure DevOps).
     *
     * @return array<string, mixed>
     */
    abstract public function repository(GitClient $api, string $path): array;

    /** The user name git sends over HTTPS together with the token. */
    abstract public function gitUsername(string $login): string;

    /**
     * Who the token belongs to: the login, and the name and email to commit with
     * when the provider tells them (otherwise the address it accepts for commits).
     *
     * @return array{login: string, name: string|null, email: string|null}
     */
    abstract public function identity(GitClient $api): array;

    /**
     * Whether a remote (https://host/…, git@host:…, ssh://git@host/…) is on a
     * host: github.com, or dev.azure.com/organization with its first path part.
     * Azure's SSH remotes (ssh.dev.azure.com:v3/organization/…) count as theirs.
     */
    public static function remoteOn(string $host, string $remote): bool
    {
        $host = strtolower(trim($host, '/'));
        $remote = strtolower(trim($remote));

        if (preg_match('#^(?:https?|ssh)://(?:[^@/]+@)?([^/:]+)(?::\d+)?(/.*)?$#', $remote, $m)) {
            $where = $m[1].rtrim($m[2] ?? '', '/');
        } elseif (preg_match('#^(?:[^@/]+@)?([^:/]+):(.*)$#', $remote, $m)) {
            $where = $m[1].'/'.$m[2];
        } else {
            return false;
        }

        $where = (string) preg_replace('#^ssh\.dev\.azure\.com/v3/#', 'dev.azure.com/', $where);

        return $host !== '' && ($where === $host || str_starts_with($where, $host.'/'));
    }
}
