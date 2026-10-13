<?php

namespace App\Infrastructure\Cipi;

use App\Infrastructure\ApiClient;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Connectors\Cipi;
use App\Mcp\McpClient;
use App\Models\ConnectorAccount;
use App\Models\ProjectResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One app on a Cipi server, through the two doors it has: the server's Cipi API (the app, its
 * deploys and rollbacks, logs, .env, disk, database backups, the server's own MCP server at
 * /mcp) and, inside the app, the Cipi agent (cipi/agent: its MCP server at /cipi/mcp with the
 * releases on disk, its anonymizer at /cipi/db). The agent's tokens are read from the app's
 * .env through the API; the administrators may type them in instead (the resource's settings).
 *
 * @see https://cipi.sh/docs/agent
 */
final class CipiApp
{
    /** @var array<string, string>|null */
    private ?array $env = null;

    /** @param  array<string, mixed>  $overrides  mcp_token, anonymizer_token, app_url */
    public function __construct(
        public readonly ApiClient $api,
        public readonly string $app,
        private readonly string $apiUrl,
        private readonly string $apiToken,
        private readonly array $overrides = [],
    ) {}

    public static function fromResource(ProjectResource $resource): self
    {
        if (! $resource->isCipiApp()) {
            throw new RuntimeException(__('Not a Cipi app.'));
        }

        return self::fromAccount($resource->account, $resource->resource_id, (array) ($resource->settings ?? []));
    }

    /** @param  array<string, mixed>  $overrides */
    public static function fromAccount(ConnectorAccount $account, string $app, array $overrides = []): self
    {
        /** @var Cipi $cipi */
        $cipi = $account->definition();

        return new self($cipi->client($account->credentials ?? []), $app, Cipi::address($account->credential('url')), (string) $account->credential('token', ''), $overrides);
    }

    private function path(string $path = ''): string
    {
        return '/apps/'.rawurlencode($this->app).$path;
    }

    // ------------------------------------------------------------------ the Cipi API

    /** @return array<string, mixed> */
    public function details(): array
    {
        return (array) ($this->api->get($this->path())['data'] ?? []);
    }

    /** @return array<string, mixed> the server: system, resources, services, php */
    public function server(): array
    {
        return (array) ($this->api->get('/status')['data'] ?? []);
    }

    /**
     * The server's disk and this app's share of it (files, database, its limit); null on a
     * Cipi older than 5.5.2, which has no /disk.
     *
     * @return array{disk: array<string, mixed>, app: array<string, mixed>|null}|null
     */
    public function disk(): ?array
    {
        try {
            $data = (array) ($this->api->get('/disk')['data'] ?? []);
        } catch (ConnectorException) {
            return null;
        }

        $app = collect((array) ($data['apps'] ?? []))->first(fn ($row) => is_array($row) && ($row['app'] ?? null) === $this->app);

        return ['disk' => (array) ($data['disk'] ?? []), 'app' => is_array($app) ? $app : null];
    }

    /** @return array<string, string> the app's .env */
    public function env(): array
    {
        if ($this->env === null) {
            $data = $this->api->get($this->path('/env'))['data'] ?? [];
            $vars = is_array($data) && is_array($data['vars'] ?? null) ? $data['vars'] : $data;
            $this->env = collect(is_array($vars) ? $vars : [])->filter(fn ($v, $k) => is_string($k) && is_scalar($v))->map(fn ($v) => (string) $v)->all();
        }

        return $this->env;
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $unset
     */
    public function setEnv(array $set, array $unset = []): void
    {
        $this->api->put($this->path('/env'), array_filter(['set' => $set === [] ? null : $set, 'unset' => $unset === [] ? null : $unset]));
        $this->env = null;
    }

    /**
     * One page of a log, newest first: the files it read, each with its lines.
     *
     * @return array{files: list<array{path: string, lines: list<string>, page: int, total_pages: int, total_lines: int}>, available_types: list<string>}
     */
    public function logs(string $type = 'laravel', int $page = 1, int $perPage = 200): array
    {
        $data = (array) ($this->api->get($this->path('/logs'), ['type' => $type, 'page' => max(1, $page), 'per_page' => max(1, min(1000, $perPage))])['data'] ?? []);
        $files = [];

        foreach ((array) ($data['files'] ?? []) as $file) {
            if (! is_array($file)) {
                continue;
            }

            $files[] = [
                'path' => (string) ($file['path'] ?? $file['file'] ?? ''),
                'lines' => array_values(array_map(fn ($line) => is_scalar($line) ? (string) $line : (string) json_encode($line), (array) ($file['lines'] ?? []))),
                'page' => (int) ($file['page'] ?? $page),
                'total_pages' => (int) ($file['total_pages'] ?? 1),
                'total_lines' => (int) ($file['total_lines'] ?? 0),
            ];
        }

        return ['files' => $files, 'available_types' => array_values(array_map('strval', (array) ($data['available_types'] ?? ['all', 'laravel', 'nginx', 'php', 'worker', 'deploy'])))];
    }

    /**
     * The deploy ledger, newest first: published, failed and rollback events with their release and commit.
     *
     * @return list<array<string, mixed>>
     */
    public function audit(int $days = 90): array
    {
        $records = (array) data_get($this->api->get($this->path('/deploy/audit'), ['days' => $days]), 'data.records', []);

        return array_values(array_reverse(array_filter($records, 'is_array')));
    }

    /** Deploy, roll back to the previous release, unlock a stuck deploy, back up the database: a job id to poll. */
    public function deploy(): string
    {
        return $this->job('POST', $this->path('/deploy'));
    }

    public function rollback(): string
    {
        return $this->job('POST', $this->path('/deploy/rollback'));
    }

    public function unlock(): string
    {
        return $this->job('POST', $this->path('/deploy/unlock'));
    }

    public function backupDatabase(): string
    {
        return $this->job('POST', '/dbs/'.rawurlencode($this->app).'/backup');
    }

    public function artisan(string $command): string
    {
        return $this->job('POST', $this->path('/artisan'), ['command' => $command]);
    }

    /** @param  array<string, mixed>  $body */
    private function job(string $method, string $path, array $body = []): string
    {
        $answer = $this->api->send($method, $path, $body === [] ? [] : ['json' => $body]);
        $id = $answer['job_id'] ?? null;

        return is_string($id) ? $id : throw new ConnectorException(__('Cipi did not start a job.'));
    }

    /**
     * A job of the server: pending, running, completed or failed, with its result and output.
     *
     * @return array{status: string, type: string, result: mixed, output: string}
     */
    public function jobStatus(string $id): array
    {
        $job = (array) ($this->api->get('/jobs/'.rawurlencode($id))['data'] ?? []);

        return [
            'status' => (string) ($job['status'] ?? 'pending'),
            'type' => (string) ($job['type'] ?? ''),
            'result' => $job['result'] ?? null,
            'output' => Str::limit((string) ($job['output'] ?? ''), 20000),
        ];
    }

    // ------------------------------------------------------------------ the agent in the app

    /** Where the app answers: its APP_URL, else https:// and its domain. */
    public function appUrl(): string
    {
        $override = trim((string) ($this->overrides['app_url'] ?? ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        $url = trim((string) ($this->envValue('APP_URL') ?? ''));

        if ($url !== '' && preg_match('#^https?://#', $url) && ! str_contains($url, 'localhost')) {
            return rtrim($url, '/');
        }

        return 'https://'.($this->details()['domain'] ?? $this->app);
    }

    private function agentBase(): string
    {
        return $this->appUrl().'/'.trim($this->envValue('CIPI_ROUTE_PREFIX') ?: 'cipi', '/');
    }

    /**
     * The agent's MCP server: on when CIPI_MCP is, with CIPI_MCP_TOKEN (or the token typed in).
     *
     * @return array{installed: bool, enabled: bool, url: string, token: string|null, reason: string|null}
     */
    public function agent(): array
    {
        $env = $this->safeEnv();
        $installed = $env !== null && (array_key_exists('CIPI_WEBHOOK_TOKEN', $env) || array_key_exists('CIPI_MCP', $env) || array_key_exists('CIPI_APP_USER', $env));
        $enabled = self::truthy($env['CIPI_MCP'] ?? null);
        $token = $this->secret('mcp_token', 'CIPI_MCP_TOKEN');
        $url = $this->agentBase().'/mcp';

        return [
            'installed' => $installed,
            'enabled' => $enabled || filled($this->overrides['mcp_token'] ?? null),
            'url' => $url,
            'token' => $token,
            'reason' => match (true) {
                $env === null => __('The app\'s .env could not be read: the API token needs the apps-env ability.'),
                ! $installed => __('The Cipi agent is not in the app: composer require cipi/agent.'),
                ! $enabled && ! filled($this->overrides['mcp_token'] ?? null) => __('Its MCP server is off: php artisan cipi:service mcp --enable, then cipi:generate-token mcp.'),
                $token === null => __('No MCP token: php artisan cipi:generate-token mcp, or type it in.'),
                default => null,
            },
        ];
    }

    /** The agent's MCP server, ready to call; null while it is off. */
    public function mcp(): ?McpClient
    {
        $agent = $this->agent();

        return $agent['enabled'] && $agent['token'] !== null ? new McpClient($agent['url'], ['Authorization' => 'Bearer '.$agent['token']], 120) : null;
    }

    /**
     * The server's own MCP server (every app of the server, 73 tools): the API token, with the mcp-access ability.
     *
     * @return array{type: string, url: string, headers: array<string, string>}
     */
    public function serverMcp(): array
    {
        return ['type' => 'http', 'url' => $this->apiUrl.'/mcp', 'headers' => ['Authorization' => 'Bearer '.$this->apiToken]];
    }

    /**
     * The releases on disk, newest first, from the agent's deploy_status; null without the agent.
     *
     * @return array{current: string|null, commit: string|null, branch: string|null, pending: bool, in_progress: bool, releases: list<array{name: string, commit: string|null, current: bool, created_at: string|null}>, log: string|null}|null
     */
    public function releases(int $count = 20, int $logLines = 0): ?array
    {
        $mcp = $this->mcp();

        if ($mcp === null) {
            return null;
        }

        $status = (array) $mcp->call('deploy_status', ['releases' => max(1, min(50, $count)), 'log_lines' => max(0, min(300, $logLines))]);
        $releases = [];

        foreach ((array) ($status['releases'] ?? []) as $release) {
            if (is_array($release)) {
                $releases[] = [
                    'name' => (string) ($release['name'] ?? ''),
                    'commit' => isset($release['commit']) ? (string) $release['commit'] : null,
                    'current' => (bool) ($release['current'] ?? false),
                    'created_at' => isset($release['created_at']) ? (string) $release['created_at'] : null,
                ];
            }
        }

        $log = $status['last_deploy']['log'] ?? $status['log'] ?? null;

        return [
            'current' => isset($status['release']) ? (string) (is_array($status['release']) ? ($status['release']['name'] ?? '') : $status['release']) : null,
            'commit' => isset($status['commit']) ? (string) (is_array($status['commit']) ? ($status['commit']['sha'] ?? json_encode($status['commit'])) : $status['commit']) : null,
            'branch' => isset($status['deploy_branch']) ? (string) $status['deploy_branch'] : null,
            'pending' => (bool) ($status['pending'] ?? false),
            'in_progress' => (bool) ($status['in_progress'] ?? false),
            'releases' => $releases,
            'log' => is_array($log) ? implode("\n", array_map('strval', $log)) : (is_string($log) ? $log : null),
        ];
    }

    // ------------------------------------------------------------------ the anonymizer

    /**
     * The agent's anonymizer: an anonymized dump of the database, mailed as a link.
     *
     * @return array{enabled: bool, token: string|null, url: string, users_table: string, ttl: int, reason: string|null}
     */
    public function anonymizer(): array
    {
        $env = $this->safeEnv() ?? [];
        $enabled = self::truthy($env['CIPI_ANONYMIZER'] ?? null) || filled($this->overrides['anonymizer_token'] ?? null);
        $token = $this->secret('anonymizer_token', 'CIPI_ANONYMIZER_TOKEN');

        return [
            'enabled' => $enabled,
            'token' => $token,
            'url' => $this->agentBase().'/db',
            'users_table' => (string) ($env['CIPI_ANONYMIZER_USERS_TABLE'] ?? 'users'),
            'ttl' => (int) ($env['CIPI_ANONYMIZER_TTL'] ?? 15),
            'reason' => match (true) {
                ! $enabled => __('The anonymizer is off: php artisan cipi:service anonymize --enable, then cipi:generate-token anonymize.'),
                $token === null => __('No anonymizer token: php artisan cipi:generate-token anonymize, or type it in.'),
                default => null,
            },
        ];
    }

    /**
     * Ask the agent for an anonymized dump: it queues the job and mails a download link, valid
     * for CIPI_ANONYMIZER_TTL minutes, to that address. Nothing comes back but "queued".
     *
     * @return array<string, mixed>
     */
    public function requestAnonymizedDump(string $email, bool $gzip = true): array
    {
        $anonymizer = $this->anonymizer();

        if ($anonymizer['reason'] !== null) {
            throw new ConnectorException($anonymizer['reason']);
        }

        try {
            $response = Http::withToken((string) $anonymizer['token'])->acceptJson()->connectTimeout(10)->timeout(60)
                ->post($anonymizer['url'], ['email' => $email, 'gzip' => $gzip]);
        } catch (ConnectionException $e) {
            throw new ConnectorException(__('The app is not reachable: :reason', ['reason' => Str::limit($e->getMessage(), 200)]), previous: $e);
        }

        if (! $response->successful()) {
            $error = $response->json('message') ?? $response->json('error') ?? Str::limit($response->body(), 300);
            $details = $response->json('details');

            throw new ConnectorException(trim(__('The anonymizer answered :status: :error', ['status' => $response->status(), 'error' => is_string($error) ? $error : json_encode($error)]).(is_array($details) ? ' '.Str::limit((string) json_encode($details, JSON_UNESCAPED_SLASHES), 400) : '')));
        }

        return (array) $response->json();
    }

    /**
     * The anonymizer tool of the agent: audit (what the config covers and misses), config (the
     * file in use), scan (a config proposed from the live schema), transformations (the rules).
     */
    public function anonymizerTool(string $action, array $arguments = []): mixed
    {
        $mcp = $this->mcp() ?? throw new ConnectorException($this->agent()['reason'] ?? __('The agent\'s MCP server is off.'));

        return $mcp->call('anonymizer', ['action' => $action, ...$arguments]);
    }

    /** Write anonymization.json on the server from the live schema (cipi:init-anonymize --scan): a job id. */
    public function generateAnonymizerConfig(bool $force = false): string
    {
        return $this->artisan('cipi:init-anonymize --scan'.($force ? ' --force' : ''));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, string>|null */
    private function safeEnv(): ?array
    {
        try {
            return $this->env();
        } catch (ConnectorException) {
            return null;
        }
    }

    private function envValue(string $key): ?string
    {
        $value = ($this->safeEnv() ?? [])[$key] ?? null;

        return $value === null || self::redacted($value) ? null : $value;
    }

    /** A token typed in by the administrators wins over the .env's, which the API may hide. */
    private function secret(string $override, string $env): ?string
    {
        $typed = trim((string) ($this->overrides[$override] ?? ''));

        return $typed !== '' ? $typed : $this->envValue($env);
    }

    public static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value, " \"'")), ['1', 'true', 'on', 'yes'], true);
    }

    /** What the API shows instead of a secret: ****, [hidden], ••• */
    public static function redacted(string $value): bool
    {
        $value = trim($value, " \"'");

        return $value === '' || preg_match('/^(\*+|•+|\[(hidden|redacted)\]|<redacted>|redacted|hidden)$/i', $value) === 1;
    }
}
