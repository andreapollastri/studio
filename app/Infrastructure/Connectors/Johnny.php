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

/**
 * Johnny (https://johnny.web.ap.it), S3-compatible storage on one's own server
 * (Garage behind Caddy), through the REST API of its panel: buckets, the keys
 * that reach each of them and with which permissions, the keys themselves, and
 * a bucket provisioned with its own key in one call. One panel per account.
 */
final class Johnny extends Connector
{
    /** Bucket names as Johnny accepts them. */
    private const BUCKET = '/^[a-z0-9][a-z0-9._-]{1,254}$/';

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
        return __('S3-compatible storage on your own server: buckets, the keys that reach them and their permissions, a bucket with its own key in one step, one panel per account.');
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
            $this->bucketKeys(),
            $this->keys(),
        ];
    }

    private static function bucket(mixed $name): string
    {
        return '/buckets/'.rawurlencode((string) $name);
    }

    /** @return array<string, string> key id => "name · id" */
    private static function keyOptions(ApiClient $api): array
    {
        return collect(self::items($api->get('/keys')['data'] ?? null))
            ->filter(fn ($key) => is_array($key) && isset($key['id']))
            ->mapWithKeys(fn (array $key) => [(string) $key['id'] => ($key['name'] ?? '?').' · '.$key['id']])
            ->all();
    }

    /**
     * read, write and owner as Johnny takes them; at least one, or Johnny refuses.
     *
     * @param  array<string, mixed>  $data
     * @return array{read: bool, write: bool, owner: bool}
     */
    private static function permissions(array $data): array
    {
        $flags = ['read' => (bool) ($data['read'] ?? false), 'write' => (bool) ($data['write'] ?? false), 'owner' => (bool) ($data['owner'] ?? false)];

        if (! in_array(true, $flags, true)) {
            throw new ConnectorException(__('Tick at least one of read, write and owner.'));
        }

        return $flags;
    }

    /** @return list<Field> */
    private static function permissionFields(bool $read = true): array
    {
        return [
            Field::checkbox('read', __('Read'))->default($read),
            Field::checkbox('write', __('Write')),
            Field::checkbox('owner', __('Owner'))->help(__('Owner manages the bucket itself: its settings and who reaches it.')),
        ];
    }

    private function buckets(): Section
    {
        $keys = fn ($row) => ['bucket' => $row['name'], 'bucket_name' => $row['name']];

        return Section::make('buckets', __('Buckets'))
            ->icon('archive-box')
            ->id('name')
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items($api->get('/buckets')['data'] ?? null), 'is_array')))
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:'.self::BUCKET])->placeholder('customer-orders')
                    ->help(__('Lower case letters, digits, dots, dashes and underscores. The panel\'s own key gets every permission on it.')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Bucket :name created.', ['name' => $data['name']]), fn () => $api->post('/buckets', ['name' => $data['name']])))
            ->toolbar([
                Action::make('provision', __('Bucket with its key'))->icon('sparkles')->fields([
                    Field::text('bucket', __('Bucket name'))->rules(['regex:'.self::BUCKET])->placeholder(__('empty: a random b-… name')),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $made = $api->post('/buckets/provision', array_filter(['bucket' => $data['bucket'] ?? null]));

                    return Outcome::show(__('Bucket :name and its key', ['name' => $made['bucket'] ?? '']), [
                        'bucket' => $made['bucket'] ?? null,
                        'endpoint' => $made['endpoint'] ?? null,
                        'region' => $made['region'] ?? null,
                        'path_style' => $made['path_style'] ?? null,
                        'key' => $made['key_name'] ?? null,
                        'access_key_id' => data_get($made, 'credentials.access_key_id'),
                        'secret_access_key' => data_get($made, 'credentials.secret_access_key'),
                        'env' => $made['env'] ?? null,
                    ], __('Provisioned: the secret key shows only now.'));
                }),
            ])
            ->actions([
                Action::make('keys', __('Keys'))->icon('key')->opens('bucket-keys', $keys),
                Action::make('details', __('Details'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Bucket :name', ['name' => $row['name']]), $api->get(self::bucket($row['name']))['data'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ($row['name'] ?? '') !== 'default')
                    ->danger(__('Delete the bucket? It must be empty.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Bucket deleted.'), fn () => $api->delete(self::bucket($row['name'])))),
            ]);
    }

    /** The keys that reach one bucket, with their permissions. */
    private function bucketKeys(): Section
    {
        return Section::make('bucket-keys', __('Keys of the bucket'))
            ->icon('key')
            ->childOf('buckets', 'bucket_name')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter(self::items(data_get($api->get(self::bucket($scope['bucket'])), 'data.keys')), 'is_array')))
            ->columns([
                Column::make('name', __('Key')),
                Column::make('id', __('Id'))->mono(),
                Column::make('permissions', __('Permissions'))->mono(),
            ])
            ->toolbar([
                Action::make('grant', __('Give a key access'))->icon('plus')->primary()->fields([
                    Field::select('key_id', __('Key'), fn (ApiClient $api) => self::keyOptions($api))->required(),
                    ...self::permissionFields(),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Access given.'), fn () => $api->post(self::bucket($scope['bucket']).'/keys', ['key_id' => $data['key_id'], ...self::permissions($data)]))),
            ])
            ->actions([
                Action::make('revoke', __('Take permissions away'))->icon('minus-circle')->fields(self::permissionFields())
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Permissions taken away.'), fn () => $api->delete(self::bucket($scope['bucket']).'/keys/'.rawurlencode((string) $row['id']), self::permissions($data)))),
            ]);
    }

    private function keys(): Section
    {
        return Section::make('keys', __('Keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => array_values(array_filter(self::items($api->get('/keys')['data'] ?? null), 'is_array')))
            ->columns([
                Column::make('name', __('Key')),
                Column::make('id', __('Id'))->mono(),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New key'), [
                Field::text('name', __('Name'))->required()->rules(['max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'])->placeholder('shop-uploads'),
            ], function (ApiClient $api, array $row, array $data) {
                $made = $api->post('/keys', ['name' => $data['name']]);

                return Outcome::show(__('Key :name', ['name' => $made['name'] ?? $data['name']]), [
                    'id' => $made['id'] ?? null,
                    'access_key_id' => data_get($made, 'credentials.access_key_id'),
                    'secret_access_key' => data_get($made, 'credentials.secret_access_key'),
                ], __('Key created: the secret shows only now. Give it access to a bucket from the bucket\'s keys.'));
            })
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? What uses it loses its access.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/keys/'.rawurlencode((string) $row['id'])))),
            ]);
    }
}
