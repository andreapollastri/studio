<?php

namespace App\Livewire\Projects;

use App\Infrastructure\Cipi\Anonymization;
use App\Infrastructure\Cipi\CipiApp;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Summary;
use App\Mcp\NativeServers;
use App\Models\ProjectResource;
use App\Models\SshConnection;
use App\Models\SshKey;
use App\Ssh\RemoteFiles;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A Cipi app attached to a project, as one place: what runs now and the releases before it,
 * deploy and roll back, the logs, the .env, the server's load and the app's disk, the database
 * (a backup on the server, an anonymized copy for developers and the anonymizer's config), the
 * Cipi agent's MCP servers the project's chats get, and every other action of the connector.
 * Each part shows to whom the resource's actions allow it; administrators see everything.
 *
 * @property-read array{enabled: bool, token: string|null, url: string, users_table: string, ttl: int, reason: string|null} $anonymizer
 * @property-read Collection<int, SshConnection> $sshConnections
 * @property-read Collection<int, SshKey> $systemKeys
 * @property-read array<string, mixed> $details
 * @property-read array<string, string> $env
 */
class CipiAppHub extends Component
{
    public const TABS = ['deploys', 'logs', 'env', 'database', 'agent', 'actions'];

    #[Locked]
    public string $resource = '';

    #[Url(as: 'view')]
    public string $tab = 'deploys';

    /** @var array{id: string, label: string, status: string, output: string, result: mixed}|null the job of the server being watched */
    public ?array $job = null;

    // ---- logs

    public string $logType = 'laravel';

    public int $logPage = 1;

    public int $logLines = 200;

    public string $logSearch = '';

    public bool $logFollow = false;

    // ---- .env

    public string $envSet = '';

    public string $envUnset = '';

    // ---- database

    public string $anonymizeEmail = '';

    public bool $anonymizeGzip = true;

    /** @var list<array{table: string, column: string, rule: string, unique: bool, consistent: bool}> */
    public array $anonRules = [];

    public string $anonTruncate = '';

    public string $anonLocale = 'en_US';

    public string $anonPassword = 'password';

    public bool $anonConsistent = true;

    public bool $anonLoaded = false;

    /** Where the editor's rules came from: server, scan, starter. */
    public string $anonSource = '';

    /** uuid of the SSH connection the file is written with */
    public string $anonConnection = '';

    public ?string $anonAudit = null;

    // ---- agent (administrators)

    public bool $mcpApp = true;

    public bool $mcpServer = false;

    public string $mcpToken = '';

    public string $anonymizerToken = '';

    public string $appUrl = '';

    // ---- SSH access (administrators)

    public string $sshAuth = 'password';

    public string $sshPassword = '';

    public string $sshKey = '';

    public function mount(string $resource): void
    {
        $this->resource = $resource;
        $item = $this->item();

        abort_unless($item->isVisibleTo(auth()->user()) && $item->isCipiApp(), 403);

        $this->tab = in_array($this->tab, $this->tabs(), true) ? $this->tab : 'deploys';
        $this->anonymizeEmail = (string) auth()->user()->email;
        $this->mcpApp = (bool) $item->setting('mcp_app', true);
        $this->mcpServer = (bool) $item->setting('mcp_server', false);
        $this->appUrl = (string) $item->setting('app_url', '');
    }

    protected function item(): ProjectResource
    {
        return once(fn () => ProjectResource::query()->with(['account', 'project'])->where('uuid', $this->resource)->firstOrFail());
    }

    protected function app(): CipiApp
    {
        return once(fn () => CipiApp::fromResource($this->item()));
    }

    /** May this person run that action of the resource? */
    public function can(string $action): bool
    {
        return $this->item()->allows(auth()->user(), $action);
    }

    public function isAdmin(): bool
    {
        return auth()->user()->isAdmin();
    }

    /** @return list<string> */
    public function tabs(): array
    {
        return array_values(array_filter(self::TABS, fn (string $tab) => match ($tab) {
            'logs' => $this->can('logs'),
            'env' => $this->can('env') || $this->can('env-set'),
            'database' => $this->can('db-backup') || $this->can('anonymize') || $this->can('anonymizer-config') || $this->can('anonymizer-audit'),
            default => true,
        }));
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'deploys';
    }

    /** Something read from the server, or why it could not be. */
    private function read(callable $read, mixed $fallback = null): mixed
    {
        try {
            return $read();
        } catch (ConnectorException $e) {
            return $fallback ?? ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return $fallback ?? ['error' => __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()])];
        }
    }

    // ------------------------------------------------------------------ deploys

    /** @return array<string, mixed> */
    #[Computed]
    public function details(): array
    {
        return $this->read(fn () => Cache::remember($this->item()->account->cacheKey('cipi-app', $this->item()->resource_id), 30, fn () => $this->app()->details()));
    }

    /** @return array<string, mixed> the card of the overview: load, disk, agent */
    #[Computed]
    public function summary(): array
    {
        return Summary::of($this->item());
    }

    /** @return array<string, mixed>|null the releases on disk, from the agent; null without it */
    #[Computed]
    public function releases(): ?array
    {
        if (! ($this->can('releases') || $this->can('audit') || $this->can('deploy') || $this->can('rollback'))) {
            return null;
        }

        return $this->read(fn () => $this->app()->releases(15), ['error' => __('The agent did not answer.')]);
    }

    /** @return list<array<string, mixed>>|array{error: string} */
    #[Computed]
    public function audit(): array
    {
        if (! ($this->can('audit') || $this->can('releases') || $this->can('deploy') || $this->can('rollback'))) {
            return [];
        }

        return $this->read(fn () => array_slice($this->app()->audit(90), 0, 30));
    }

    public function deploy(): void
    {
        $this->startJob('deploy', __('Deploy'), fn () => $this->app()->deploy());
    }

    public function rollback(): void
    {
        $this->startJob('rollback', __('Roll back to the previous release'), fn () => $this->app()->rollback());
    }

    public function unlock(): void
    {
        $this->startJob('unlock', __('Unlock the deploy'), fn () => $this->app()->unlock());
    }

    public function backupDatabase(): void
    {
        $this->startJob('db-backup', __('Database backup'), fn () => $this->app()->backupDatabase());
    }

    public function generateAnonymizerConfig(): void
    {
        $this->startJob('anonymizer-config', __('anonymization.json from the schema'), fn () => $this->app()->generateAnonymizerConfig(true));
    }

    /** @param  callable(): string  $start */
    private function startJob(string $action, string $label, callable $start): void
    {
        abort_unless($this->can($action), 403);

        if ($this->job !== null && in_array($this->job['status'], ['pending', 'running'], true)) {
            Flux::toast(variant: 'warning', text: __(':label is still running on the server.', ['label' => $this->job['label']]));

            return;
        }

        try {
            $id = $start();
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e instanceof ConnectorException ? $e->getMessage() : __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => 'cipi', 'account' => $this->item()->account->uuid, 'section' => 'apps', 'action' => $action, 'row' => $this->item()->resource_id, 'project_resource' => $this->item()->uuid]);
        $this->job = ['id' => $id, 'label' => $label, 'status' => 'pending', 'output' => '', 'result' => null];
        $this->forget();
    }

    public function pollJob(): void
    {
        if ($this->job === null) {
            return;
        }

        $status = $this->read(fn () => $this->app()->jobStatus($this->job['id']));

        if (isset($status['error'])) {
            return;
        }

        $this->job = ['id' => $this->job['id'], 'label' => $this->job['label'], 'status' => $status['status'], 'output' => $status['output'], 'result' => $status['result']];

        if (in_array($status['status'], ['completed', 'failed'], true)) {
            $this->forget();
            Flux::toast(variant: $status['status'] === 'completed' ? 'success' : 'danger', text: $this->job['label'].': '.($status['status'] === 'completed' ? __('done.') : __('failed on the server.')));
        }
    }

    public function closeJob(): void
    {
        $this->job = null;
    }

    private function forget(): void
    {
        $this->item()->account->forget();
        NativeServers::forget($this->item());
        unset($this->details, $this->summary, $this->releases, $this->audit);
    }

    // ------------------------------------------------------------------ logs

    /** @return array<string, mixed> */
    #[Computed]
    public function logs(): array
    {
        if ($this->tab !== 'logs' || ! $this->can('logs')) {
            return ['files' => [], 'available_types' => []];
        }

        $logs = $this->read(fn () => $this->app()->logs($this->logType, $this->logPage, $this->logLines));
        $search = mb_strtolower(trim($this->logSearch));

        if ($search !== '' && isset($logs['files'])) {
            foreach ($logs['files'] as $i => $file) {
                $logs['files'][$i]['lines'] = array_values(array_filter($file['lines'], fn (string $line) => str_contains(mb_strtolower($line), $search)));
            }
        }

        return $logs;
    }

    public function updatedLogType(): void
    {
        $this->logPage = 1;
    }

    public function olderLogs(): void
    {
        $this->logPage++;
    }

    public function newerLogs(): void
    {
        $this->logPage = max(1, $this->logPage - 1);
    }

    // ------------------------------------------------------------------ .env

    /** @return array<string, string>|array{error: string} */
    #[Computed]
    public function env(): array
    {
        if ($this->tab !== 'env' || ! $this->can('env')) {
            return [];
        }

        return $this->read(fn () => $this->app()->env());
    }

    public static function isSecret(string $key): bool
    {
        return (bool) preg_match('/(KEY|SECRET|PASSWORD|PASS|TOKEN|DSN|CREDENTIALS|PRIVATE)/i', $key);
    }

    /** Put one variable in the form, to change it. */
    public function editEnv(string $key): void
    {
        abort_unless($this->can('env-set'), 403);

        $value = $this->can('env') ? (string) ($this->env[$key] ?? '') : '';
        $line = $key.'='.$value;
        $this->envSet = trim($this->envSet) === '' ? $line : rtrim($this->envSet)."\n".$line;
    }

    public function saveEnv(): void
    {
        abort_unless($this->can('env-set'), 403);

        $set = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->envSet) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (! str_contains($line, '=') || ! preg_match('/^[A-Z_][A-Z0-9_]*$/i', trim(explode('=', $line, 2)[0]))) {
                $this->addError('envSet', __('":line" is not KEY=value.', ['line' => Str::limit($line, 40)]));

                return;
            }

            [$key, $value] = explode('=', $line, 2);
            $set[trim($key)] = trim($value);
        }

        $unset = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $this->envUnset) ?: [])));

        if ($set === [] && $unset === []) {
            return;
        }

        try {
            $this->app()->setEnv($set, $unset);
        } catch (ConnectorException $e) {
            $this->addError('envSet', $e->getMessage());

            return;
        }

        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => 'cipi', 'action' => 'env-set', 'row' => $this->item()->resource_id, 'keys' => [...array_keys($set), ...$unset], 'project_resource' => $this->item()->uuid]);
        $this->reset('envSet', 'envUnset');
        $this->forget();
        unset($this->env);
        Flux::toast(variant: 'success', text: __('.env saved: deploy, or clear the config cache, to apply it.'));
    }

    // ------------------------------------------------------------------ database

    /** @return array{enabled: bool, token: string|null, url: string, users_table: string, ttl: int, reason: string|null} */
    #[Computed]
    public function anonymizer(): array
    {
        return $this->read(fn () => $this->app()->anonymizer(), ['enabled' => false, 'token' => null, 'url' => '', 'users_table' => 'users', 'ttl' => 15, 'reason' => __('The app could not be read.')]);
    }

    public function requestAnonymizedDump(): void
    {
        abort_unless($this->can('anonymize'), 403);

        $this->validate(['anonymizeEmail' => ['required', 'email']]);

        try {
            $this->app()->requestAnonymizedDump($this->anonymizeEmail, $this->anonymizeGzip);
        } catch (ConnectorException $e) {
            $this->addError('anonymizeEmail', $e->getMessage());

            return;
        }

        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => 'cipi', 'action' => 'anonymize', 'row' => $this->item()->resource_id, 'email' => $this->anonymizeEmail, 'project_resource' => $this->item()->uuid]);
        Flux::toast(variant: 'success', duration: 10000, text: __('Queued in the app: a link to the anonymized dump reaches :email within minutes, valid :ttl minutes.', ['email' => $this->anonymizeEmail, 'ttl' => $this->anonymizer['ttl']]));
    }

    /** Fill the editor: the file on the server, a proposal from the live schema, or a start for a Laravel app. */
    public function loadAnonymizer(string $source): void
    {
        abort_unless($this->can('anonymizer-config') || $this->can('anonymizer-audit'), 403);

        $config = match ($source) {
            'server', 'scan' => $this->read(fn () => $this->app()->anonymizerTool($source === 'server' ? 'config' : 'scan'), ['error' => __('The agent did not answer.')]),
            default => null,
        };

        if (is_array($config) && isset($config['error']) && count($config) === 1) {
            Flux::toast(variant: 'danger', text: (string) $config['error']);

            return;
        }

        // the agent may wrap the file: {config: {...}} or {proposal: {...}}
        if (is_array($config)) {
            $config = $config['config'] ?? $config['proposal'] ?? $config['suggested'] ?? $config;
        }

        $parsed = $config === null ? Anonymization::starter() : Anonymization::parse($config);

        if ($source !== 'starter' && $parsed['rules'] === []) {
            Flux::toast(variant: 'warning', text: $source === 'server' ? __('No anonymization.json on the server yet: start from the schema or from a Laravel app.') : __('The scan proposed nothing.'));
            $parsed = $source === 'server' ? Anonymization::starter() : $parsed;
        }

        $this->anonRules = $parsed['rules'];
        $this->anonTruncate = implode(', ', $parsed['truncate']);
        $this->anonLocale = (string) ($parsed['options']['faker_locale'] ?? 'en_US');
        $this->anonPassword = (string) ($parsed['options']['password'] ?? 'password');
        $this->anonConsistent = (bool) ($parsed['options']['consistent'] ?? true);
        $this->anonLoaded = true;
        $this->anonSource = $source;
    }

    public function addAnonRule(): void
    {
        $last = end($this->anonRules) ?: null;
        $this->anonRules[] = ['table' => $last['table'] ?? '', 'column' => '', 'rule' => 'fakeName', 'unique' => false, 'consistent' => false];
    }

    public function removeAnonRule(int $index): void
    {
        array_splice($this->anonRules, $index, 1);
    }

    public function anonymizationJson(): string
    {
        return Anonymization::build($this->anonRules, array_map('trim', explode(',', $this->anonTruncate)), [
            'faker_locale' => $this->anonLocale,
            'password' => $this->anonPassword,
            'consistent' => $this->anonConsistent,
        ]);
    }

    private function validateAnonymizer(): bool
    {
        $this->validate([
            'anonRules' => ['array', 'max:500'],
            'anonRules.*.table' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'anonRules.*.column' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'anonRules.*.rule' => ['required', 'string', 'max:120'],
            'anonLocale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}_[A-Z]{2}$/'],
            'anonPassword' => ['required', 'string', 'max:120'],
        ], [], ['anonRules.*.table' => __('table'), 'anonRules.*.column' => __('column'), 'anonLocale' => __('locale')]);

        return true;
    }

    public function downloadAnonymizer(): StreamedResponse
    {
        abort_unless($this->can('anonymizer-config') || $this->can('anonymizer-audit'), 403);
        $this->validateAnonymizer();
        $json = $this->anonymizationJson();

        return response()->streamDownload(fn () => print ($json), 'anonymization.json', ['Content-Type' => 'application/json']);
    }

    /** Write the file where the agent reads it, ~/.db/anonymization.json, over SFTP as the app's user. */
    public function saveAnonymizer(RemoteFiles $files): void
    {
        abort_unless($this->can('anonymizer-config'), 403);
        $this->validateAnonymizer();

        $connection = $this->sshConnections->firstWhere('uuid', $this->anonConnection);

        if ($connection === null) {
            $this->addError('anonConnection', __('Pick the SSH connection of the app\'s user.'));

            return;
        }

        try {
            $files->put($connection, auth()->user(), Anonymization::PATH, $this->anonymizationJson(), 0640);
        } catch (ConnectorException $e) {
            $this->addError('anonConnection', $e->getMessage());

            return;
        }

        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => 'cipi', 'action' => 'anonymizer-save', 'row' => $this->item()->resource_id, 'connection' => $connection->uuid, 'project_resource' => $this->item()->uuid]);
        Flux::toast(variant: 'success', text: __('~/:path written on :host. Check it with the audit.', ['path' => Anonymization::PATH, 'host' => $connection->host]));
    }

    public function auditAnonymizer(): void
    {
        abort_unless($this->can('anonymizer-audit') || $this->can('anonymizer-config'), 403);

        $audit = $this->read(fn () => $this->app()->anonymizerTool('audit'), ['error' => __('The agent did not answer.')]);
        $this->anonAudit = is_string($audit) ? $audit : (string) json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The project's SSH connections this person can open: the one of the app's user first.
     *
     * @return Collection<int, SshConnection>
     */
    #[Computed]
    public function sshConnections(): Collection
    {
        $user = auth()->user();
        $app = $this->item()->resource_id;

        return SshConnection::query()->visibleTo($user)->where('project_id', $this->item()->project_id)->with('key')->get()
            ->filter(fn (SshConnection $c) => $c->canConnect($user))
            ->sortByDesc(fn (SshConnection $c) => $c->username === $app)
            ->values();
    }

    // ------------------------------------------------------------------ agent (administrators)

    /** @return array{installed: bool, enabled: bool, url: string, token: string|null, reason: string|null}|null */
    #[Computed]
    public function agent(): ?array
    {
        return NativeServers::agent($this->item());
    }

    public function saveAgent(): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->validate([
            'appUrl' => ['nullable', 'url:https,http', 'max:255'],
            'mcpToken' => ['nullable', 'string', 'max:255'],
            'anonymizerToken' => ['nullable', 'string', 'max:255'],
        ]);

        $item = $this->item();
        $settings = (array) ($item->settings ?? []);
        $settings['mcp_app'] = $this->mcpApp;
        $settings['mcp_server'] = $this->mcpServer;
        $settings['app_url'] = trim($this->appUrl) ?: null;

        // typed tokens are kept until replaced; a single dash clears them
        foreach (['mcp_token' => $this->mcpToken, 'anonymizer_token' => $this->anonymizerToken] as $key => $typed) {
            if (trim($typed) === '-') {
                unset($settings[$key]);
            } elseif (trim($typed) !== '') {
                $settings[$key] = trim($typed);
            }
        }

        $item->update(['settings' => array_filter($settings, fn ($v) => $v !== null)]);
        NativeServers::forget($item);
        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => 'cipi', 'action' => 'agent-settings', 'row' => $item->resource_id, 'mcp_app' => $this->mcpApp, 'mcp_server' => $this->mcpServer, 'project_resource' => $item->uuid]);

        $this->reset('mcpToken', 'anonymizerToken');
        unset($this->agent, $this->anonymizer);
        Flux::toast(variant: 'success', text: __('Saved: the next turn of each chat gets the servers.'));
    }

    /** Does the agent's MCP server answer, and with which tools? */
    public function testAgent(): void
    {
        NativeServers::forget($this->item());
        unset($this->agent);

        try {
            $mcp = $this->app()->mcp() ?? throw new ConnectorException($this->app()->agent()['reason'] ?? __('The agent\'s MCP server is off.'));
            $tools = $mcp->tools();
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', duration: 8000, text: __('The agent answers with :count tools: :tools', ['count' => count($tools), 'tools' => implode(', ', $tools)]));
    }

    /** @return Collection<int, SshKey> */
    #[Computed]
    public function systemKeys(): Collection
    {
        return SshKey::query()->system()->orderBy('name')->get();
    }

    /**
     * A saved SSH connection for the app's user on its server, tied to the project: its developers
     * see it on the SSH page and in the workspace's remote terminal, with a credential of their own.
     */
    public function addSshConnection(): void
    {
        abort_unless($this->isAdmin(), 403);

        $this->validate([
            'sshAuth' => ['required', Rule::in(['password', 'key'])],
            'sshPassword' => ['required_if:sshAuth,password', 'nullable', 'string', 'max:255'],
            'sshKey' => ['required_if:sshAuth,key', 'nullable', Rule::in($this->systemKeys->pluck('uuid')->all())],
        ]);

        $ip = (string) data_get($this->read(fn () => $this->app()->server(), []), 'system.ip', '');
        $host = $ip !== '' ? $ip : (string) ($this->details['domain'] ?? '');

        if ($host === '') {
            Flux::toast(variant: 'danger', text: __('The server\'s address could not be read.'));

            return;
        }

        $connection = SshConnection::query()->create([
            'user_id' => auth()->id(),
            'project_id' => $this->item()->project_id,
            'name' => $this->item()->label,
            'host' => $host,
            'port' => 22,
            'username' => $this->item()->resource_id,
            'auth' => $this->sshAuth,
            'password' => $this->sshAuth === 'password' ? $this->sshPassword : null,
            'ssh_key_id' => $this->sshAuth === 'key' ? SshKey::query()->system()->where('uuid', $this->sshKey)->value('id') : null,
        ]);

        $this->reset('sshPassword', 'sshKey');
        unset($this->sshConnections);
        Flux::toast(variant: 'success', text: __(':address saved for the project: open it once in the SSH terminal to accept its host key.', ['address' => $connection->address()]));
    }

    public function render(): View
    {
        return view('livewire.projects.cipi-app-hub', [
            'item' => $this->item(),
            'tabs' => $this->tabs(),
            'transformations' => Anonymization::TRANSFORMATIONS,
            'appName' => NativeServers::appName($this->item()),
            'serverName' => NativeServers::serverName($this->item()),
        ]);
    }
}
