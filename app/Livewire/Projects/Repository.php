<?php

namespace App\Livewire\Projects;

use App\Accounts\Accounts;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\GitClient;
use App\Infrastructure\Section;
use App\Livewire\Tools\Git;
use App\Models\ConnectorAccount;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * A project's repository on its git host, in the project: its pull requests,
 * issues, branches, pipelines and releases, read and run with the person's own
 * account on that host (the one their workspace commits with), so what they can
 * do here is what the host lets them do.
 */
#[Layout('layouts::studio')]
class Repository extends Git
{
    #[Locked]
    public string $projectUuid = '';

    /** @var array<string, string> what the repository's lists are asked with: its id, its name, its default branch */
    #[Locked]
    public array $repoScope = [];

    #[Locked]
    public ?string $problem = null;

    public function mount(?string $connector = null, ?Project $project = null): void
    {
        $project ??= abort(404);
        abort_unless(auth()->user()->can('chat', $project), 403);
        $this->projectUuid = $project->uuid;

        $choice = Accounts::git(auth()->user(), $project);
        $path = $project->repositoryPath();

        if ($choice === null || $path === null) {
            $this->problem = $choice === null ? 'account' : 'repository';
            $this->connector = $choice?->account->connector ?? 'github';

            return;
        }

        $this->connector = $choice->account->connector;
        $this->account = $choice->account->uuid;

        try {
            $row = $this->repositoryRow($choice->account, $path);
        } catch (ConnectorException $e) {
            $this->problem = 'unreadable';
            $this->resultText = $e->getMessage();

            return;
        }

        $tabs = $this->tabSections();
        $opens = $this->definition()->section($this->definition()->repositorySection())->actions ?? [];

        foreach ($opens as $action) {
            if ($action->opens !== null && $action->scope !== null) {
                $this->repoScope = array_map('strval', ($action->scope)($row));
                break;
            }
        }

        $this->tab = $tabs[0]->key ?? null;
        $this->scope = $this->repoScope;
        $this->filters = $tabs !== [] ? $this->defaultFilters($tabs[0]) : [];
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->where('uuid', $this->projectUuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function repositoryRow(ConnectorAccount $account, string $path): array
    {
        /** @var Forge $forge */
        $forge = $account->definition();

        return decrypt(Cache::remember($account->cacheKey('repository', $path), 600, function () use ($account, $forge, $path) {
            /** @var GitClient $client */
            $client = $account->client();

            return encrypt($forge->repository($client, $path));
        }));
    }

    protected function allowed(): bool
    {
        return auth()->user()->can('chat', $this->project());
    }

    protected function managesAccounts(): bool
    {
        return false;
    }

    protected function pageTitle(): string
    {
        return $this->project()->name.' · '.__('Repository');
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        $choice = Accounts::git(auth()->user(), $this->project());

        return new Collection(array_filter([$choice?->account]));
    }

    /** The lists a repository row opens: pull requests, issues, branches, pipelines, releases. */
    protected function tabSections(): array
    {
        $definition = $this->definition();
        $repositories = $definition->section($definition->repositorySection());
        $tabs = [];

        foreach ($repositories->actions ?? [] as $action) {
            if ($action->opens !== null && ($section = $definition->section($action->opens)) !== null) {
                $tabs[$section->key] = $section;
            }
        }

        return array_values($tabs);
    }

    public function section(): Section
    {
        $tabs = $this->tabSections();

        return ($this->tab !== null ? $this->definition()->section($this->tab) : null) ?? $tabs[0] ?? parent::section();
    }

    protected function compactHeader(): ?array
    {
        $account = $this->currentAccount();
        $who = $account ? $account->gitCredentials() : null;

        return [
            'title' => (string) ($this->repoScope['repo_name'] ?? $this->project()->repositoryPath() ?? $this->project()->repo_url),
            'subtitle' => $who ? __(':provider · as :name <:email>, your account :account', ['provider' => $this->definition()->label(), 'name' => $who['name'], 'email' => $who['email'], 'account' => $account->name]) : $this->definition()->label(),
            'href' => $this->project()->settings['repository'] ?? null,
        ];
    }

    public function select(string $tab): void
    {
        if (! in_array($tab, array_map(fn (Section $s) => $s->key, $this->tabSections()), true)) {
            return;
        }

        $this->tab = $tab;
        $this->scope = $this->repoScope;
        $this->search = '';
        $this->filters = $this->defaultFilters($this->section());
        unset($this->listing);
    }

    public function selectGroup(string $group): void {}

    public function useAccount(string $uuid): void {}

    /** From a pull request's thread (or any list opened from a tab) back to the tab. */
    public function back(): void
    {
        $parent = $this->section()->parent;
        $tabs = array_map(fn (Section $s) => $s->key, $this->tabSections());

        $this->select($parent !== null && in_array($parent, $tabs, true) ? $parent : ($tabs[0] ?? ''));
    }

    /** The repository's own lists keep its scope under whatever a row adds. */
    protected function context(): array
    {
        return [...$this->repoScope, ...parent::context()];
    }

    public function render(): View
    {
        if ($this->problem !== null) {
            return view('livewire.projects.repository-missing', [
                'project' => $this->project(),
                'problem' => $this->problem,
                'reason' => $this->resultText,
                'title' => $this->pageTitle(),
            ]);
        }

        return view('livewire.projects.repository', [
            ...$this->viewData(),
            'project' => $this->project(),
            'title' => $this->pageTitle(),
        ]);
    }
}
