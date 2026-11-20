<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Action;
use App\Infrastructure\ApiClient;
use App\Infrastructure\Cipi\CipiApp;
use App\Infrastructure\Column;
use App\Infrastructure\Connector;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;
use Illuminate\Support\Sleep;

/**
 * Cipi servers (https://cipi.sh), one account per server with the Cipi API
 * installed (`cipi api`): the server's status, its apps (create, deploy,
 * rollback, live deploy status, deploy history, releases, SSL, aliases,
 * redirects, proxies, .env, Artisan, logs, disk, cipi.yml, environment
 * label, the anonymized database copy of the Cipi agent, suspend),
 * databases (link to an app), backups, Node runtimes, services, SSH keys
 * and PHP versions. Most changes run as background jobs on the server:
 * Studio waits a few seconds, then offers to check again.
 */
final class Cipi extends Connector
{
    private const PHP = ['8.6' => '8.6', '8.5' => '8.5', '8.4' => '8.4', '8.3' => '8.3', '8.2' => '8.2', '8.1' => '8.1', '8.0' => '8.0', '7.4' => '7.4'];

    /** App environment label: `^[a-z0-9][a-z0-9_-]{0,31}$` (cipi/api 1.36+ / Cipi 5.8.0+). */
    private const ENV_RULE = 'regex:/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public function key(): string
    {
        return 'cipi';
    }

    public function label(): string
    {
        return 'Cipi';
    }

    public function description(): string
    {
        return __('Cipi servers through the Cipi API: apps, deploys and rollbacks, live deploy status, SSL, aliases, redirects, proxies, .env, Artisan, logs, databases, backups, Node, services, SSH keys and PHP, one server per account.');
    }

    public function icon(): string
    {
        return 'rocket-launch';
    }

    public function docs(): string
    {
        return 'https://cipi.sh/docs/advanced#cipi-api';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('API address'))->required()->placeholder('https://api.server.example.com')
                ->help(__('The host the Cipi API answers on: `cipi api <host>` and `cipi api ssl` on the server.')),
            Field::password('token', __('API token'))->required()
                ->help(__('`cipi api token create` on the server, with the abilities to allow (`--full-access` from Cipi 5.9 / cipi/api 1.39 for everything, including abilities later versions add). Add Studio\'s address to `cipi api ip-whitelist` if it is on.')),
        ];
    }

    /** The address of the Cipi API as typed: https://, no trailing slash. */
    public static function address(mixed $url): string
    {
        return self::instanceUrl($url);
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient(self::instanceUrl($credentials['url'] ?? '').'/api', ['Authorization' => 'Bearer '.self::string($credentials, 'token')], 60);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $system = data_get($api->get('/status'), 'data.system', []);
        $apiVersion = data_get($system, 'api');

        return 'ok · '.data_get($system, 'hostname', '').' · Cipi '.data_get($system, 'cipi', '?').($apiVersion ? ' · API '.$apiVersion : '');
    }

    public function sections(): array
    {
        return [
            $this->status(),
            $this->apps(),
            $this->aliases(),
            $this->redirects(),
            $this->proxies(),
            $this->databases(),
            $this->backups(),
            $this->node(),
            $this->services(),
            $this->sshKeys(),
            $this->php(),
        ];
    }

    public function poll(object $api, string $token): Outcome
    {
        /** @var ApiClient $api */
        $job = (array) ($api->get('/jobs/'.rawurlencode($token))['data'] ?? []);
        $status = (string) ($job['status'] ?? 'pending');
        $title = __('Job :type', ['type' => $job['type'] ?? $token]);

        return match ($status) {
            'completed' => Outcome::show($title, $this->withDatabaseUrl($api, $job), __('Completed.')),
            'failed' => Outcome::show($title, $job['result'] ?? __('Failed.'), __('The job failed on the server.')),
            default => Outcome::pending($title.' · '.$status, $token, __('Still running on the server.')),
        };
    }

    /**
     * An app just created: the database link Cipi prints (mariadb+ssh://ssh_user:ssh_password@server/user:password@127.0.0.1/database),
     * taken from the command's own output or built from the credentials when that line is missing.
     *
     * @param  array<string, mixed>  $job
     */
    private function withDatabaseUrl(ApiClient $api, array $job): mixed
    {
        $result = $job['result'] ?? null;

        if (($job['type'] ?? '') !== 'app-create' || ! is_array($result) || filled($result['database_url'] ?? null)) {
            return $result ?? __('Completed.');
        }

        $url = $this->databaseUrl($api, $result, (string) ($job['output'] ?? ''));

        return $url === null ? $result : [...$result, 'database_url' => $url];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function databaseUrl(ApiClient $api, array $result, string $output): ?string
    {
        $plain = (string) preg_replace("/\x1b\[[0-9;]*m/", '', $output);

        if (preg_match('~[a-z][a-z0-9]*\+ssh://\S+~i', $plain, $found)) {
            return rtrim($found[0], " \t.,)");
        }

        $database = (array) ($result['database'] ?? []);
        $dbUser = is_string($database['user'] ?? null) ? $database['user'] : '';
        $dbPassword = is_string($database['password'] ?? null) ? $database['password'] : '';

        if ($dbUser === '' || $dbPassword === '') {
            return null;
        }

        $ssh = (array) ($result['ssh'] ?? []);
        $sshUser = is_string($ssh['user'] ?? null) && $ssh['user'] !== '' ? $ssh['user'] : (is_string($result['app'] ?? null) ? $result['app'] : $dbUser);
        $sshPassword = is_string($ssh['password'] ?? null) && $ssh['password'] !== '' ? $ssh['password'] : null;
        $name = is_string($result['app'] ?? null) && $result['app'] !== '' ? $result['app'] : $dbUser;
        $host = null;

        if (preg_match('/IP:\s*(\S+)/', $plain, $ip)) {
            $host = $ip[1];
        }

        $host ??= $this->serverHost($api);

        if ($host === null || $sshUser === '') {
            return null;
        }

        $engine = strtolower((string) ($result['engine'] ?? ''));
        $scheme = in_array($engine, ['pgsql', 'postgres', 'postgresql'], true) || preg_match('/\b(?:postgres(?:ql)?|pgsql)\b/i', $plain) ? 'postgres' : 'mariadb';
        $sshPart = rawurlencode($sshUser).($sshPassword !== null ? ':'.rawurlencode($sshPassword) : '');

        return $scheme.'+ssh://'.$sshPart.'@'.$this->linkHost($host).'/'.rawurlencode($dbUser).':'.rawurlencode($dbPassword).'@127.0.0.1/'.rawurlencode($name);
    }

    /** The address an SSH tunnel reaches: the server's IP, or the API host when status cannot be read. */
    private function serverHost(ApiClient $api): ?string
    {
        try {
            $ip = data_get($api->get('/status'), 'data.system.ip');

            if (is_string($ip) && $ip !== '') {
                return $ip;
            }
        } catch (\Throwable) {
            // A token without status-view still gets the host of the API address.
        }

        $host = parse_url($api->baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function linkHost(string $host): string
    {
        return str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
    }

    /**
     * A change that runs as a job on the server: a few seconds to finish
     * here, else the page checks again on request.
     *
     * @param  array<string, mixed>  $data
     */
    private function job(ApiClient $api, string $method, string $path, array $data = []): Outcome
    {
        $answer = $api->send($method, $path, $data === [] ? [] : ['json' => $data]);
        $id = $answer['job_id'] ?? null;

        if (! is_string($id)) {
            return Outcome::show(__('Done'), $answer['data'] ?? $answer);
        }

        $outcome = Outcome::pending(__('Job'), $id);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            Sleep::for(1500)->milliseconds();
            $outcome = $this->poll($api, $id);

            if ($outcome->poll === null) {
                return $outcome;
            }
        }

        return $outcome;
    }

    /** A job action on an app: POST /apps/{name}/{path} with the form's data. */
    private function appJob(string $key, string $label, string $icon, string $path, string $method = 'POST'): Action
    {
        return Action::make($key, $label)->icon($icon)
            ->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, $method, '/apps/'.$row['app'].$path, array_filter($data, fn ($value) => $value !== null)));
    }

    /**
     * A change to an app's cipi.yml: a token without yml-manage, or a Cipi older than 5.6.4, says
     * so in words of its own instead of the bare 403 or 404.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function yml(callable $change): mixed
    {
        try {
            return $change();
        } catch (ConnectorException $e) {
            throw new ConnectorException(CipiApp::ymlUnavailable($e, 'yml-manage') ?? $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * A list the server may be too old for (CipiApp::newer): its answer, or a note that says what
     * it needs; a token without $ability gets a note that names it.
     *
     * @param  callable(): array<mixed>  $read
     * @return array<mixed>
     */
    private function newer(callable $read, string $ability, string $needs): array
    {
        return CipiApp::newer($read, $ability)
            ?? throw new ConnectorException(__('This server does not have it yet: it needs :needs or later (cipi self-update).', ['needs' => $needs]), 404);
    }

    private function status(): Section
    {
        return Section::info('status', __('Server'), function (ApiClient $api) {
            $status = (array) ($api->get('/status')['data'] ?? []);
            $disk = $api->get('/disk')['data'] ?? null;

            return [
                __('System') => array_filter([
                    __('Host') => data_get($status, 'system.hostname'),
                    __('IP') => data_get($status, 'system.ip'),
                    __('OS') => data_get($status, 'system.os'),
                    __('Cipi') => data_get($status, 'system.cipi'),
                    __('API') => data_get($status, 'system.api'),
                    __('Uptime') => data_get($status, 'system.uptime'),
                    __('Apps') => data_get($status, 'apps'),
                ], fn ($value) => $value !== null && $value !== ''),
                __('Resources') => [
                    __('CPU') => data_get($status, 'resources.cpu.usage_percent').'%',
                    __('Memory') => data_get($status, 'resources.memory.used_mb').' / '.data_get($status, 'resources.memory.total_mb').' MB ('.data_get($status, 'resources.memory.usage_percent').'%)',
                    __('Disk') => data_get($status, 'resources.disk.display'),
                ],
                __('Services') => (array) data_get($status, 'services', []),
                __('PHP') => collect((array) data_get($status, 'php', []))->mapWithKeys(fn ($php) => ['PHP '.$php['version'] => ($php['status'] ?? '').' · '.trans_choice(':count pool|:count pools', (int) ($php['pools'] ?? 0))])->all(),
                ...(is_array($disk) ? [__('Disk by app') => collect((array) ($disk['apps'] ?? []))->mapWithKeys(function ($app) {
                    if (! is_array($app)) {
                        return [];
                    }

                    return [(string) ($app['app'] ?? $app['name'] ?? '?') => $this->diskLine($app)];
                })->all()] : []),
            ];
        });
    }

    /**
     * One app from GET /api/disk. Cipi sends files_gb, database_gb, total_gb and
     * percent (and the KiB figures): a size under 0.01 GB is shown in MB, as
     * `cipi disk` prints it.
     *
     * @param  array<string, mixed>  $app
     */
    private function diskLine(array $app): string
    {
        $parts = [];
        $total = $this->diskSize($app['total_gb'] ?? $app['total'] ?? null, $app['total_kb'] ?? null)
            ?? (is_string($app['total_human'] ?? null) && $app['total_human'] !== '' ? $app['total_human'] : null);

        if ($total !== null) {
            $parts[] = $total;
        }

        foreach (['files' => __('files'), 'database' => __('database')] as $key => $label) {
            $size = $this->diskSize($app[$key.'_gb'] ?? null, $app[$key.'_kb'] ?? null);

            if ($size !== null) {
                $parts[] = $size.' '.$label;
            }
        }

        $percent = $app['percent'] ?? $app['share'] ?? null;

        if (is_numeric($percent)) {
            $parts[] = rtrim(rtrim(number_format((float) $percent, 1, '.', ''), '0'), '.').'%';
        }

        if (! empty($app['over_limit'])) {
            $parts[] = __('over :limit GB', ['limit' => $app['limit_gb'] ?? '?']);
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** A gigabyte figure, or megabytes when Cipi rounded it below 0.01 GB. */
    private function diskSize(mixed $gb, mixed $kb): ?string
    {
        if (is_numeric($kb) && (! is_numeric($gb) || abs((float) $gb) < 0.01)) {
            return rtrim(rtrim(number_format((float) $kb / 1024, 1, '.', ''), '0'), '.').' MB';
        }

        if (! is_numeric($gb)) {
            return is_string($gb) && $gb !== '' ? $gb : null;
        }

        return rtrim(rtrim(number_format((float) $gb, 2, '.', ''), '0'), '.').' GB';
    }

    private function apps(): Section
    {
        return Section::make('apps', __('Apps'))
            ->icon('rocket-launch')
            ->id('app')
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/apps')['data'] ?? [])))
            ->columns([
                Column::make('app', __('App'))->mono(),
                Column::make('domain', __('Domain')),
                Column::make('env', __('Env'))->mono(),
                Column::make('type', __('Type'))->badge(['laravel' => 'red', 'node' => 'green', 'custom' => 'zinc']),
                Column::make('php', __('PHP'))->mono(),
                Column::make('branch', __('Branch'))->mono(),
                Column::make('suspended', __('Suspended'))->bool(),
                Column::make('force_https', __('HTTPS'))->bool(),
            ])
            ->create(__('New app'), [
                Field::text('user', __('App user'))->required()->placeholder('myapp')->rules(['regex:/^[a-z][a-z0-9]{2,31}$/'])->help(__('Lowercase letters and digits: the Linux user, the database and the app name')),
                Field::text('domain', __('Domain'))->required()->placeholder('myapp.example.com'),
                Field::text('env', __('Environment'))->placeholder('production')->rules([self::ENV_RULE])
                    ->help(__('Label of this app\'s environment (`production`, `staging`…). From Cipi 5.8 / cipi/api 1.36: the release may carry `cipi-<env>.yml` instead of `cipi.yml`. Leave empty for production.')),
                Field::text('repository', __('Git repository (SSH)'))->placeholder('git@github.com:acme/myapp.git'),
                Field::text('branch', __('Branch'))->default('main'),
                Field::select('php', __('PHP'), ['' => __('Server default')] + self::PHP)->default('')
                    ->help(__('Server default: the PHP the server runs. PHP 8.6 needs Cipi 5.9.1.')),
                Field::select('engine', __('Database'), ['' => __('Server default'), 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL']),
                Field::checkbox('custom', __('Not Laravel (custom PHP or static)')),
                Field::text('docroot', __('Document root (custom apps)'))->placeholder('public'),
                Field::checkbox('octane', __('Laravel Octane (FrankenPHP)')),
            ], function (ApiClient $api, array $row, array $data) {
                $body = array_filter($data, fn ($value) => $value !== null && $value !== '' && $value !== false);

                if (! isset($body['repository'])) {
                    unset($body['branch']);
                }

                return $this->job($api, 'POST', '/apps', $body);
            })
            ->actions([
                $this->appJob('deploy', __('Deploy'), 'rocket-launch', '/deploy'),
                $this->appJob('rollback', __('Roll back'), 'arrow-uturn-left', '/deploy/rollback')->confirm(__('Go back to the previous release?')),
                $this->appJob('unlock', __('Unlock the deploy'), 'lock-open', '/deploy/unlock')->confirm(__('Only when a deploy is stuck. Unlock?')),
                Action::make('deploy-status', __('Deploy status'))->icon('signal')
                    ->run(function (ApiClient $api, array $row) {
                        $status = (new CipiApp($api, (string) $row['app'], '', ''))->deployStatus(80);

                        return Outcome::show(
                            __('Deploy of :app', ['app' => $row['app']]),
                            $status ?? __('This server cannot report a live deploy status: it needs Cipi 5.8.4 and cipi/api 1.37 or later (cipi self-update).'),
                        );
                    }),
                Action::make('releases', __('Releases'))->icon('rectangle-stack')
                    ->run(function (ApiClient $api, array $row) {
                        $app = new CipiApp($api, (string) $row['app'], '', '');

                        return Outcome::show(__('Releases of :app', ['app' => $row['app']]), $app->releases() ?? $app->agent()['reason'] ?? __('The Cipi agent\'s MCP server is off.'));
                    }),
                Action::make('audit', __('Deploy history'))->icon('clock')
                    ->run(function (ApiClient $api, array $row) {
                        $app = new CipiApp($api, (string) $row['app'], '', '');
                        $history = $app->deployHistory(40);

                        if ($history !== null) {
                            return Outcome::show(__('Deploys of :app', ['app' => $row['app']]), $history);
                        }

                        return Outcome::show(__('Deploys of :app', ['app' => $row['app']]), $api->get('/apps/'.$row['app'].'/deploy/audit')['data'] ?? []);
                    }),
                Action::make('aliases', __('Aliases'))->icon('link')->opens('aliases', fn ($row) => ['app' => $row['app']]),
                Action::make('redirects', __('Redirects'))->icon('arrow-uturn-right')->opens('redirects', fn ($row) => ['app' => $row['app']]),
                Action::make('proxies', __('Proxies'))->icon('arrows-right-left')->opens('proxies', fn ($row) => ['app' => $row['app']]),
                $this->appJob('ssl', __('Install SSL'), 'lock-closed', '/ssl')->fields([
                    Field::checkbox('wildcard', __('Wildcard (DNS-01 with Cloudflare)')),
                    Field::text('account', __('Cloudflare account on the server'))->placeholder('default')->help(__('Only for DNS-01; the accounts of `cipi ssl dns`')),
                ])->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/apps/'.$row['app'].'/ssl', $data['wildcard'] ? array_filter(['dns' => 'cloudflare', 'wildcard' => true, 'account' => $data['account']]) : [])),
                $this->appJob('force-https', __('Force HTTPS'), 'shield-check', '/ssl/force')->when(fn ($row) => empty($row['force_https'])),
                Action::make('www', __('www redirect'))->icon('arrows-right-left')->fields([
                    Field::select('mode', __('Redirect'), ['add' => __('Add the www/apex alias'), 'force-to-root' => __('www → apex'), 'force-from-root' => __('apex → www'), 'clear' => __('No redirect')])->required()->default(fn ($row) => $row['www_redirect'] ?? 'add'),
                ])->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/apps/'.$row['app'].'/www/'.$data['mode'])),
                $this->appJob('artisan', __('Artisan'), 'command-line', '/artisan')->when(fn ($row) => ($row['type'] ?? 'laravel') === 'laravel')
                    ->fields([Field::text('command', __('Command'))->required()->placeholder('migrate --force')->help(__('Without "php artisan"; tinker is not allowed'))]),
                $this->appJob('run', __('Run a command'), 'command-line', '/run')
                    ->fields([Field::text('command', __('Command'))->required()->placeholder('composer install --no-dev')->help(__('Whitelisted binaries only: composer, npm, git, ls…'))]),
                Action::make('logs', __('Logs'))->icon('document-text')->fields([
                    Field::select('type', __('Log'), ['laravel' => 'Laravel', 'nginx' => 'nginx', 'php' => 'PHP-FPM', 'worker' => __('Workers'), 'deploy' => __('Deploy'), 'all' => __('All')])->default('laravel'),
                    Field::number('lines', __('Lines'))->default(100),
                ])->run(fn (ApiClient $api, array $row, array $data) => Outcome::show(__('Logs of :app', ['app' => $row['app']]), $this->logs($api->get('/apps/'.$row['app'].'/logs', ['type' => $data['type'], 'per_page' => min(1000, max(1, (int) ($data['lines'] ?? 100)))])['data'] ?? []))),
                Action::make('env', __('.env'))->icon('variable')->when(fn ($row) => ($row['type'] ?? 'laravel') === 'laravel')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('.env of :app (secrets hidden)', ['app' => $row['app']]), $api->get('/apps/'.$row['app'].'/env')['data'] ?? [])),
                Action::make('env-set', __('Set .env keys'))->icon('pencil-square')->when(fn ($row) => ($row['type'] ?? 'laravel') === 'laravel')->fields([
                    Field::textarea('set', __('Set'))->placeholder("APP_DEBUG=false\nMAIL_FROM_ADDRESS=hello@example.com")->help(__('KEY=value, one per line')),
                    Field::text('unset', __('Remove'))->placeholder('OLD_KEY, OTHER_KEY'),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $set = [];

                    foreach (preg_split('/\r\n|\r|\n/', (string) $data['set']) ?: [] as $line) {
                        if (str_contains($line, '=')) {
                            [$key, $value] = explode('=', $line, 2);
                            $set[trim($key)] = trim($value);
                        }
                    }

                    $api->put('/apps/'.$row['app'].'/env', array_filter(['set' => $set, 'unset' => self::lines($data['unset'])]));

                    return __('.env saved: deploy or reload to apply.');
                }),
                $this->appJob('edit', __('Edit'), 'pencil', '', 'PUT')->fields([
                    Field::text('domain', __('Primary domain'))->default(fn ($row) => $row['domain'] ?? null),
                    Field::text('env', __('Environment'))->default(fn ($row) => $row['env'] ?? 'production')->rules([self::ENV_RULE])
                        ->help(__('From Cipi 5.8 / cipi/api 1.36: which `cipi-<env>.yml` the app reads. Older servers ignore it.')),
                    Field::select('php', __('PHP'), self::PHP)->default(fn ($row) => $row['php'] ?? null),
                    Field::text('repository', __('Git repository'))->default(fn ($row) => $row['repository'] ?? null),
                    Field::text('branch', __('Branch'))->default(fn ($row) => $row['branch'] ?? null),
                ]),
                Action::make('node-status', __('Node status'))->icon('cube')->when(fn ($row) => filled($row['node'] ?? $row['node_mode'] ?? null) || ($row['type'] ?? '') === 'node')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Node of :app', ['app' => $row['app']]), $api->get('/apps/'.$row['app'].'/node')['data'] ?? [])),
                $this->appJob('node-restart', __('Restart Node'), 'arrow-path', '/node/restart')->when(fn ($row) => filled($row['node'] ?? $row['node_mode'] ?? null) || ($row['type'] ?? '') === 'node')
                    ->confirm(__('Blue/green restart of the Node process?')),
                Action::make('basicauth', __('Password protection'))->icon('key')->fields([
                    Field::checkbox('enabled', __('Ask for a username and password'))->default(fn ($row) => (bool) ($row['basic_auth'] ?? false)),
                    Field::text('user', __('Username'))->default('admin'),
                    Field::password('password', __('Password'))->help(__('Empty: generated and shown once')),
                ])->run(fn (ApiClient $api, array $row, array $data) => $data['enabled']
                    ? Outcome::show(__('Password protection of :app', ['app' => $row['app']]), $api->post('/apps/'.$row['app'].'/basicauth/enable', array_filter(['user' => $data['user'], 'password' => $data['password']]))['data'] ?? [], __('Protected.'))
                    : tap(__('Protection removed.'), fn () => $api->post('/apps/'.$row['app'].'/basicauth/disable'))),
                Action::make('health', __('Health check'))->icon('heart')->run(fn (ApiClient $api, array $row) => Outcome::show(__('Health of :app', ['app' => $row['app']]), $api->post('/apps/'.$row['app'].'/health/check')['data'] ?? [])),
                Action::make('disk', __('Disk usage'))->icon('circle-stack')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Disk of :app', ['app' => $row['app']]), (new CipiApp($api, (string) $row['app'], '', ''))->disk() ?? __('This Cipi has no disk report (5.5.2 or later).'))),
                Action::make('db-backup', __('Back up the database'))->icon('archive-box')->confirm(__('Dump the app\'s database on the server now?'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/dbs/'.$row['app'].'/backup')),
                Action::make('anonymize', __('Anonymized database copy'))->icon('eye-slash')->fields([
                    Field::text('email', __('Send the download link to'))->required()->rules(['email'])->default(fn () => auth()->user()?->email),
                    Field::checkbox('gzip', __('Compressed (.sql.gz)'))->default(true),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $app = new CipiApp($api, (string) $row['app'], '', '');
                    $app->requestAnonymizedDump((string) $data['email'], (bool) $data['gzip']);

                    return __('Queued in the app: a link to the anonymized dump reaches :email within minutes, valid :ttl minutes.', ['email' => $data['email'], 'ttl' => $app->anonymizer()['ttl']]);
                }),
                Action::make('anonymizer-config', __('Generate anonymization.json'))->icon('document-plus')
                    ->confirm(__('Write ~/.db/anonymization.json on the server from the live schema (cipi:init-anonymize --scan)? An existing file is replaced.'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/apps/'.$row['app'].'/artisan', ['command' => 'cipi:init-anonymize --scan --force'])),
                Action::make('anonymizer-audit', __('Anonymizer audit'))->icon('shield-check')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Anonymization of :app', ['app' => $row['app']]), (new CipiApp($api, (string) $row['app'], '', ''))->anonymizerTool('audit'))),
                Action::make('limits', __('Limits'))->icon('adjustments-horizontal')->run(fn (ApiClient $api, array $row) => Outcome::show(__('Limits of :app', ['app' => $row['app']]), $api->get('/apps/'.$row['app'].'/limits')['data'] ?? [])),
                Action::make('yml', __('cipi.yml plan'))->icon('document-check')
                    ->run(function (ApiClient $api, array $row) {
                        try {
                            $plan = (new CipiApp($api, (string) $row['app'], '', ''))->yml();
                        } catch (ConnectorException $e) {
                            $plan = CipiApp::ymlUnavailable($e, 'yml-view') ?? throw $e;
                        }

                        return Outcome::show(__('cipi.yml of :app', ['app' => $row['app']]), $plan);
                    }),
                Action::make('yml-apply', __('Apply cipi.yml'))->icon('document-arrow-down')->confirm(__('Apply the app\'s cipi.yml to the server now, as its plan says?'))
                    ->run(fn (ApiClient $api, array $row) => $this->yml(fn () => $this->job($api, 'POST', '/apps/'.$row['app'].'/yml/apply'))),
                // Marked danger, so the "safe actions" hand-over leaves it out: turning it on gives the app's
                // server configuration to whoever can commit to the repository.
                Action::make('yml-auto', __('cipi.yml after every deploy'))->icon('arrow-path-rounded-square')->fields([
                    Field::select('auto', __('After every successful deploy'), ['on' => __('Apply cipi.yml (auto-apply on)'), 'off' => __('Leave it as it is (auto-apply off)')])->required()->default('on')
                        ->help(__('On: anyone who can commit to the repository can change this app\'s server configuration (aliases, PHP settings, limits, workers, deploy recipe, scheduled commands, databases, backups). Webhook deploys apply it from Cipi 5.6.4.')),
                ])->danger(__('With auto-apply on, anyone who can commit to the repository can change this app\'s server configuration. Go ahead?'))
                    ->run(fn (ApiClient $api, array $row, array $data) => $this->yml(fn () => Outcome::show(
                        __('cipi.yml of :app', ['app' => $row['app']]),
                        (new CipiApp($api, (string) $row['app'], '', ''))->ymlAuto($data['auto'] === 'on'),
                        $data['auto'] === 'on' ? __('cipi.yml is applied after every deploy.') : __('cipi.yml is no longer applied after deploys.'),
                    ))),
                Action::make('details', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['app'], $api->get('/apps/'.$row['app'])['data'] ?? $row)),
                $this->appJob('fix-permissions', __('Fix permissions'), 'wrench-screwdriver', '/fix-permissions')->confirm(__('Restore the permissions of the app\'s home?')),
                $this->appJob('suspend', __('Suspend'), 'pause', '/suspend')->confirm(__('Take the app offline with a 503 page?'))->when(fn ($row) => empty($row['suspended'])),
                $this->appJob('unsuspend', __('Bring back online'), 'play', '/unsuspend')->when(fn ($row) => ! empty($row['suspended'])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the app, its files, database and user from the server?'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'DELETE', '/apps/'.$row['app'])),
            ]);
    }

    /**
     * The log snapshots as one text: a heading per file, its lines below.
     *
     * @param  array<mixed>  $logs
     */
    private function logs(array $logs): string
    {
        $text = '';

        foreach ((array) ($logs['files'] ?? $logs['logs'] ?? $logs) as $key => $file) {
            $lines = is_array($file) ? ($file['lines'] ?? $file['content'] ?? $file) : $file;
            $name = is_array($file) ? ($file['file'] ?? $file['path'] ?? $file['type'] ?? $key) : $key;
            $text .= '== '.(is_scalar($name) ? $name : $key)."\n".(is_array($lines) ? implode("\n", array_map(fn ($line) => is_scalar($line) ? (string) $line : (string) json_encode($line), $lines)) : (string) $lines)."\n\n";
        }

        return trim($text) !== '' ? trim($text) : __('No log lines.');
    }

    private function aliases(): Section
    {
        return Section::make('aliases', __('Aliases'))
            ->childOf('apps', 'app')
            ->id('alias')
            ->list(fn (ApiClient $api, array $scope) => array_map(fn ($alias) => ['alias' => $alias], array_values((array) ($api->get('/apps/'.$scope['app'].'/aliases')['data'] ?? []))))
            ->columns([
                Column::make('alias', __('Alias'))->mono(),
            ])
            ->create(__('Add an alias'), [
                Field::text('alias', __('Domain'))->required()->placeholder('www.example.com'),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => $this->job($api, 'POST', '/apps/'.$scope['app'].'/aliases/'.rawurlencode((string) $data['alias'])))
            ->actions([
                Action::make('delete', __('Remove'))->icon('trash')->danger(__('Remove the alias?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => $this->job($api, 'DELETE', '/apps/'.$scope['app'].'/aliases/'.rawurlencode((string) $row['alias']))),
            ]);
    }

    /**
     * Whole-app and path redirects (API 1.31+ / Cipi 5.4.1+). The list is the path rules;
     * whole-app redirect is set with an action that does not need a row.
     */
    private function redirects(): Section
    {
        return Section::make('redirects', __('Redirects'))
            ->childOf('apps', 'app')
            ->id('from')
            ->list(function (ApiClient $api, array $scope) {
                $data = (array) ($this->newer(fn () => $api->get('/apps/'.$scope['app'].'/redirects'), 'redirects-view', 'Cipi 5.4.1 and cipi/api 1.31')['data'] ?? []);
                $rows = [];
                $app = $data['redirect'] ?? $data['app'] ?? null;

                if (is_array($app) && filled($app['to'] ?? null)) {
                    $rows[] = [
                        'from' => '*',
                        'to' => $app['to'],
                        'code' => $app['code'] ?? 301,
                        'keep_path' => (bool) ($app['keep_path'] ?? true),
                        'enabled' => (bool) ($app['enabled'] ?? true),
                        'kind' => 'app',
                    ];
                } else {
                    // A placeholder so the whole-app redirect can be set with an empty path list.
                    $rows[] = ['from' => '*', 'to' => '', 'code' => '', 'kind' => 'app', 'enabled' => false];
                }

                foreach ((array) ($data['redirects'] ?? $data['paths'] ?? []) as $row) {
                    if (is_array($row) && filled($row['from'] ?? null)) {
                        $rows[] = [...$row, 'kind' => 'path'];
                    }
                }

                return $rows;
            })
            ->columns([
                Column::make('from', __('From'))->mono(),
                Column::make('to', __('To'))->mono(),
                Column::make('code', __('Code'))->mono(),
            ])
            ->create(__('Add a path redirect'), [
                Field::text('from', __('Path'))->required()->placeholder('/old')->help(__('A path ending in / is a prefix.')),
                Field::text('to', __('Target'))->required()->placeholder('https://example.com/new'),
                Field::select('code', __('Status'), ['301' => '301', '302' => '302', '307' => '307', '308' => '308'])->default('301'),
                Field::checkbox('keep_path', __('Keep the rest of the path'))->default(true)
                    ->help(__('Off for a target with ?query or #fragment.')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Redirect saved.'), fn () => $api->post('/apps/'.$scope['app'].'/redirects', [
                'from' => $data['from'],
                'to' => $data['to'],
                'code' => (int) ($data['code'] ?: 301),
                // sent either way: the API keeps the path when it is missing
                'keep_path' => (bool) $data['keep_path'],
            ])))
            ->actions([
                // Confirmed like Suspend: every request to the app goes elsewhere until it is turned off.
                Action::make('app-redirect', __('Whole-app redirect'))->icon('globe-alt')->when(fn ($row) => ($row['kind'] ?? '') === 'app')->fields([
                    Field::text('to', __('Target URL'))->required()->placeholder('https://example.com')->default(fn ($row) => $row['to'] ?? null),
                    Field::select('code', __('Status'), ['301' => '301', '302' => '302', '307' => '307', '308' => '308'])->default(fn ($row) => (string) (($row['code'] ?? '') ?: '301')),
                    Field::checkbox('keep_path', __('Keep the path'))->default(fn ($row) => (bool) ($row['keep_path'] ?? true)),
                ])->confirm(__('Send every request to this app to the target, on all its domains?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Whole-app redirect set.'), fn () => $api->put('/apps/'.$scope['app'].'/redirect', [
                        'to' => $data['to'],
                        'code' => (int) ($data['code'] ?: 301),
                        'keep_path' => (bool) $data['keep_path'],
                    ]))),
                Action::make('enable', __('Enable whole-app redirect'))->icon('play')->when(fn ($row) => ($row['kind'] ?? '') === 'app' && filled($row['to'] ?? null) && empty($row['enabled']))
                    ->confirm(__('Send every request to this app to the target, on all its domains?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Redirect enabled.'), fn () => $api->post('/apps/'.$scope['app'].'/redirect/enable'))),
                Action::make('disable', __('Disable whole-app redirect'))->icon('pause')->when(fn ($row) => ($row['kind'] ?? '') === 'app' && filled($row['to'] ?? null) && ! empty($row['enabled']))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Redirect disabled.'), fn () => $api->post('/apps/'.$scope['app'].'/redirect/disable'))),
                Action::make('clear-app', __('Remove whole-app redirect'))->icon('x-mark')->when(fn ($row) => ($row['kind'] ?? '') === 'app' && filled($row['to'] ?? null))->confirm(__('Remove the whole-app redirect?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Redirect removed.'), fn () => $api->delete('/apps/'.$scope['app'].'/redirect'))),
                Action::make('delete', __('Remove path'))->icon('trash')->when(fn ($row) => ($row['kind'] ?? 'path') === 'path')->danger(__('Remove this path redirect?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Path redirect removed.'), fn () => $api->delete('/apps/'.$scope['app'].'/redirects', ['from' => $row['from']]))),
            ]);
    }

    private function proxies(): Section
    {
        return Section::make('proxies', __('Proxies'))
            ->childOf('apps', 'app')
            ->id('prefix')
            ->list(function (ApiClient $api, array $scope) {
                $data = (array) ($this->newer(fn () => $api->get('/apps/'.$scope['app'].'/proxies'), 'proxies-view', 'Cipi 5.4.1 and cipi/api 1.31')['data'] ?? []);

                return array_values(array_filter(
                    (array) ($data['proxies'] ?? $data),
                    fn ($row) => is_array($row) && filled($row['prefix'] ?? null),
                ));
            })
            ->columns([
                Column::make('prefix', __('Prefix'))->mono(),
                Column::make('upstream', __('Upstream'))->mono(),
                Column::make('strip_prefix', __('Strip prefix'))->bool(),
            ])
            // The usual create, confirmed: the requests under the prefix go to another server.
            ->toolbar([
                Action::make('create', __('Add a proxy'))->icon('plus')->primary()->fields([
                    Field::text('prefix', __('Prefix'))->required()->placeholder('/api'),
                    Field::text('upstream', __('Upstream'))->required()->placeholder('http://127.0.0.1:8080')->help(__('Loopback ports Cipi already uses are refused.')),
                    Field::checkbox('strip_prefix', __('Strip the prefix'))->default(true),
                    Field::checkbox('preserve_host', __('Preserve Host')),
                ])->confirm(__('Send every request under this prefix, cookies included, to the upstream?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Proxy saved.'), fn () => $api->post('/apps/'.$scope['app'].'/proxies', [
                        'prefix' => $data['prefix'],
                        'upstream' => $data['upstream'],
                        'strip_prefix' => (bool) $data['strip_prefix'],
                        'preserve_host' => (bool) $data['preserve_host'],
                    ]))),
            ])
            ->actions([
                Action::make('delete', __('Remove'))->icon('trash')->danger(__('Remove this proxy?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Proxy removed.'), fn () => $api->delete('/apps/'.$scope['app'].'/proxies', ['prefix' => $row['prefix']]))),
            ]);
    }

    private function databases(): Section
    {
        return Section::make('databases', __('Databases'))
            ->icon('circle-stack')
            ->id(fn ($row) => ($row['engine'] ?? '').':'.($row['name'] ?? ''))
            ->list(fn (ApiClient $api) => array_map(
                // size_mb from Cipi 5.9 / cipi/api 1.39, the size the table printed before
                fn ($row) => is_array($row) ? [...$row, 'size' => is_numeric($row['size_mb'] ?? null) ? $row['size_mb'].' MB' : ($row['size'] ?? null)] : $row,
                array_values((array) ($api->get('/dbs')['data'] ?? [])),
            ))
            ->columns([
                Column::make('name', __('Database'))->mono(),
                Column::make('engine', __('Engine'))->badge(['mariadb' => 'blue', 'pgsql' => 'purple']),
                Column::make('app', __('App'))->mono(),
                Column::make('user', __('User'))->mono(),
                Column::make('size', __('Size')),
            ])
            ->create(__('New database'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z][a-z0-9_]{2,31}$/']),
                Field::select('engine', __('Engine'), ['' => __('Server default'), 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL']),
                Field::text('app', __('Link to app'))->placeholder('myapp')->help(__('From Cipi 5.9 / cipi/api 1.39: the app this database belongs to.')),
            ], fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/dbs', array_filter($data)))
            ->actions([
                Action::make('backup', __('Back up'))->icon('archive-box')
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/backup', ['engine' => $row['engine'] ?? null])),
                // Destructive, so the safe-actions hand-over leaves it out.
                Action::make('restore', __('Restore from file'))->icon('arrow-up-tray')->fields([
                    Field::text('file', __('Path on the server'))->required()->placeholder('/home/cipi/backups/shop.sql.gz')->rules(['regex:/\.sql\.gz$/'])
                        ->help(__('A .sql.gz dump already on the server. Uploading one from Studio is not wired yet.')),
                ])->danger(__('Overwrite this database with the file? Everything in it now is replaced.'))->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/restore', array_filter(['file' => $data['file'], 'engine' => $row['engine'] ?? null]))),
                Action::make('link', __('Link to an app'))->icon('link')->fields([
                    Field::text('app', __('App'))->required()->placeholder('myapp')->help(__('Cipi 5.9 / cipi/api 1.39.')),
                ])->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/link', array_filter(['app' => $data['app'], 'engine' => $row['engine'] ?? null]))),
                Action::make('unlink', __('Unlink from its app'))->icon('link-slash')->confirm(__('Unlink this database from its app?'))
                    ->when(fn ($row) => filled($row['app'] ?? $row['link'] ?? null))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'DELETE', '/dbs/'.$row['name'].'/link', array_filter(['engine' => $row['engine'] ?? null]))),
                Action::make('password', __('New password'))->icon('key')->confirm(__('Apps using the old password stop connecting until their .env is updated. Go ahead?'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/password', ['engine' => $row['engine'] ?? null])),
            ]);
    }

    /** Server backup profiles (API 1.39+ / Cipi 5.9.0+). Destinations stay on the server. */
    private function backups(): Section
    {
        return Section::make('backups', __('Backups'))
            ->icon('archive-box')
            ->id('name')
            ->list(function (ApiClient $api) {
                $data = (array) ($this->newer(fn () => $api->get('/backups'), 'backups-view', 'Cipi 5.9 and cipi/api 1.39')['data'] ?? []);

                if (array_key_exists('configured', $data) && $data['configured'] === false) {
                    return [];
                }

                return array_values(array_filter(
                    (array) ($data['profiles'] ?? []),
                    fn ($row) => is_array($row) && filled($row['name'] ?? null),
                ));
            })
            ->columns([
                Column::make('name', __('Profile'))->mono(),
                Column::make('enabled', __('On'))->bool(),
                Column::make('overdue', __('Overdue'))->bool(),
                Column::make('running', __('Running'))->bool(),
            ])
            ->actions([
                Action::make('status', __('Backup status'))->icon('information-circle')
                    ->run(fn (ApiClient $api) => Outcome::show(__('Backups'), $api->get('/backups')['data'] ?? __('Not available on this server (Cipi 5.9 / cipi/api 1.39).'))),
                Action::make('runs', __('Stored runs'))->icon('clock')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Runs of :profile', ['profile' => $row['name']]), $api->get('/backups/runs', ['profile' => $row['name']])['data'] ?? [])),
                Action::make('run', __('Run now'))->icon('play')->confirm(__('Start a backup run on the server now?'))
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Backup started'), $api->post('/backups/run', array_filter(['profile' => $row['name'] ?? null]))['data'] ?? __('Started.'), __('The run goes on by itself; refresh the status to see when it ends.'))),
                Action::make('verify', __('Verify'))->icon('shield-check')
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/backups/verify', array_filter(['profile' => $row['name'] ?? null]))),
                Action::make('enable', __('Enable'))->icon('check')->when(fn ($row) => empty($row['enabled']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Profile enabled.'), fn () => $api->post('/backups/profiles/'.$row['name'].'/enable'))),
                Action::make('disable', __('Disable'))->icon('pause')->when(fn ($row) => ! empty($row['enabled']))
                    ->danger(__('Stop this backup profile on the server? Nothing it covers is backed up until it is enabled again.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Profile disabled.'), fn () => $api->post('/backups/profiles/'.$row['name'].'/disable'))),
            ]);
    }

    /** Node runtimes on the server (list from 1.31; install/upgrade/default/remove from 1.39 / Cipi 5.9). */
    private function node(): Section
    {
        return Section::make('node', __('Node'))
            ->icon('cube')
            ->id('major')
            ->list(function (ApiClient $api) {
                $data = (array) ($this->newer(fn () => $api->get('/node'), 'node-view', 'Cipi 5.4.1 and cipi/api 1.31')['data'] ?? []);

                $default = $data['default'] ?? null;

                $rows = [];

                foreach ((array) ($data['runtimes'] ?? $data['versions'] ?? $data) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $major = (string) ($row['major'] ?? $row['version'] ?? $row['name'] ?? '');

                    if ($major === '') {
                        continue;
                    }

                    $rows[] = [
                        'major' => $major,
                        'version' => $row['version'] ?? $row['full'] ?? $major,
                        // the names of the apps on it (cipi/api): the column joins them
                        'apps' => $row['apps'] ?? $row['app_list'] ?? [],
                        'default' => $row['default'] ?? ($default !== null && (string) $default === $major),
                    ];
                }

                return $rows;
            })
            ->columns([
                Column::make('major', __('Major'))->mono(),
                Column::make('version', __('Version'))->mono(),
                Column::make('apps', __('Apps')),
                Column::make('default', __('Default'))->bool(),
            ])
            ->create(__('Install a major'), [
                Field::text('major', __('Major'))->required()->placeholder('22')->rules(['regex:/^\d+$/'])->help(__('Cipi 5.9 / cipi/api 1.39: `cipi node install`.')),
            ], fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/node/install', ['major' => $data['major']]))
            ->actions([
                // Server-wide, so destructive: the safe-actions hand-over leaves them out.
                Action::make('upgrade', __('Upgrade'))->icon('arrow-up')->when(fn ($row) => ($row['major'] ?? '') !== 'system')
                    ->danger(__('Upgrade this Node major on the server and restart every app that runs on it?'))
                    ->run(fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/node/upgrade', ['major' => $row['major'], 'restart' => true])),
                Action::make('default', __('Make default'))->icon('star')->when(fn ($row) => empty($row['default']))
                    ->danger(__('Make this the server\'s default Node? Every app on the default runtime moves to it.'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'PUT', '/node/default', ['major' => $row['major']])),
                Action::make('delete', __('Remove'))->icon('trash')->when(fn ($row) => ($row['major'] ?? '') !== 'system')
                    ->danger(__('Remove this Node major from the server? Apps that run on it stop working.'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'DELETE', '/node/'.$row['major'])),
            ]);
    }

    private function services(): Section
    {
        return Section::make('services', __('Services'))
            ->icon('cog-6-tooth')
            ->id('name')
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/services')['data'] ?? [])))
            ->columns([
                Column::make('name', __('Service'))->mono(),
                Column::make('status', __('Status'))->badge(['running' => 'green', 'active' => 'green', 'stopped' => 'red', 'failed' => 'red', '*' => 'yellow']),
                Column::make('since', __('Since')),
            ])
            ->actions([
                Action::make('restart', __('Restart'))->icon('arrow-path')->confirm(__('Restart the service?'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/services/'.$row['name'].'/restart')),
            ]);
    }

    private function sshKeys(): Section
    {
        return Section::make('ssh-keys', __('SSH keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/ssh/keys')['data'] ?? [])))
            ->columns([
                Column::make('comment', __('Comment')),
                Column::make('type', __('Type'))->mono(),
                Column::make('fingerprint', __('Fingerprint'))->mono(),
                Column::make('current_session', __('This session'))->bool(),
            ])
            ->create(__('Add a key'), [
                Field::textarea('key', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA… you@laptop')->help(__('For the cipi user on the server')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added.'), fn () => $api->post('/ssh/keys', ['key' => trim((string) $data['key'])])))
            ->actions([
                Action::make('delete', __('Remove'))->icon('trash')->danger(__('Remove the key from the server?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key removed.'), fn () => $api->delete('/ssh/keys/'.$row['id']))),
            ]);
    }

    private function php(): Section
    {
        return Section::make('php', __('PHP'))
            ->icon('code-bracket')
            ->id('version')
            ->list(function (ApiClient $api) {
                // GET /api/php answers { default, installable, versions }, not a flat list.
                $data = (array) ($api->get('/php')['data'] ?? []);

                return array_values(array_filter(
                    (array) ($data['versions'] ?? $data),
                    fn ($row) => is_array($row) && isset($row['version']),
                ));
            })
            ->columns([
                Column::make('version', __('Version'))->mono(),
                Column::make('status', __('Status'))->badge(['installed' => 'green', 'running' => 'green', '*' => 'zinc']),
                Column::make('apps', __('Apps')),
                Column::make('default', __('Default'))->bool(),
            ])
            ->create(__('Install a version'), [
                Field::select('version', __('Version'), self::PHP)->required(),
            ], fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/php/install', ['version' => $data['version']]));
    }
}
