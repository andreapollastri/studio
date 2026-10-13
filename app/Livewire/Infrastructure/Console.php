<?php

namespace App\Livewire\Infrastructure;

use App\Infrastructure\Action;
use App\Infrastructure\Connector;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Connectors;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;
use App\Models\ConnectorAccount;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The page of one infrastructure connector: its accounts, a tab per list
 * the connector describes, the actions on each row and on the list. What is
 * read from the provider is cached for a couple of minutes; anything done
 * from here clears it. Administrators add, check and remove the accounts.
 *
 * @property-read array{rows: array<mixed>, error: string|null} $listing
 */
#[Layout('layouts::app')]
class Console extends Component
{
    /** How long a list read from a provider stays fresh, in seconds. */
    private const FRESH = 120;

    #[Locked]
    public string $connector = '';

    /** uuid of the account open now */
    #[Url]
    public ?string $account = null;

    /** key of the section open now */
    #[Url]
    public ?string $tab = null;

    /** @var array<string, string> what a child list needs from its parent row (a zone's id) */
    #[Url]
    public array $scope = [];

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $search = '';

    /** key of the action whose form is open */
    public ?string $formAction = null;

    /** id of the row it runs on; null for a list action */
    public ?string $formRow = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?string $resultTitle = null;

    public ?string $resultText = null;

    /** what to ask the provider about again, for work it runs in the background */
    public ?string $resultPoll = null;

    /** uuid of the account in the form; 'new' for a new one */
    public ?string $accountEditing = null;

    public string $account_name = '';

    /** @var array<string, mixed> */
    public array $credentials = [];

    public function mount(string $connector): void
    {
        abort_unless(Connectors::allows(auth()->user(), $connector), 403);

        $this->connector = $connector;
        $this->open();
    }

    /** The first account, and the tab and filters asked for in the address. */
    protected function open(): void
    {
        if ($this->currentAccount() === null) {
            $this->account = $this->accounts()->first()?->uuid;
        }

        $section = $this->section();

        if ($section->isChild() && $this->scope === []) {
            $this->tab = $section->parent;
        }

        $this->tab = $this->section()->key;
        $this->filters = $this->defaultFilters($this->section());
    }

    /** A connector turned off, or a role taken off it, closes the page at the next click. */
    public function hydrate(): void
    {
        abort_unless($this->allowed(), 403);
    }

    protected function allowed(): bool
    {
        return Connectors::allows(auth()->user(), $this->connector);
    }

    /** Whether this person adds, checks and removes the accounts: the administrators, for a shared connector. */
    protected function managesAccounts(): bool
    {
        return auth()->user()->isAdmin();
    }

    /** The account is the person's own (their git token): no account picker, no account management. */
    protected function personal(): bool
    {
        return false;
    }

    /** Whose the accounts are: nobody's for a shared connector, the person's for their own. */
    protected function accountOwner(): ?int
    {
        return null;
    }

    /**
     * More inputs of the account form, besides its name and the connector's fields.
     *
     * @return array<string, list<mixed>>
     */
    protected function accountRules(): array
    {
        return [];
    }

    /** What else an account keeps once its credentials work, asked with its client. */
    protected function completeAccount(ConnectorAccount $account, object $client): void {}

    /** An account was saved; $credentialsChanged when it is new or its credentials are. */
    protected function accountSaved(ConnectorAccount $account, bool $credentialsChanged): void {}

    protected function accountRemoved(ConnectorAccount $account): void {}

    protected function pageTitle(): string
    {
        return $this->definition()->label().' · '.__('Infrastructure');
    }

    #[Computed]
    public function definition(): Connector
    {
        return Connectors::make($this->connector);
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return ConnectorAccount::query()->where('connector', $this->connector)->whereNull('user_id')->orderBy('name')->get();
    }

    public function currentAccount(): ?ConnectorAccount
    {
        return $this->accounts()->firstWhere('uuid', $this->account);
    }

    public function section(): Section
    {
        $sections = $this->definition()->sections();

        return ($this->tab !== null ? $this->definition()->section($this->tab) : null) ?? $sections[0];
    }

    /**
     * What the provider is asked with: the parent row's scope and the filters.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [...$this->filters, ...$this->scope];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilters(Section $section): array
    {
        $filters = [];

        foreach ($section->filters as $field) {
            $filters[$field->name] = $field->defaultFor([]) ?? '';
        }

        return $filters;
    }

    /**
     * The open list as the provider has it, or why it could not be read.
     *
     * @return array{rows: array<mixed>, error: string|null}
     */
    #[Computed]
    public function listing(): array
    {
        $account = $this->currentAccount();
        $section = $this->section();

        if ($account === null || $section->list === null) {
            return ['rows' => [], 'error' => null];
        }

        try {
            $sealed = Cache::remember(
                $account->cacheKey('rows', $section->key, (string) json_encode($this->context())),
                self::FRESH,
                fn () => encrypt(($section->list)($account->client(), $this->context())),
            );

            $rows = decrypt($sealed);

            return ['rows' => is_array($rows) ? $rows : [], 'error' => null];
        } catch (ConnectorException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $this->unexpected($e)];
        }
    }

    /**
     * The rows that match the search box.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleRows(Section $section): array
    {
        $rows = array_values(array_filter($this->listing['rows'], 'is_array'));
        $search = mb_strtolower(trim($this->search));

        if ($search === '' || $section->kind !== 'table') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($section, $search) {
            foreach ($section->columns as $column) {
                if (str_contains(mb_strtolower($column->text($row)), $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $id): ?array
    {
        $section = $this->section();

        foreach ($this->listing['rows'] as $row) {
            if (is_array($row) && $section->rowId($row) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The action and the row it runs on (empty for a list action); null when
     * the row is gone from the provider since the page was drawn.
     *
     * @return array{0: Action, 1: array<string, mixed>}|null
     */
    private function locate(string $key, ?string $id): ?array
    {
        $section = $this->section();

        if ($id === null) {
            return [$section->toolbarAction($key) ?? abort(404), []];
        }

        $action = $section->action($key) ?? abort(404);
        $row = $this->row($id);

        if ($row === null || ! $action->appliesTo($row)) {
            Flux::toast(variant: 'warning', text: __('That is no longer in the list. Refreshed.'));
            $this->refresh();

            return null;
        }

        return [$action, $row];
    }

    /** An answer Studio did not expect: logged, and said in plain words. */
    private function unexpected(\Throwable $e): string
    {
        report($e);

        return __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]);
    }

    public function select(string $tab): void
    {
        $section = $this->definition()->section($tab);

        if ($section === null || $section->isChild()) {
            return;
        }

        $this->tab = $tab;
        $this->scope = [];
        $this->search = '';
        $this->filters = $this->defaultFilters($section);
        unset($this->listing);
    }

    /** A group of tabs (EC2, Lightsail…): its first tab. */
    public function selectGroup(string $group): void
    {
        foreach ($this->definition()->sections() as $section) {
            if (! $section->isChild() && $section->group === $group) {
                $this->select($section->key);

                return;
            }
        }
    }

    public function back(): void
    {
        $this->select((string) $this->section()->parent);
    }

    public function useAccount(string $uuid): void
    {
        if ($this->accounts()->firstWhere('uuid', $uuid) === null) {
            return;
        }

        $this->account = $uuid;

        // A zone or an app of one account means nothing on another.
        if ($this->section()->isChild()) {
            $this->back();
        }

        unset($this->listing);
    }

    public function updatedFilters(): void
    {
        unset($this->listing);
    }

    public function refresh(): void
    {
        $this->currentAccount()?->forget();
        unset($this->listing);
    }

    /** A click on an action: open its form, or a child list, or run it. */
    public function start(string $key, ?string $id = null): void
    {
        if (($located = $this->locate($key, $id)) === null) {
            return;
        }

        [$action, $row] = $located;

        if ($action->opens !== null && $action->scope !== null) {
            $this->tab = $action->opens;
            $this->scope = array_map('strval', ($action->scope)($row));
            $this->search = '';
            $this->filters = $this->defaultFilters($this->section());
            unset($this->listing);

            return;
        }

        if (! $action->hasForm()) {
            $this->execute($action, $row, []);

            return;
        }

        $this->formAction = $key;
        $this->formRow = $id;
        $this->form = [];

        foreach ($action->fields as $field) {
            $default = $field->defaultFor($row);
            $this->form[$field->name] = match ($field->type) {
                'multiselect' => array_map('strval', (array) $default),
                'checkbox' => (bool) $default,
                default => is_scalar($default) ? (string) $default : '',
            };
        }

        $this->resetValidation();
        Flux::modal('infrastructure-action')->show();
    }

    public function submit(): void
    {
        if ($this->formAction === null) {
            return;
        }

        if (($located = $this->locate($this->formAction, $this->formRow)) === null) {
            Flux::modal('infrastructure-action')->close();

            return;
        }

        [$action, $row] = $located;

        $this->validate(
            collect($action->fields)->mapWithKeys(fn (Field $field) => ['form.'.$field->name => $field->validation()])->all(),
            [],
            collect($action->fields)->mapWithKeys(fn (Field $field) => ['form.'.$field->name => mb_strtolower($field->label)])->all(),
        );

        $data = [];

        foreach ($action->fields as $field) {
            $data[$field->name] = $field->cast($this->form[$field->name] ?? null);
        }

        if ($this->execute($action, $row, $data)) {
            Flux::modal('infrastructure-action')->close();
            $this->reset('formAction', 'formRow', 'form');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $data
     */
    private function execute(Action $action, array $row, array $data): bool
    {
        $account = $this->currentAccount();

        if ($account === null || $action->run === null) {
            return false;
        }

        $section = $this->section();

        Log::info('infrastructure', [
            'user' => auth()->id(),
            'connector' => $this->connector,
            'account' => $account->uuid,
            'section' => $section->key,
            'action' => $action->key,
            'row' => $row === [] ? null : $section->rowId($row),
        ]);

        try {
            $outcome = Outcome::from(($action->run)($account->client(), $row, $data, $this->context()));
        } catch (ConnectorException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $this->unexpected($e));

            return false;
        } finally {
            $account->forget();
            unset($this->listing);
        }

        $this->present($outcome);

        return true;
    }

    private function present(Outcome $outcome): void
    {
        if ($outcome->message !== null) {
            Flux::toast(variant: 'success', text: $outcome->message);
        }

        if ($outcome->data === null && $outcome->poll === null) {
            return;
        }

        $this->resultTitle = $outcome->title ?? __('Result');
        $this->resultText = match (true) {
            $outcome->data === null => '',
            is_string($outcome->data) => $outcome->data,
            is_scalar($outcome->data) => (string) $outcome->data,
            default => (string) json_encode($outcome->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
        $this->resultPoll = $outcome->poll;

        Flux::modal('infrastructure-result')->show();
    }

    /** Ask again about work the provider runs in the background. */
    public function poll(): void
    {
        $account = $this->currentAccount();

        if ($account === null || $this->resultPoll === null) {
            return;
        }

        try {
            $outcome = $this->definition()->poll($account->client(), $this->resultPoll);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e instanceof ConnectorException ? $e->getMessage() : $this->unexpected($e));

            return;
        }

        if ($outcome->poll === null) {
            $account->forget();
            unset($this->listing);
        }

        $this->present($outcome);
    }

    /**
     * The choices of a select in the open form, from the provider when they come from there.
     *
     * @return array{options: array<string, string>, error: string|null}
     */
    public function options(Field $field): array
    {
        if (is_array($field->options)) {
            return ['options' => $field->options, 'error' => null];
        }

        $account = $this->currentAccount();

        if ($field->options === null || $account === null) {
            return ['options' => [], 'error' => null];
        }

        try {
            $sealed = Cache::remember(
                $account->cacheKey('options', $this->section()->key, (string) $this->formAction, $field->name, (string) json_encode($this->context())),
                self::FRESH * 5,
                fn () => encrypt(($field->options)($account->client(), $this->context())),
            );
            $options = decrypt($sealed);

            return ['options' => is_array($options) ? array_map('strval', $options) : [], 'error' => null];
        } catch (ConnectorException $e) {
            return ['options' => [], 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['options' => [], 'error' => $this->unexpected($e)];
        }
    }

    /* ---------------------------------------------------------------- accounts */

    public function manageAccounts(): void
    {
        abort_unless($this->managesAccounts(), 403);

        $this->accountEditing = $this->accounts()->isEmpty() ? 'new' : null;
        $this->fillAccountForm(null);
        Flux::modal('infrastructure-accounts')->show();
    }

    public function editAccount(?string $uuid = null): void
    {
        abort_unless($this->managesAccounts(), 403);

        $account = $uuid !== null ? $this->accounts()->firstWhere('uuid', $uuid) : null;
        $this->accountEditing = $account->uuid ?? 'new';
        $this->fillAccountForm($account);
    }

    public function cancelAccount(): void
    {
        $this->accountEditing = null;
        $this->resetValidation();
    }

    protected function fillAccountForm(?ConnectorAccount $account): void
    {
        $this->account_name = $account->name ?? '';
        $this->credentials = [];

        foreach ($this->definition()->fields() as $field) {
            $stored = $account?->credential($field->name);
            $this->credentials[$field->name] = $field->secret ? '' : (is_scalar($stored) ? (string) $stored : (is_scalar($default = $field->defaultFor([])) ? (string) $default : ''));
        }

        $this->resetValidation();
    }

    public function saveAccount(): void
    {
        abort_unless($this->managesAccounts(), 403);

        $account = $this->accountEditing !== 'new' ? $this->accounts()->firstWhere('uuid', $this->accountEditing) : null;
        $fields = $this->definition()->fields();

        $this->validate([
            'account_name' => ['required', 'string', 'max:80', Rule::unique('connector_accounts', 'name')->where('connector', $this->connector)->where('user_id', $this->accountOwner())->ignore($account?->id)],
            ...$this->accountRules(),
            ...collect($fields)->mapWithKeys(fn (Field $field) => ['credentials.'.$field->name => $field->validation(keepsStoredSecret: $account !== null)])->all(),
        ], [], [
            'account_name' => __('name'),
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

        $account ??= new ConnectorAccount(['connector' => $this->connector, 'user_id' => $this->accountOwner()]);

        try {
            $client = $this->definition()->client($credentials);
            $status = $this->definition()->verify($client);
            $this->completeAccount($account, $client);
        } catch (ConnectorException $e) {
            $this->addError('credentials', __('The provider refused these credentials: :reason', ['reason' => $e->getMessage()]));

            return;
        } catch (\Throwable $e) {
            $this->addError('credentials', $this->unexpected($e));

            return;
        }

        $tokenChanged = ! $account->exists || $account->credentials !== $credentials;
        $account->fill(['name' => trim($this->account_name), 'credentials' => $credentials, 'status' => $status, 'checked_at' => now()])->save();
        $this->accountSaved($account, $tokenChanged);
        $account->forget();

        unset($this->accounts, $this->listing);
        $this->account ??= $account->uuid;
        $this->accountEditing = null;

        Flux::toast(variant: 'success', text: __('Account saved: :status', ['status' => $status]));
    }

    public function testAccount(string $uuid): void
    {
        abort_unless($this->managesAccounts(), 403);

        $account = $this->accounts()->firstWhere('uuid', $uuid) ?? abort(404);

        try {
            $status = $this->definition()->verify($account->client());
            Flux::toast(variant: 'success', text: $status);
        } catch (\Throwable $e) {
            $status = $e instanceof ConnectorException ? $e->getMessage() : $this->unexpected($e);
            Flux::toast(variant: 'danger', text: $status);
        }

        $account->forceFill(['status' => mb_substr($status, 0, 250), 'checked_at' => now()])->save();
        unset($this->accounts);
    }

    public function removeAccount(string $uuid): void
    {
        abort_unless($this->managesAccounts(), 403);

        $removed = $this->accounts()->firstWhere('uuid', $uuid);
        $removed?->delete();
        unset($this->accounts, $this->listing);

        if ($removed !== null) {
            $this->accountRemoved($removed);
        }

        if ($this->account === $uuid) {
            $this->account = $this->accounts()->first()?->uuid;

            if ($this->section()->isChild()) {
                $this->back();
            }
        }

        Flux::toast(text: __('Account removed from Studio. The credentials still work at the provider until you revoke them there.'));
    }

    public function render(): View
    {
        $definition = $this->definition();
        $section = $this->section();
        $formAction = null;

        if ($this->formAction !== null) {
            $formAction = $this->formRow === null ? $section->toolbarAction($this->formAction) : $section->action($this->formAction);
        }

        $tabs = array_values(array_filter($definition->sections(), fn (Section $r) => ! $r->isChild()));
        $groups = array_values(array_unique(array_filter(array_map(fn (Section $r) => $r->group, $tabs))));
        $group = $section->group ?? ($section->parent !== null ? $definition->section($section->parent)?->group : null);

        return view('livewire.infrastructure.console', [
            'definition' => $definition,
            'groups' => $groups,
            'group' => $group,
            'tabs' => $groups === [] ? $tabs : array_values(array_filter($tabs, fn (Section $r) => $r->group === $group)),
            'section' => $section,
            'current' => $this->currentAccount(),
            'rows' => match (true) {
                $this->currentAccount() === null => [],
                $section->kind === 'info' => $this->listing['rows'],
                default => $this->visibleRows($section),
            },
            'error' => $this->currentAccount() !== null ? $this->listing['error'] : null,
            'parent' => $section->parent !== null ? $definition->section($section->parent) : null,
            'formFields' => $formAction->fields ?? [],
            'formLabel' => $formAction->label ?? '',
            'formConfirm' => $formAction?->confirm,
            'isAdmin' => $this->managesAccounts(),
            'personal' => $this->personal(),
        ])->title($this->pageTitle());
    }
}
