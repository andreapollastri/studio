<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Action;
use App\Infrastructure\ApiClient;
use App\Infrastructure\Cipi\CipiApp;
use App\Infrastructure\Column;
use App\Infrastructure\Connector;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;
use Illuminate\Support\Sleep;

/**
 * Cipi servers (https://cipi.sh), one account per server with the Cipi API
 * installed (`cipi api`): the server's status, its apps (create, deploy,
 * rollback, releases, SSL, aliases, .env, Artisan, logs, disk, the anonymized
 * database copy of the Cipi agent, suspend), databases,
 * services, SSH keys and PHP versions. Most changes run as background jobs
 * on the server: Studio waits a few seconds, then offers to check again.
 */
final class Cipi extends Connector
{
    private const PHP = ['8.5' => '8.5', '8.4' => '8.4', '8.3' => '8.3', '8.2' => '8.2', '8.1' => '8.1', '8.0' => '8.0', '7.4' => '7.4'];

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
        return __('Cipi servers through the Cipi API: apps, deploys and rollbacks, SSL, aliases, .env, Artisan, logs, databases, services, SSH keys and PHP, one server per account.');
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
                ->help(__('`cipi api token create` on the server, with the abilities to allow (all of them for full control). Add Studio\'s address to `cipi api ip-whitelist` if it is on.')),
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

        return 'ok · '.data_get($system, 'hostname', '').' · Cipi '.data_get($system, 'cipi', '?');
    }

    public function sections(): array
    {
        return [
            $this->status(),
            $this->apps(),
            $this->aliases(),
            $this->databases(),
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
            'completed' => Outcome::show($title, $job['result'] ?? __('Completed.'), __('Completed.')),
            'failed' => Outcome::show($title, $job['result'] ?? __('Failed.'), __('The job failed on the server.')),
            default => Outcome::pending($title.' · '.$status, $token, __('Still running on the server.')),
        };
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

    private function status(): Section
    {
        return Section::info('status', __('Server'), function (ApiClient $api) {
            $status = (array) ($api->get('/status')['data'] ?? []);
            $disk = $api->get('/disk')['data'] ?? null;

            return [
                __('System') => [
                    __('Host') => data_get($status, 'system.hostname'),
                    __('IP') => data_get($status, 'system.ip'),
                    __('OS') => data_get($status, 'system.os'),
                    __('Cipi') => data_get($status, 'system.cipi'),
                    __('Uptime') => data_get($status, 'system.uptime'),
                    __('Apps') => data_get($status, 'apps'),
                ],
                __('Resources') => [
                    __('CPU') => data_get($status, 'resources.cpu.usage_percent').'%',
                    __('Memory') => data_get($status, 'resources.memory.used_mb').' / '.data_get($status, 'resources.memory.total_mb').' MB ('.data_get($status, 'resources.memory.usage_percent').'%)',
                    __('Disk') => data_get($status, 'resources.disk.display'),
                ],
                __('Services') => (array) data_get($status, 'services', []),
                __('PHP') => collect((array) data_get($status, 'php', []))->mapWithKeys(fn ($php) => ['PHP '.$php['version'] => ($php['status'] ?? '').' · '.trans_choice(':count pool|:count pools', (int) ($php['pools'] ?? 0))])->all(),
                ...(is_array($disk) ? [__('Disk by app') => collect((array) ($disk['apps'] ?? []))->mapWithKeys(fn ($app) => [(string) ($app['app'] ?? $app['name'] ?? '?') => ($app['total_human'] ?? $app['total'] ?? '').(isset($app['share']) ? ' · '.$app['share'].'%' : '')])->all()] : []),
            ];
        });
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
                Column::make('type', __('Type'))->badge(['laravel' => 'red', 'node' => 'green', 'custom' => 'zinc']),
                Column::make('php', __('PHP'))->mono(),
                Column::make('branch', __('Branch'))->mono(),
                Column::make('suspended', __('Suspended'))->bool(),
                Column::make('force_https', __('HTTPS'))->bool(),
            ])
            ->create(__('New app'), [
                Field::text('user', __('App user'))->required()->placeholder('myapp')->rules(['regex:/^[a-z][a-z0-9]{2,31}$/'])->help(__('Lowercase letters and digits: the Linux user, the database and the app name')),
                Field::text('domain', __('Domain'))->required()->placeholder('myapp.example.com'),
                Field::text('repository', __('Git repository (SSH)'))->placeholder('git@github.com:acme/myapp.git'),
                Field::text('branch', __('Branch'))->default('main'),
                Field::select('php', __('PHP'), self::PHP)->default('8.4'),
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
                Action::make('releases', __('Releases'))->icon('rectangle-stack')
                    ->run(function (ApiClient $api, array $row) {
                        $app = new CipiApp($api, (string) $row['app'], '', '');

                        return Outcome::show(__('Releases of :app', ['app' => $row['app']]), $app->releases() ?? $app->agent()['reason'] ?? __('The Cipi agent\'s MCP server is off.'));
                    }),
                Action::make('audit', __('Deploy history'))->icon('clock')->run(fn (ApiClient $api, array $row) => Outcome::show(__('Deploys of :app', ['app' => $row['app']]), $api->get('/apps/'.$row['app'].'/deploy/audit')['data'] ?? [])),
                Action::make('aliases', __('Aliases'))->icon('link')->opens('aliases', fn ($row) => ['app' => $row['app']]),
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
                    Field::select('php', __('PHP'), self::PHP)->default(fn ($row) => $row['php'] ?? null),
                    Field::text('repository', __('Git repository'))->default(fn ($row) => $row['repository'] ?? null),
                    Field::text('branch', __('Branch'))->default(fn ($row) => $row['branch'] ?? null),
                ]),
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

    private function databases(): Section
    {
        return Section::make('databases', __('Databases'))
            ->icon('circle-stack')
            ->id(fn ($row) => ($row['engine'] ?? '').':'.($row['name'] ?? ''))
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/dbs')['data'] ?? [])))
            ->columns([
                Column::make('name', __('Database'))->mono(),
                Column::make('engine', __('Engine'))->badge(['mariadb' => 'blue', 'pgsql' => 'purple']),
                Column::make('user', __('User'))->mono(),
                Column::make('size', __('Size')),
            ])
            ->create(__('New database'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z][a-z0-9_]{2,31}$/']),
                Field::select('engine', __('Engine'), ['' => __('Server default'), 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL']),
            ], fn (ApiClient $api, array $row, array $data) => $this->job($api, 'POST', '/dbs', array_filter($data)))
            ->actions([
                Action::make('backup', __('Back up'))->icon('archive-box')
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/backup', ['engine' => $row['engine'] ?? null])),
                Action::make('password', __('New password'))->icon('key')->confirm(__('Apps using the old password stop connecting until their .env is updated. Go ahead?'))
                    ->run(fn (ApiClient $api, array $row) => $this->job($api, 'POST', '/dbs/'.$row['name'].'/password', ['engine' => $row['engine'] ?? null])),
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
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/php')['data'] ?? [])))
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
