<?php

namespace App\Livewire\Tools;

use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Infrastructure\Git\GitClient;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Infrastructure\Console;
use App\Models\ConnectorAccount;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Tools → a git host (GitHub, GitLab, Bitbucket, Azure DevOps): pull
 * requests waiting for a review, issues, repositories with their branches,
 * pipelines and releases. The connector page, run with the person's own
 * accounts: they add as many as they work with (personal, work, a client's),
 * each with the name and email its commits carry, and act as themselves.
 */
#[Layout('layouts::app')]
class Git extends Console
{
    /** The commit identity of the account in the form. */
    public string $git_name = '';

    public string $git_email = '';

    public function mount(string $connector): void
    {
        abort_unless(Forges::isEnabled($connector), 404);

        $this->connector = $connector;
        $this->open();
    }

    protected function allowed(): bool
    {
        return Forges::isEnabled($this->connector);
    }

    protected function managesAccounts(): bool
    {
        return true;
    }

    protected function personal(): bool
    {
        return true;
    }

    protected function accountOwner(): ?int
    {
        return (int) auth()->id();
    }

    protected function pageTitle(): string
    {
        return $this->definition()->label().' · '.__('Tools');
    }

    #[Computed]
    public function definition(): Forge
    {
        return Forges::make($this->connector);
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return ConnectorAccount::query()->gitFor(auth()->user())->where('connector', $this->connector)->orderBy('name')->get();
    }

    protected function fillAccountForm(?ConnectorAccount $account): void
    {
        parent::fillAccountForm($account);

        $this->git_name = (string) $account?->git_name;
        $this->git_email = (string) $account?->git_email;
    }

    protected function accountRules(): array
    {
        return [
            'git_name' => ['nullable', 'string', 'max:255'],
            'git_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** Who the token belongs to; the commit identity as typed, or as the provider tells it. */
    protected function completeAccount(ConnectorAccount $account, object $client): void
    {
        /** @var GitClient $client */
        $who = $this->definition()->identity($client);

        $account->fill([
            'login' => $who['login'],
            'host' => $this->definition()->host($this->credentials),
            'git_name' => trim($this->git_name) ?: ($who['name'] ?: auth()->user()->name),
            'git_email' => trim($this->git_email) ?: ($who['email'] ?: auth()->user()->email),
        ]);

        $this->git_name = (string) $account->git_name;
        $this->git_email = (string) $account->git_email;
    }

    /** The workspaces take a new token or identity at once; the first account also names the person's home and hosts. */
    protected function accountSaved(ConnectorAccount $account, bool $credentialsChanged): void
    {
        $user = auth()->user();
        $user->ensureHandle();

        if ($credentialsChanged || $account->wasChanged(['git_name', 'git_email'])) {
            SyncWorkspaceCredentials::dispatch($user);
        }
    }

    protected function accountRemoved(ConnectorAccount $account): void
    {
        SyncWorkspaceCredentials::dispatch(auth()->user());
    }
}
