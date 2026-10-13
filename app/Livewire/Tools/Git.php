<?php

namespace App\Livewire\Tools;

use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Livewire\Infrastructure\Console;
use App\Models\ConnectorAccount;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Tools → Git: what waits on the git hosts (pull requests asking for a review,
 * issues, notifications) and every repository with its branches, pipelines and
 * releases, through any of the person's own accounts, on any host: the picker
 * moves between their personal GitHub, the company's Azure DevOps and a
 * client's GitLab. The accounts themselves are kept in Settings → Accounts.
 */
#[Layout('layouts::app')]
class Git extends Console
{
    public function mount(?string $connector = null): void
    {
        $accounts = $this->accounts();
        $chosen = $accounts->firstWhere('uuid', $this->account)
            ?? ($connector !== null ? $accounts->firstWhere('connector', $connector) : null)
            ?? $accounts->first();

        abort_unless($connector === null || Forges::isEnabled($connector), 404);

        $this->connector = $chosen->connector ?? $connector ?? 'github';
        $this->account = $chosen?->uuid;
        $this->open();
    }

    protected function allowed(): bool
    {
        return true;
    }

    /** The accounts are kept in Settings → Accounts. */
    protected function managesAccounts(): bool
    {
        return false;
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
        return __('Git').' · '.$this->definition()->label();
    }

    #[Computed]
    public function definition(): Forge
    {
        return Forges::make($this->connector);
    }

    /**
     * Every git account of the person, on every host.
     *
     * @return Collection<int, ConnectorAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return ConnectorAccount::query()->gitFor(auth()->user())->orderBy('connector')->orderBy('name')->get();
    }

    public function currentAccount(): ?ConnectorAccount
    {
        return $this->accounts()->where('connector', $this->connector)->firstWhere('uuid', $this->account);
    }

    /** Another account, maybe on another host: its own lists, from the first. */
    public function useAccount(string $uuid): void
    {
        $account = $this->accounts()->firstWhere('uuid', $uuid);

        if ($account === null) {
            return;
        }

        $moved = $account->connector !== $this->connector;
        $this->account = $account->uuid;

        if ($moved) {
            $this->connector = $account->connector;
            unset($this->definition);
            $this->tab = null;
            $this->scope = [];
            $this->search = '';
            $this->tab = $this->section()->key;
            $this->filters = $this->defaultFilters($this->section());
        } elseif ($this->section()->isChild()) {
            $this->back();
        }

        unset($this->listing);
    }
}
