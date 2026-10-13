<?php

namespace App\Livewire\Settings;

use App\Infrastructure\ConnectorException;
use App\Infrastructure\Field;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Infrastructure\Git\GitClient;
use App\Jobs\SyncWorkspaceCredentials;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectPreference;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Accounts on the git hosts, in one place. A person's own (Settings → Accounts):
 * their personal GitHub, the company's Azure DevOps, a client's GitLab, each
 * with the name and email its commits carry; their workspaces clone, commit and
 * push with the one on the host of each repository. Or, for the administrators,
 * the team's service accounts (Administration → Connectors): a bot's token that
 * creates and deploys project sites whoever the administrator is.
 */
class GitAccounts extends Component
{
    /** The team's service accounts (administrators), or the person's own. */
    #[Locked]
    public bool $shared = false;

    /** uuid of the account in the form; 'new' for a new one */
    public ?string $editing = null;

    public string $host = 'github';

    public string $name = '';

    /** @var array<string, mixed> */
    public array $credentials = [];

    public string $git_name = '';

    public string $git_email = '';

    public function mount(bool $shared = false): void
    {
        abort_if($shared && ! auth()->user()->isAdmin(), 403);

        $this->shared = $shared;
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return ConnectorAccount::query()
            ->whereIn('connector', array_keys(Forges::ALL))
            ->when($this->shared, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('connector')->orderBy('name')->get();
    }

    private function forge(): Forge
    {
        return Forges::make(isset(Forges::ALL[$this->host]) ? $this->host : 'github');
    }

    private function owner(): ?int
    {
        abort_if($this->shared && ! auth()->user()->isAdmin(), 403);

        return $this->shared ? null : (int) auth()->id();
    }

    public function add(string $host = 'github'): void
    {
        $this->owner();
        $this->editing = 'new';
        $this->host = isset(Forges::ALL[$host]) ? $host : 'github';
        $this->fillForm(null);

        Flux::modal($this->modal())->show();
    }

    public function edit(string $uuid): void
    {
        $this->owner();
        $account = $this->accounts()->firstWhere('uuid', $uuid) ?? abort(404);

        $this->editing = $account->uuid;
        $this->host = $account->connector;
        $this->fillForm($account);

        Flux::modal($this->modal())->show();
    }

    /** A different host in the form: its own fields. */
    public function updatedHost(): void
    {
        if ($this->editing === 'new') {
            $this->fillForm(null);
        }
    }

    private function fillForm(?ConnectorAccount $account): void
    {
        $this->name = $account->name ?? '';
        $this->git_name = (string) $account?->git_name;
        $this->git_email = (string) $account?->git_email;
        $this->credentials = [];

        foreach ($this->forge()->fields() as $field) {
            $stored = $account?->credential($field->name);
            $this->credentials[$field->name] = $field->secret ? '' : (is_scalar($stored) ? (string) $stored : (is_scalar($default = $field->defaultFor([])) ? (string) $default : ''));
        }

        $this->resetValidation();
    }

    public function save(): void
    {
        $owner = $this->owner();
        $account = $this->editing !== 'new' ? $this->accounts()->firstWhere('uuid', (string) $this->editing) : null;
        $forge = $this->forge();
        $fields = $forge->fields();

        $this->validate([
            'host' => ['required', Rule::in(array_keys(Forges::ALL))],
            'name' => ['required', 'string', 'max:80', Rule::unique('connector_accounts', 'name')->where('connector', $this->host)->where('user_id', $owner)->ignore($account?->id)],
            'git_name' => ['nullable', 'string', 'max:255'],
            'git_email' => ['nullable', 'email', 'max:255'],
            ...collect($fields)->mapWithKeys(fn (Field $field) => ['credentials.'.$field->name => $field->validation(keepsStoredSecret: $account !== null)])->all(),
        ], [], [
            'name' => __('name'),
            ...collect($fields)->mapWithKeys(fn (Field $field) => ['credentials.'.$field->name => mb_strtolower($field->label)])->all(),
        ]);

        $credentials = $account->credentials ?? [];

        foreach ($fields as $field) {
            $value = $field->cast($this->credentials[$field->name] ?? null);

            if ($field->secret && $value === null && $account !== null) {
                continue;
            }

            $credentials[$field->name] = $value;
        }

        try {
            /** @var GitClient $client */
            $client = $forge->client($credentials);
            $status = $forge->verify($client);
            $who = $forge->identity($client);
        } catch (ConnectorException $e) {
            $this->addError('credentials', __('The provider refused these credentials: :reason', ['reason' => $e->getMessage()]));

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('credentials', __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $account ??= new ConnectorAccount(['connector' => $this->host, 'user_id' => $owner]);
        $changed = ! $account->exists || $account->credentials !== $credentials;

        $account->fill([
            'name' => trim($this->name),
            'credentials' => $credentials,
            'status' => $status,
            'checked_at' => now(),
            'login' => $who['login'],
            'host' => $forge->host($credentials),
            'git_name' => trim($this->git_name) ?: ($who['name'] ?: ($this->shared ? $who['login'] : auth()->user()->name)),
            'git_email' => trim($this->git_email) ?: ($who['email'] ?: ($this->shared ? null : auth()->user()->email)),
        ])->save();
        $account->forget();

        if (! $this->shared) {
            auth()->user()->ensureHandle();

            if ($changed || $account->wasChanged(['git_name', 'git_email'])) {
                SyncWorkspaceCredentials::dispatch(auth()->user());
            }
        }

        unset($this->accounts);
        $this->editing = null;
        Flux::modal($this->modal())->close();
        Flux::toast(variant: 'success', text: __('Account saved: :status', ['status' => $status]));
    }

    public function check(string $uuid): void
    {
        $this->owner();
        $account = $this->accounts()->firstWhere('uuid', $uuid) ?? abort(404);

        try {
            $status = $account->definition()->verify($account->client());
            Flux::toast(variant: 'success', text: $status);
        } catch (\Throwable $e) {
            $status = $e instanceof ConnectorException ? $e->getMessage() : __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: $status);
        }

        $account->forceFill(['status' => mb_substr($status, 0, 250), 'checked_at' => now()])->save();
        unset($this->accounts);
    }

    public function remove(string $uuid): void
    {
        $this->owner();
        $account = $this->accounts()->firstWhere('uuid', $uuid) ?? abort(404);

        if ($this->shared && ($count = Project::query()->where('git_account_id', $account->id)->count()) > 0) {
            Flux::toast(variant: 'warning', text: trans_choice(':count project is deployed with it: choose another account in its settings first.|:count projects are deployed with it: choose another account in their settings first.', $count));

            return;
        }

        $account->delete();
        unset($this->accounts);

        if (! $this->shared) {
            SyncWorkspaceCredentials::dispatch(auth()->user());
        }

        Flux::toast(text: __('Account removed from Studio. The token still works at the provider until you revoke it there.'));
    }

    private function modal(): string
    {
        return $this->shared ? 'service-git-account' : 'git-account';
    }

    public function render(): View
    {
        $deploys = $this->shared
            ? Project::query()->whereIn('git_account_id', $this->accounts()->modelKeys())->get(['git_account_id'])->countBy('git_account_id')->all()
            : [];

        $usage = [];

        if (! $this->shared) {
            foreach (ProjectGroup::query()->where('user_id', auth()->id())->whereNotNull('git_account_id')->get(['name', 'git_account_id']) as $group) {
                $usage[$group->git_account_id]['groups'][] = $group->name;
            }

            foreach (ProjectPreference::query()->where('user_id', auth()->id())->whereNotNull('git_account_id')->pluck('git_account_id')->countBy() as $id => $total) {
                $usage[$id]['projects'] = $total;
            }
        }

        return view('livewire.settings.git-accounts', [
            'usage' => $usage,
            'forges' => Forges::all(),
            'forge' => $this->forge(),
            'deploys' => $deploys,
            'modal' => $this->modal(),
        ]);
    }
}
