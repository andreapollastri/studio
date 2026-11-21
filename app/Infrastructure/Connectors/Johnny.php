<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Action;
use App\Infrastructure\ApiClient;
use App\Infrastructure\Column;
use App\Infrastructure\Connector;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;
use Illuminate\Support\Number;

/**
 * Johnny (https://johnny.web.ap.it), S3-compatible storage on one's own server
 * (Garage behind Caddy), through the REST API of its panel (Johnny 2.0): buckets
 * and their settings (quotas, CORS, expiry rules), keys with one scope per bucket
 * or on every bucket, a bucket provisioned with its own key in one call, and the
 * connections, transfers and scheduled syncs that move data in and out of it.
 * One panel per account. Panels older than 2.0 keep buckets and keys; the rest
 * says it needs the update.
 */
final class Johnny extends Connector
{
    /** Bucket names as Johnny accepts them. */
    private const BUCKET = '/^[a-z0-9][a-z0-9._-]{1,254}$/';

    /** Key names as Johnny accepts them. */
    private const KEY_NAME = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    /** A size as typed: 10 GB, 500 MB, 1.5 TB; no unit means GB. */
    private const SIZE = '/^\s*(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)?\s*$/i';

    private const METHODS = ['GET' => 'GET', 'HEAD' => 'HEAD', 'PUT' => 'PUT', 'POST' => 'POST', 'DELETE' => 'DELETE'];

    public function key(): string
    {
        return 'johnny';
    }

    public function label(): string
    {
        return 'Johnny';
    }

    public function description(): string
    {
        return __('S3-compatible storage on your own server: buckets and their quotas, CORS and expiry rules, keys with a scope per bucket or on all of them, imports, exports and scheduled syncs with other providers. One panel per account.');
    }

    public function icon(): string
    {
        return 'archive-box';
    }

    public function docs(): string
    {
        return 'https://johnny.web.ap.it/#docs';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Panel address'))->required()->placeholder('https://panel.example.com'),
            Field::password('token', __('API token'))->required()
                ->help(__('Settings → Panel API tokens on the Johnny panel, or php artisan johnny:api-token you@example.com on its server.')),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient(self::instanceUrl($credentials['url'] ?? '').'/api', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        return 'ok · '.trans_choice(':count bucket|:count buckets', count(self::items($api->get('/buckets')['data'] ?? null)));
    }

    public function sections(): array
    {
        return [
            $this->buckets(),
            $this->bucketSettings(),
            $this->bucketKeys(),
            $this->keys(),
            $this->connections(),
            $this->transfers(),
            $this->syncs(),
            $this->syncRuns(),
        ];
    }

    /** A transfer started from here: asked again until it is no longer waiting or running. */
    public function poll(object $api, string $token): Outcome
    {
        /** @var ApiClient $api */
        $transfer = (array) ($api->get('/transfers/'.rawurlencode($token))['data'] ?? []);

        return self::transferOutcome($transfer, $token);
    }

    // ------------------------------------------------------------------ helpers

    private static function bucket(mixed $name): string
    {
        return '/buckets/'.rawurlencode((string) $name);
    }

    /**
     * What only Johnny 2.0 answers: a panel before it has no such route (404), which is said as
     * such instead of "not found".
     *
     * @return array<mixed>
     */
    private static function v2(callable $read): array
    {
        try {
            return (array) $read();
        } catch (ConnectorException $e) {
            if ($e->getCode() === 404) {
                throw new ConnectorException(__('This Johnny panel does not have it yet: it needs Johnny 2.0 (Updates on the panel).'), 404, $e);
            }

            throw $e;
        }
    }

    /** @return array<string, string> */
    private static function scopes(): array
    {
        return [
            'read' => __('Read only'),
            'read-write' => __('Read and write'),
            'write' => __('Write only'),
            'full' => __('Full control'),
        ];
    }

    private static function scopeField(string $name = 'scope', string|\Closure $default = 'read-write'): Field
    {
        return Field::select($name, __('Scope'), self::scopes())->required()->default($default)
            ->help(__('Read only lists and downloads; read and write also uploads, overwrites and deletes; write only does that without reading back; full control adds the bucket\'s settings and deleting it.'));
    }

    /** @return array<string, string> key id => "name · id" */
    private static function keyOptions(ApiClient $api): array
    {
        return collect(self::items($api->get('/keys')['data'] ?? null))
            ->filter(fn ($key) => is_array($key) && isset($key['id']))
            ->mapWithKeys(fn (array $key) => [(string) $key['id'] => ($key['name'] ?? '?').' · '.$key['id']])
            ->all();
    }

    /** @return array<string, string> */
    private static function bucketOptions(ApiClient $api): array
    {
        return collect(self::items($api->get('/buckets')['data'] ?? null))
            ->filter(fn ($bucket) => is_array($bucket) && isset($bucket['name']))
            ->mapWithKeys(fn (array $bucket) => [(string) $bucket['name'] => (string) $bucket['name']])
            ->all();
    }

    /** @return array<string, string> connection id => "name · provider" */
    private static function connectionOptions(ApiClient $api): array
    {
        return collect(self::items(self::v2(fn () => $api->get('/connections'))['data'] ?? null))
            ->filter(fn ($connection) => is_array($connection) && isset($connection['id']))
            ->mapWithKeys(fn (array $connection) => [(string) $connection['id'] => ($connection['name'] ?? '?').' · '.($connection['provider_label'] ?? $connection['provider'] ?? '')])
            ->all();
    }

    /** @return array<string, string> */
    private static function providerOptions(ApiClient $api): array
    {
        return collect(self::items(self::v2(fn () => $api->get('/providers'))['data'] ?? null))
            ->filter(fn ($provider) => is_array($provider) && isset($provider['id']))
            ->mapWithKeys(fn (array $provider) => [(string) $provider['id'] => (string) ($provider['label'] ?? $provider['id'])])
            ->all();
    }

    /** Bytes out of "10 GB", "500 MB", "1.5 TB" (no unit: GB); null when empty, for no limit. */
    private static function bytes(mixed $size): ?int
    {
        if (! is_string($size) || trim($size) === '') {
            return null;
        }

        if (! preg_match(self::SIZE, $size, $match)) {
            throw new ConnectorException(__('Write the size as 10 GB, 500 MB or 1.5 TB.'));
        }

        $power = ['B' => 0, 'KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4][strtoupper($match[2] ?? '') ?: 'GB'];

        return max(1, (int) round((float) $match[1] * (1024 ** $power)));
    }

    /** "10 GB" out of bytes, in the largest unit that holds it whole: sent back, it is the same number. */
    private static function size(mixed $bytes): string
    {
        if (! is_numeric($bytes)) {
            return '';
        }

        $bytes = (int) $bytes;

        foreach (['TB' => 4, 'GB' => 3, 'MB' => 2, 'KB' => 1] as $unit => $power) {
            if ($bytes >= 1024 ** $power && $bytes % (1024 ** $power) === 0) {
                return intdiv($bytes, 1024 ** $power).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    /**
     * The form of a connection, for an S3 account or an SFTP server, without what was left empty:
     * on a change, an empty secret keeps the stored one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function connectionPayload(array $data): array
    {
        $sftp = ($data['provider'] ?? null) === 'sftp';
        $fields = $sftp
            ? ['host' => $data['host'] ?? null, 'port' => is_numeric($data['port'] ?? null) ? (int) $data['port'] : null, 'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null, 'private_key' => $data['private_key'] ?? null]
            : ['endpoint' => $data['endpoint'] ?? null, 'region' => $data['region'] ?? null,
                'access_key_id' => $data['access_key_id'] ?? null, 'secret_access_key' => $data['secret_access_key'] ?? null];

        $payload = array_filter(['name' => $data['name'] ?? null, 'provider' => $data['provider'] ?? null, ...$fields], fn ($value) => $value !== null && $value !== '');

        // the fields of the other kind stay out: an S3 account sends no SFTP port, and back
        if (! $sftp) {
            $payload['path_style'] = (bool) ($data['path_style'] ?? false);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private static function connectionSaved(array $connection, string $saved): string
    {
        return ($connection['reachable'] ?? null) === false
            ? __(':saved The provider refused it for now: :reason', ['saved' => $saved, 'reason' => $connection['check_error'] ?? __('no details')])
            : $saved;
    }

    /**
     * The fields of an import, an export or a sync.
     *
     * @return list<Field>
     */
    private static function transferFields(): array
    {
        return [
            Field::select('direction', __('Direction'), ['import' => __('Import into Johnny'), 'export' => __('Export out of Johnny')])->required()->default('import'),
            Field::select('connection_id', __('Connection'), fn (ApiClient $api) => self::connectionOptions($api))->required()
                ->help(__('The other provider or SFTP server, from Connections.')),
            Field::text('remote_bucket', __('There'))->required()->placeholder('media')
                ->help(__('The bucket at the other provider, or a folder of the SFTP server (backups/media, /srv/backups).')),
            Field::text('local_bucket', __('On Johnny'))->required()->rules(['regex:'.self::BUCKET])->placeholder('media')
                ->help(__('An import creates the bucket when it is missing; an export needs it to exist.')),
            Field::text('prefix', __('Only this folder'))->placeholder('uploads/2026/'),
            Field::select('mode', __('Mode'), ['copy' => __('Copy: add new and changed files'), 'sync' => __('Mirror: also delete what the source no longer has')])->required()->default('copy'),
            Field::checkbox('create_bucket', __('Create the bucket at the provider'))->help(__('Exports to S3 only; SFTP folders are always created.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function transferPayload(array $data): array
    {
        return array_filter([
            'direction' => $data['direction'],
            'connection_id' => (int) $data['connection_id'],
            'remote_bucket' => $data['remote_bucket'],
            'local_bucket' => $data['local_bucket'],
            'prefix' => $data['prefix'] ?? null,
            'mode' => $data['mode'],
            'create_bucket' => (bool) ($data['create_bucket'] ?? false),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * A transfer as the page shows it: still going (checked again on request) or ended, with what happened.
     *
     * @param  array<string, mixed>  $transfer
     */
    private static function transferOutcome(array $transfer, string $id): Outcome
    {
        $title = __('Transfer #:id', ['id' => $transfer['id'] ?? $id]);
        $data = array_filter([
            'status' => $transfer['status'] ?? null,
            'progress' => self::progress($transfer),
            'from' => ($transfer['direction'] ?? '') === 'export' ? ($transfer['local_bucket'] ?? null) : ($transfer['remote_bucket'] ?? null),
            'to' => ($transfer['direction'] ?? '') === 'export' ? ($transfer['remote_bucket'] ?? null) : ($transfer['local_bucket'] ?? null),
            'errors' => $transfer['errors'] ?? null,
            'message' => $transfer['message'] ?? null,
            'log' => $transfer['log'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (($transfer['active'] ?? false) === true) {
            return Outcome::pending($title.' · '.($transfer['status'] ?? 'pending'), (string) ($transfer['id'] ?? $id), $data);
        }

        return Outcome::show($title, $data, self::transferEnded($transfer));
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private static function transferEnded(array $transfer): ?string
    {
        return match ($transfer['status'] ?? null) {
            'completed' => ($transfer['dry_run'] ?? false) ? self::say('Dry run finished: nothing was changed.') : self::say('Transfer completed.'),
            'failed' => self::say('The transfer failed.'),
            'cancelled' => self::say('The transfer was cancelled.'),
            default => null,
        };
    }

    /**
     * __() as a string, for the texts joined into one cell.
     *
     * @param  array<string, mixed>  $replace
     */
    private static function say(string $text, array $replace = [], ?int $count = null): string
    {
        $said = $count === null ? __($text, $replace) : trans_choice($text, $count, $replace + ['count' => Number::format($count)]);

        return is_string($said) ? $said : $text;
    }

    /**
     * "42% · 1.2 GB of 3 GB · 120 of 300 files"
     *
     * @param  array<string, mixed>  $transfer
     */
    private static function progress(array $transfer): string
    {
        $parts = [];

        if (is_numeric($transfer['percent'] ?? null)) {
            $parts[] = round((float) $transfer['percent']).'%';
        }

        if (is_numeric($transfer['bytes_total'] ?? null) && (int) $transfer['bytes_total'] > 0) {
            $parts[] = __(':done of :total', ['done' => Number::fileSize((int) ($transfer['bytes_done'] ?? 0), 1), 'total' => Number::fileSize((int) $transfer['bytes_total'], 1)]);
        }

        if (is_numeric($transfer['files_total'] ?? null) && (int) $transfer['files_total'] > 0) {
            $parts[] = __(':done of :total files', ['done' => Number::format((int) ($transfer['files_done'] ?? 0)), 'total' => Number::format((int) $transfer['files_total'])]);
        }

        return implode(' · ', $parts);
    }

    /** @return list<Column> */
    private static function transferColumns(): array
    {
        return [
            Column::make('id', __('#'))->mono(),
            Column::make('direction', __('Direction'))->badge(['import' => 'blue', 'export' => 'purple']),
            Column::make('connection_name', __('Connection')),
            Column::make('local_bucket', __('On Johnny'))->mono(),
            Column::make('remote_bucket', __('There'))->mono(),
            Column::make('mode', __('Mode'))->using(fn ($row) => ($row['mode'] ?? '') === 'sync' ? __('mirror') : ($row['mode'] ?? '')),
            Column::make('status', __('Status'))->badge(['completed' => 'green', 'running' => 'sky', 'pending' => 'amber', 'failed' => 'red', 'cancelled' => 'zinc']),
            Column::make('progress', __('Progress'))->using(fn ($row) => self::progress($row)),
            Column::make('created_at', __('Started'))->date(),
        ];
    }

    /** @return list<Action> */
    private static function transferActions(): array
    {
        return [
            Action::make('progress', __('Progress and log'))->icon('eye')
                ->run(fn (ApiClient $api, array $row) => self::transferOutcome((array) ($api->get('/transfers/'.rawurlencode((string) $row['id']))['data'] ?? []), (string) $row['id'])),
            Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => ($row['active'] ?? false) === true)
                ->confirm(__('Stop the transfer? Files already copied stay.'))
                ->run(fn (ApiClient $api, array $row) => tap(__('Transfer cancelled.'), fn () => $api->post('/transfers/'.rawurlencode((string) $row['id']).'/cancel'))),
            Action::make('retry', __('Run again'))->icon('arrow-path')->when(fn ($row) => ($row['active'] ?? false) !== true)
                ->fields([Field::checkbox('for_real', __('For real, not as a dry run'))->default(fn ($row) => ! ($row['dry_run'] ?? false))
                    ->help(__('Same settings; files already at the destination are skipped.'))])
                ->confirm(__('Run the transfer again? A mirror deletes at the destination what the source no longer has.'))
                ->run(fn (ApiClient $api, array $row, array $data) => self::transferOutcome(
                    (array) ($api->post('/transfers/'.rawurlencode((string) $row['id']).'/retry', ['for_real' => (bool) $data['for_real']])['data'] ?? []),
                    (string) $row['id'],
                )),
            Action::make('delete', __('Remove from the history'))->icon('trash')->when(fn ($row) => ($row['active'] ?? false) !== true)
                ->confirm(__('Remove the transfer from the history? Nothing it copied is touched.'))
                ->run(fn (ApiClient $api, array $row) => tap(__('Transfer removed.'), fn () => $api->delete('/transfers/'.rawurlencode((string) $row['id'])))),
        ];
    }

    // ------------------------------------------------------------------ buckets

    private function buckets(): Section
    {
        $scope = fn ($row) => ['bucket' => $row['name'], 'bucket_name' => $row['name']];

        return Section::make('buckets', __('Buckets'))
            ->icon('archive-box')
            ->group(__('Storage'))
            ->id('name')
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items($api->get('/buckets')['data'] ?? null), 'is_array')))
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:'.self::BUCKET])->placeholder('customer-orders')
                    ->help(__('Lower case letters, digits, dots, dashes and underscores. The panel\'s key, the backup key and the keys on all buckets reach it.')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Bucket :name created.', ['name' => $data['name']]), fn () => $api->post('/buckets', ['name' => $data['name']])))
            ->toolbar([
                Action::make('provision', __('Bucket with its key'))->icon('sparkles')->fields([
                    Field::text('bucket', __('Bucket name'))->rules(['regex:'.self::BUCKET])->placeholder(__('empty: a random b-… name')),
                    self::scopeField(default: 'full')->help(__('What the bucket\'s own key may do in it. Full control also changes its settings and deletes it.')),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $made = $api->post('/buckets/provision', array_filter(['bucket' => $data['bucket'] ?? null, 'scope' => $data['scope'] ?? null]));

                    return Outcome::show(__('Bucket :name and its key', ['name' => $made['bucket'] ?? '']), [
                        'bucket' => $made['bucket'] ?? null,
                        'endpoint' => $made['endpoint'] ?? null,
                        'region' => $made['region'] ?? null,
                        'path_style' => $made['path_style'] ?? null,
                        'key' => $made['key_name'] ?? null,
                        'scope' => $made['scope'] ?? null,
                        'access_key_id' => data_get($made, 'credentials.access_key_id'),
                        'secret_access_key' => data_get($made, 'credentials.secret_access_key'),
                        'env' => $made['env'] ?? null,
                    ], __('Provisioned: the secret key shows only now.'));
                }),
            ])
            ->actions([
                Action::make('keys', __('Keys'))->icon('key')->opens('bucket-keys', $scope),
                Action::make('settings', __('Settings'))->icon('adjustments-horizontal')->opens('bucket-settings', $scope),
                Action::make('details', __('Details'))->icon('eye')
                    ->run(function (ApiClient $api, array $row) {
                        $bucket = (array) ($api->get(self::bucket($row['name']))['data'] ?? []);
                        unset($bucket['info_raw']);

                        if (is_numeric($bucket['size'] ?? null)) {
                            $bucket['size'] = Number::fileSize((int) $bucket['size'], 1);
                        }

                        return Outcome::show(__('Bucket :name', ['name' => $row['name']]), $bucket);
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ($row['name'] ?? '') !== 'default')
                    ->danger(__('Delete the bucket? It must be empty.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Bucket deleted.'), fn () => $api->delete(self::bucket($row['name'])))),
            ]);
    }

    /**
     * A bucket's usage, quotas, CORS rule and expiry rules, one row each: a change starts from
     * what the bucket has, so a form never clears a limit it did not show.
     */
    private function bucketSettings(): Section
    {
        return Section::make('bucket-settings', __('Settings of the bucket'))
            ->icon('adjustments-horizontal')
            ->group(__('Storage'))
            ->childOf('buckets', 'bucket_name')
            ->list(function (ApiClient $api, array $scope) {
                $settings = (array) (self::v2(fn () => $api->get(self::bucket($scope['bucket']).'/settings'))['data'] ?? []);
                $usage = (array) ($settings['usage'] ?? []);
                $quotas = (array) ($settings['quotas'] ?? []);
                $cors = (array) ($settings['cors'] ?? []);
                $lifecycle = (array) ($settings['lifecycle'] ?? []);

                return [
                    [
                        'id' => 'usage',
                        'setting' => self::say('Usage'),
                        'value' => implode(' · ', array_filter([
                            is_numeric($usage['size'] ?? null) ? Number::fileSize((int) $usage['size'], 1) : null,
                            is_numeric($usage['objects'] ?? null) ? self::say(':count object|:count objects', [], (int) $usage['objects']) : null,
                        ])),
                    ],
                    [
                        'id' => 'quotas',
                        'setting' => self::say('Quotas'),
                        'value' => implode(' · ', array_filter([
                            is_numeric($quotas['max_size'] ?? null) ? self::say('at most :size', ['size' => self::size($quotas['max_size'])]) : null,
                            is_numeric($quotas['max_objects'] ?? null) ? self::say('at most :count object|at most :count objects', [], (int) $quotas['max_objects']) : null,
                        ])) ?: self::say('no limit'),
                        'max_size' => $quotas['max_size'] ?? null,
                        'max_objects' => $quotas['max_objects'] ?? null,
                    ],
                    [
                        'id' => 'cors',
                        'setting' => self::say('CORS'),
                        'value' => ($cors['origins'] ?? []) === [] ? self::say('no browser access')
                            : implode(', ', (array) $cors['origins']).' · '.implode(' ', (array) ($cors['methods'] ?? []))
                                .((int) ($cors['rules'] ?? 1) > 1 ? ' · '.self::say(':count rule|:count rules', [], (int) $cors['rules']) : ''),
                        'origins' => (array) ($cors['origins'] ?? []),
                        'methods' => (array) ($cors['methods'] ?? []),
                        'headers' => (array) ($cors['headers'] ?? []),
                        'max_age' => $cors['max_age'] ?? null,
                        'rules' => (int) ($cors['rules'] ?? 0),
                    ],
                    [
                        'id' => 'lifecycle',
                        'setting' => self::say('Expiry rules'),
                        'value' => implode(' · ', array_filter([
                            is_numeric($lifecycle['expire_days'] ?? null) ? self::say('objects deleted after :count day|objects deleted after :count days', [], (int) $lifecycle['expire_days'])
                                .(($lifecycle['expire_prefix'] ?? '') !== '' ? ' '.self::say('under :prefix', ['prefix' => (string) $lifecycle['expire_prefix']]) : '') : null,
                            is_numeric($lifecycle['abort_days'] ?? null) ? self::say('unfinished uploads removed after :count day|unfinished uploads removed after :count days', [], (int) $lifecycle['abort_days']) : null,
                            (int) ($lifecycle['other_rules'] ?? 0) > 0 ? self::say(':count rule of other tools, kept|:count rules of other tools, kept', [], (int) $lifecycle['other_rules']) : null,
                        ])) ?: self::say('nothing expires'),
                        'expire_days' => $lifecycle['expire_days'] ?? null,
                        'expire_prefix' => $lifecycle['expire_prefix'] ?? null,
                        'abort_days' => $lifecycle['abort_days'] ?? null,
                    ],
                ];
            })
            ->columns([
                Column::make('setting', __('Setting')),
                Column::make('value', __('Now')),
            ])
            ->actions([
                Action::make('quotas', __('Change'))->icon('pencil')->when(fn ($row) => ($row['id'] ?? '') === 'quotas')->fields([
                    Field::text('max_size', __('Largest size'))->rules(['nullable', 'regex:'.self::SIZE])->placeholder('10 GB')
                        ->default(fn ($row) => self::size($row['max_size'] ?? null))->help(__('Empty: no limit. Garage refuses uploads that would go over it.')),
                    Field::number('max_objects', __('Most objects'))->rules(['nullable', 'integer', 'min:1'])
                        ->default(fn ($row) => $row['max_objects'] ?? null)->help(__('Empty: no limit.')),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Quotas saved.'), fn () => $api->put(self::bucket($scope['bucket']).'/quotas', [
                    'max_size' => self::bytes($data['max_size'] ?? null),
                    'max_objects' => $data['max_objects'] ?? null,
                ]))),
                Action::make('cors', __('Change'))->icon('pencil')->when(fn ($row) => ($row['id'] ?? '') === 'cors')->fields([
                    Field::textarea('origins', __('Websites'))->placeholder("https://app.example.com\nhttps://admin.example.com")
                        ->default(fn ($row) => implode("\n", (array) ($row['origins'] ?? [])))
                        ->help(__('One per line, https://host without a path, or *. Empty: no browser may reach the bucket. The rule replaces the one there.')),
                    Field::multiselect('methods', __('Methods'), self::METHODS)
                        ->default(fn ($row) => ($row['methods'] ?? []) ?: ['GET', 'HEAD']),
                    Field::text('headers', __('Request headers'))->placeholder('*')
                        ->default(fn ($row) => implode(', ', (array) ($row['headers'] ?? [])))->help(__('Comma separated; empty: every header.')),
                    Field::number('max_age', __('Preflight cache, seconds'))->rules(['nullable', 'integer', 'min:0', 'max:86400'])
                        ->default(fn ($row) => $row['max_age'] ?? 3600),
                ])->run(function (ApiClient $api, array $row, array $data, array $scope) {
                    $origins = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($data['origins'] ?? '')) ?: [])));

                    if ($origins === []) {
                        $api->put(self::bucket($scope['bucket']).'/cors', ['origins' => []]);

                        return __('CORS rule removed.');
                    }

                    $headers = array_values(array_filter(array_map('trim', explode(',', (string) ($data['headers'] ?? '')))));

                    $api->put(self::bucket($scope['bucket']).'/cors', array_filter([
                        'origins' => $origins,
                        'methods' => array_values((array) ($data['methods'] ?? [])) ?: ['GET', 'HEAD'],
                        'headers' => $headers ?: null,
                        'max_age' => $data['max_age'] ?? null,
                    ], fn ($value) => $value !== null));

                    return __('CORS rule saved.');
                }),
                Action::make('lifecycle', __('Change'))->icon('pencil')->when(fn ($row) => ($row['id'] ?? '') === 'lifecycle')->fields([
                    Field::number('expire_days', __('Delete objects after, days'))->rules(['nullable', 'integer', 'min:1', 'max:36500'])
                        ->default(fn ($row) => $row['expire_days'] ?? null)->help(__('Counted from each upload. Empty: objects never expire.')),
                    Field::text('expire_prefix', __('Only under this folder'))->placeholder('tmp/')
                        ->default(fn ($row) => $row['expire_prefix'] ?? null)->help(__('Empty: the whole bucket.')),
                    Field::number('abort_days', __('Remove unfinished uploads after, days'))->rules(['nullable', 'integer', 'min:1', 'max:365'])
                        ->default(fn ($row) => $row['abort_days'] ?? null),
                ])->danger(__('Save the expiry rules? Garage deletes the objects they cover once a day, for good.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Expiry rules saved.'), fn () => $api->put(self::bucket($scope['bucket']).'/lifecycle', [
                        'expire_days' => $data['expire_days'] ?? null,
                        'expire_prefix' => $data['expire_prefix'] ?? null,
                        'abort_days' => $data['abort_days'] ?? null,
                    ]))),
            ]);
    }

    /** The keys that reach one bucket, each with its scope. */
    private function bucketKeys(): Section
    {
        return Section::make('bucket-keys', __('Keys of the bucket'))
            ->icon('key')
            ->group(__('Storage'))
            ->childOf('buckets', 'bucket_name')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter(self::items(data_get($api->get(self::bucket($scope['bucket'])), 'data.keys')), 'is_array')))
            ->columns([
                Column::make('name', __('Key')),
                Column::make('id', __('Id'))->mono(),
                Column::make('scope', __('Scope'))->using(fn ($row) => self::scopes()[$row['scope'] ?? ''] ?? __('custom'))
                    ->badge([__('Full control') => 'red', __('Read and write') => 'amber', __('Write only') => 'amber', __('Read only') => 'green', '*' => 'zinc']),
                Column::make('permissions', __('Permissions'))->mono(),
            ])
            ->toolbar([
                Action::make('grant', __('Give a key access'))->icon('plus')->primary()->fields([
                    Field::select('key_id', __('Key'), fn (ApiClient $api) => self::keyOptions($api))->required(),
                    self::scopeField(),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Access given.'), fn () => $api->post(self::bucket($scope['bucket']).'/keys', ['key_id' => $data['key_id'], 'scope' => $data['scope']]))),
                Action::make('new-key', __('New key for this bucket'))->icon('sparkles')->fields([
                    Field::text('name', __('Name'))->required()->rules(['max:128', 'regex:'.self::KEY_NAME])->placeholder('shop-uploads'),
                    self::scopeField(),
                ])->run(function (ApiClient $api, array $row, array $data, array $scope) {
                    $made = $api->post('/keys', ['name' => $data['name'], 'access' => 'buckets', 'scope' => $data['scope'], 'buckets' => [$scope['bucket']]]);

                    return self::keyCreated($made, (string) $data['name']);
                }),
            ])
            ->actions([
                Action::make('scope', __('Change the scope'))->icon('adjustments-horizontal')->fields([
                    self::scopeField(default: fn ($row) => $row['scope'] ?? 'read-write'),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Scope changed.'), fn () => $api->post(self::bucket($scope['bucket']).'/keys', ['key_id' => $row['id'], 'scope' => $data['scope']]))),
                Action::make('revoke', __('Remove access'))->icon('minus-circle')
                    ->danger(__('Take this key off the bucket? What uses it loses access to it at once.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Access removed.'), fn () => $api->delete(self::bucket($scope['bucket']).'/keys/'.rawurlencode((string) $row['id']), ['read' => true, 'write' => true, 'owner' => true]))),
            ]);
    }

    /**
     * A key just made: the secret, once, and the buckets where the grant failed.
     *
     * @param  array<string, mixed>  $made
     */
    private static function keyCreated(array $made, string $name): Outcome
    {
        $failed = (array) ($made['failed_buckets'] ?? []);

        return Outcome::show(__('Key :name', ['name' => $made['name'] ?? $name]), array_filter([
            'id' => $made['id'] ?? null,
            'access' => $made['access'] ?? null,
            'scope' => $made['scope'] ?? null,
            'buckets' => $made['buckets'] ?? null,
            'failed_buckets' => $failed ?: null,
            'access_key_id' => data_get($made, 'credentials.access_key_id'),
            'secret_access_key' => data_get($made, 'credentials.secret_access_key'),
        ], fn ($value) => $value !== null && $value !== []), $failed !== []
            ? __('Key created, but not granted on :buckets: give it access from those buckets\' keys. The secret shows only now.', ['buckets' => implode(', ', $failed)])
            : __('Key created: the secret shows only now.'));
    }

    private function keys(): Section
    {
        return Section::make('keys', __('Keys'))
            ->icon('key')
            ->group(__('Storage'))
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items($api->get('/keys')['data'] ?? null), 'is_array')))
            ->columns([
                Column::make('name', __('Key')),
                Column::make('id', __('Id'))->mono(),
                Column::make('access', __('Reaches'))->using(fn ($row) => ($row['all_buckets'] ?? false)
                    ? __('every bucket · :scope', ['scope' => self::scopes()[$row['scope'] ?? ''] ?? __('custom')])
                    : __('bucket by bucket')),
                Column::make('created', __('Created'))->date(),
            ])
            ->toolbar([
                Action::make('create', __('New key'))->icon('plus')->primary()->fields([
                    Field::text('name', __('Name'))->required()->rules(['max:128', 'regex:'.self::KEY_NAME])->placeholder('shop-uploads'),
                    Field::select('access', __('Where it works'), [
                        'none' => __('Nowhere yet'),
                        'buckets' => __('The buckets chosen below'),
                        'all' => __('Every bucket, the ones created later too'),
                    ])->required()->default('none'),
                    self::scopeField(),
                    Field::multiselect('buckets', __('Buckets'), fn (ApiClient $api) => self::bucketOptions($api))->help(__('Only for the buckets chosen below.')),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $access = (string) $data['access'];

                    if ($access === 'buckets' && ($data['buckets'] ?? []) === []) {
                        throw new ConnectorException(__('Choose the buckets the key works on.'));
                    }

                    $made = $api->post('/keys', array_filter([
                        'name' => $data['name'],
                        'access' => $access,
                        'scope' => $access === 'none' ? null : $data['scope'],
                        'buckets' => $access === 'buckets' ? array_values((array) $data['buckets']) : null,
                    ], fn ($value) => $value !== null));

                    return self::keyCreated($made, (string) $data['name']);
                }),
            ])
            ->actions([
                Action::make('details', __('Buckets and scopes'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Key :name', ['name' => $row['name'] ?? $row['id']]), $api->get('/keys/'.rawurlencode((string) $row['id']))['data'] ?? [])),
                Action::make('all-buckets', __('One scope on every bucket'))->icon('globe-alt')->fields([
                    self::scopeField(default: fn ($row) => $row['scope'] ?? 'read'),
                ])->confirm(__('Give the key this scope on every bucket, and on each bucket created later?'))
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $done = (array) ($api->put('/keys/'.rawurlencode((string) $row['id']).'/all-buckets', ['scope' => $data['scope']])['data'] ?? []);
                        $failed = (array) ($done['failed_buckets'] ?? []);

                        return $failed === []
                            ? trans_choice('Applied to :count bucket, and to the new ones.|Applied to :count buckets, and to the new ones.', (int) ($done['buckets'] ?? 0))
                            : __('Applied, except on :buckets: try again from those buckets\' keys.', ['buckets' => implode(', ', $failed)]);
                    }),
                Action::make('stop-all', __('Stop following new buckets'))->icon('pause')->when(fn ($row) => ($row['all_buckets'] ?? false) === true)
                    ->confirm(__('Stop giving the key the buckets created from now on? What it reaches now stays.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('The key no longer follows new buckets.'), fn () => $api->delete('/keys/'.rawurlencode((string) $row['id']).'/all-buckets'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? What uses it loses its access.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/keys/'.rawurlencode((string) $row['id'])))),
            ]);
    }

    // ------------------------------------------------------------------ moving data

    /** Accounts at other S3 providers and SFTP servers, for transfers and syncs. */
    private function connections(): Section
    {
        $fields = fn (bool $change) => [
            Field::text('name', __('Name'))->required()->rules(['max:64'])->placeholder('R2 archive')->default(fn ($row) => $row['name'] ?? null),
            Field::select('provider', __('Provider'), fn (ApiClient $api) => self::providerOptions($api))->required()->default(fn ($row) => $row['provider'] ?? null),
            Field::text('endpoint', __('Endpoint'))->placeholder('https://<account-id>.r2.cloudflarestorage.com')
                ->default(fn ($row) => ($row['provider'] ?? '') === 'sftp' ? null : ($row['endpoint'] ?? null))->help(__('S3: every provider but AWS.')),
            Field::text('region', __('Region'))->placeholder('auto')->default(fn ($row) => $row['region'] ?? null),
            Field::text('access_key_id', __('Access key id'))->help($change ? __('S3: write it again, the panel shows it masked.') : __('S3 only.')),
            Field::password('secret_access_key', __('Secret access key'))->help($change ? __('Empty keeps the stored one.') : __('S3 only.')),
            Field::checkbox('path_style', __('Path-style addresses'))->default(fn ($row) => (bool) ($row['path_style'] ?? false))->help(__('MinIO, Garage, another Johnny.')),
            Field::text('host', __('SFTP host'))->placeholder('backup.example.com')
                ->default(fn ($row) => ($row['provider'] ?? '') === 'sftp' ? parse_url((string) ($row['endpoint'] ?? ''), PHP_URL_HOST) : null),
            Field::number('port', __('SFTP port'))->rules(['nullable', 'integer', 'min:1', 'max:65535'])
                ->default(fn ($row) => ($row['provider'] ?? '') === 'sftp' ? (parse_url((string) ($row['endpoint'] ?? ''), PHP_URL_PORT) ?: 22) : 22),
            Field::text('username', __('SFTP user'))->default(fn ($row) => ($row['provider'] ?? '') === 'sftp' ? ($row['access_key_id'] ?? null) : null),
            Field::password('password', __('SFTP password'))->help($change ? __('Empty keeps the stored secret.') : __('Or a private key below.')),
            Field::textarea('private_key', __('SFTP private key'))->help(__('PEM or OpenSSH, without a passphrase, instead of a password.')),
        ];

        return Section::make('connections', __('Connections'))
            ->icon('link')
            ->group(__('Moving data'))
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items(self::v2(fn () => $api->get('/connections'))['data'] ?? null), 'is_array')))
            ->columns([
                Column::make('name', __('Connection')),
                Column::make('provider_label', __('Provider')),
                Column::make('endpoint', __('Endpoint'))->mono(),
                Column::make('reachable', __('Reachable'))->using(fn ($row) => match ($row['reachable'] ?? null) {
                    true => __('yes'),
                    false => __('no'),
                    default => '',
                })->badge([__('yes') => 'green', __('no') => 'red', '*' => 'zinc']),
                Column::make('checked_at', __('Checked'))->date(),
            ])
            ->toolbar([
                Action::make('create', __('Add a connection'))->icon('plus')->primary()->fields($fields(false))
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $made = (array) ($api->post('/connections', self::connectionPayload($data))['data'] ?? []);

                        return self::connectionSaved($made, __('Connection saved.'));
                    }),
            ])
            ->actions([
                Action::make('check', __('Check'))->icon('signal')->run(function (ApiClient $api, array $row) {
                    $checked = (array) ($api->post('/connections/'.rawurlencode((string) $row['id']).'/check')['data'] ?? []);

                    if (($checked['reachable'] ?? false) !== true) {
                        throw new ConnectorException(__('The provider refused it: :reason', ['reason' => $checked['error'] ?? __('no details')]));
                    }

                    return __('Reachable: the credentials work.');
                }),
                Action::make('buckets', __('Buckets there'))->icon('archive-box')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Buckets of :name', ['name' => $row['name'] ?? '']), array_values(array_map(
                        fn ($bucket) => is_array($bucket) ? ($bucket['name'] ?? $bucket) : $bucket,
                        self::items($api->get('/connections/'.rawurlencode((string) $row['id']).'/buckets')['data'] ?? null),
                    )))),
                Action::make('edit', __('Change'))->icon('pencil')->fields($fields(true))
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $saved = (array) ($api->put('/connections/'.rawurlencode((string) $row['id']), self::connectionPayload($data))['data'] ?? []);

                        return self::connectionSaved($saved, __('Connection saved.'));
                    }),
                Action::make('delete', __('Delete'))->icon('trash')
                    ->danger(__('Delete the connection? Past transfers stay in the history; Johnny refuses while a transfer or a sync uses it.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Connection deleted.'), fn () => $api->delete('/connections/'.rawurlencode((string) $row['id'])))),
            ]);
    }

    /** One-off imports and exports, run by rclone on the panel's server. */
    private function transfers(): Section
    {
        return Section::make('transfers', __('Transfers'))
            ->icon('arrows-right-left')
            ->group(__('Moving data'))
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items(self::v2(fn () => $api->get('/transfers', ['limit' => 100]))['data'] ?? null), 'is_array')))
            ->columns(self::transferColumns())
            ->toolbar([
                Action::make('create', __('Import or export'))->icon('plus')->primary()->fields([
                    ...self::transferFields(),
                    Field::checkbox('dry_run', __('Dry run'))->help(__('List what would change without touching anything; Run again makes it real.')),
                ])->confirm(__('Start the transfer? A mirror deletes at the destination what the source no longer has.'))
                    ->run(fn (ApiClient $api, array $row, array $data) => self::transferOutcome(
                        (array) ($api->post('/transfers', self::transferPayload($data) + ['dry_run' => (bool) ($data['dry_run'] ?? false)])['data'] ?? []),
                        '',
                    )),
            ])
            ->actions(self::transferActions());
    }

    /** Transfers that repeat on an interval, run by the panel's scheduler. */
    private function syncs(): Section
    {
        return Section::make('syncs', __('Scheduled syncs'))
            ->icon('clock')
            ->group(__('Moving data'))
            ->list(function (ApiClient $api) {
                $answer = self::v2(fn () => $api->get('/syncs'));
                $syncs = array_values(array_filter(self::items($answer['data'] ?? null), 'is_array'));

                // a paused sync says so where the next run goes; with no cron, no run comes: said on
                // every row that would wait for one, and the list stays
                $stopped = ($answer['scheduler_running'] ?? true) === false;

                return array_map(fn (array $sync) => match (true) {
                    ($sync['enabled'] ?? false) !== true => ['next_run_at' => self::say('paused')] + $sync,
                    $stopped => ['next_run_at' => self::say('never: the panel\'s scheduler is not running (/etc/cron.d/johnny-panel)')] + $sync,
                    default => $sync,
                }, $syncs);
            })
            ->columns([
                Column::make('direction', __('Direction'))->badge(['import' => 'blue', 'export' => 'purple']),
                Column::make('connection_name', __('Connection')),
                Column::make('local_bucket', __('On Johnny'))->mono(),
                Column::make('remote_bucket', __('There'))->mono(),
                Column::make('mode', __('Mode'))->using(fn ($row) => ($row['mode'] ?? '') === 'sync' ? __('mirror') : ($row['mode'] ?? '')),
                Column::make('interval_label', __('Every')),
                Column::make('latest', __('Last run'))->using(fn ($row) => data_get($row, 'latest_run.status') ?? '')
                    ->badge(['completed' => 'green', 'running' => 'sky', 'pending' => 'amber', 'failed' => 'red', 'cancelled' => 'zinc']),
                Column::make('next_run_at', __('Next'))->date(),
            ])
            ->toolbar([
                Action::make('create', __('Schedule a sync'))->icon('plus')->primary()->fields([
                    ...self::transferFields(),
                    Field::number('interval_minutes', __('Every, minutes'))->required()->rules(['integer', 'min:1', 'max:525600'])->default(60)
                        ->help(__('15 a quarter of an hour, 60 an hour, 1440 a day, 10080 a week. Runs never overlap.')),
                ])->confirm(__('Schedule the sync? The first run starts at once; a mirror deletes at the destination what the source no longer has.'))
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Sync scheduled: the first run starts now.'), fn () => $api->post('/syncs', self::transferPayload($data) + ['interval_minutes' => (int) $data['interval_minutes']]))),
            ])
            ->actions([
                Action::make('runs', __('Runs'))->icon('queue-list')->opens('sync-runs', fn ($row) => ['sync' => $row['id'], 'sync_name' => '#'.$row['id'].' · '.($row['local_bucket'] ?? '')]),
                Action::make('run', __('Run now'))->icon('play')
                    ->run(fn (ApiClient $api, array $row) => self::transferOutcome((array) ($api->post('/syncs/'.rawurlencode((string) $row['id']).'/run')['data'] ?? []), '')),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ($row['enabled'] ?? false) === true)
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync paused.'), fn () => $api->put('/syncs/'.rawurlencode((string) $row['id']), ['enabled' => false]))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ($row['enabled'] ?? false) !== true)
                    ->confirm(__('Resume the sync? It runs right away, then on its interval.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync resumed: it runs now.'), fn () => $api->put('/syncs/'.rawurlencode((string) $row['id']), ['enabled' => true]))),
                Action::make('edit', __('Change'))->icon('pencil')->fields([
                    Field::number('interval_minutes', __('Every, minutes'))->required()->rules(['integer', 'min:1', 'max:525600'])->default(fn ($row) => $row['interval_minutes'] ?? 60),
                    Field::select('mode', __('Mode'), ['copy' => __('Copy: add new and changed files'), 'sync' => __('Mirror: also delete what the source no longer has')])->required()->default(fn ($row) => $row['mode'] ?? 'copy'),
                ])->confirm(__('Save the sync? A mirror deletes at the destination what the source no longer has.'))
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Sync saved.'), fn () => $api->put('/syncs/'.rawurlencode((string) $row['id']), [
                        'interval_minutes' => (int) $data['interval_minutes'],
                        'mode' => $data['mode'],
                    ]))),
                Action::make('delete', __('Delete'))->icon('trash')
                    ->danger(__('Delete the sync and its runs? Nothing it copied is touched.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Sync deleted.'), fn () => $api->delete('/syncs/'.rawurlencode((string) $row['id'])))),
            ]);
    }

    /** The latest runs of one sync (30 are kept), newest first. */
    private function syncRuns(): Section
    {
        return Section::make('sync-runs', __('Runs of the sync'))
            ->icon('queue-list')
            ->childOf('syncs', 'sync_name')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter(self::items($api->get('/transfers', ['sync_id' => (int) $scope['sync'], 'limit' => 30])['data'] ?? null), 'is_array')))
            ->columns(self::transferColumns())
            ->actions(array_values(array_filter(self::transferActions(), fn (Action $action) => in_array($action->key, ['progress', 'cancel'], true))));
    }
}
