<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Action;
use App\Infrastructure\ApiClient;
use App\Infrastructure\Column;
use App\Infrastructure\Connector;
use App\Infrastructure\Field;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;

/**
 * Boogle (https://boogle.web.ap.it), the exception tracker and uptime
 * monitor, one instance per account through its admin API: exceptions and
 * their status, projects with their installation keys, groups.
 */
final class Boogle extends Connector
{
    private const STATUSES = ['OPEN' => 'Open', 'READ' => 'Read', 'FIXED' => 'Fixed', 'DONE' => 'Done'];

    public function key(): string
    {
        return 'boogle';
    }

    public function label(): string
    {
        return 'Boogle';
    }

    public function description(): string
    {
        return __('Exceptions and outages of the apps Boogle watches, their status, projects with their installation keys and groups, one instance per account.');
    }

    public function icon(): string
    {
        return 'bug-ant';
    }

    public function docs(): string
    {
        return 'https://boogle.web.ap.it/';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Address'))->required()->placeholder('https://boogle.example.com'),
            Field::password('token', __('API token'))->required()
                ->help(__('Profile → API tokens on the Boogle panel, signed in as an administrator.')),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient(self::instanceUrl($credentials['url'] ?? '').'/api/admin', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $projects = $api->get('/projects');

        return 'ok · '.trans_choice(':count project|:count projects', (int) ($projects['total'] ?? count((array) ($projects['data'] ?? []))));
    }

    public function sections(): array
    {
        return [
            $this->exceptions('exceptions'),
            $this->projects(),
            $this->exceptions('project-exceptions'),
            $this->groups(),
        ];
    }

    /**
     * Every page of a Laravel paginator: next_page_url until there is none.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $maxPages = 20): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            array_filter($query, fn ($value) => $value !== null && $value !== ''),
            fn (array $answer) => array_values((array) ($answer['data'] ?? [])),
            fn (array $answer) => is_string($answer['next_page_url'] ?? null) ? $answer['next_page_url'] : null,
            $maxPages,
        );
    }

    /** @return array<string, string> */
    private static function projectOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/projects'))->mapWithKeys(fn ($p) => [(string) $p['id'] => (string) $p['title']])->all();
    }

    /** @return array<string, string> */
    private static function groupOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/groups'))->mapWithKeys(fn ($g) => [(string) $g['id'] => (string) $g['title']])->all();
    }

    /** All exceptions, or (as a child of a project) the project's. */
    private function exceptions(string $key): Section
    {
        $child = $key === 'project-exceptions';
        $project = fn (array $row, array $scope) => $scope['project'] ?? $row['project_id'];

        $section = Section::make($key, __('Exceptions'))
            ->icon('bug-ant')
            ->filters([
                Field::select('status', __('Status'), ['' => __('Any'), ...self::STATUSES])->default('OPEN'),
                ...($child ? [] : [Field::select('project_id', __('Project'), fn (ApiClient $api) => ['' => __('Any'), ...self::projectOptions($api)])]),
            ])
            ->list(fn (ApiClient $api, array $scope) => $child
                ? self::every($api, '/projects/'.$scope['project'].'/exceptions', ['status' => $scope['status'] ?? null], 10)
                : self::every($api, '/exceptions', ['status' => $scope['status'] ?? null, 'project_id' => $scope['project_id'] ?? null], 10))
            ->columns([
                Column::make('issue_code', __('Issue'))->mono(),
                Column::make('exception', __('Exception'))->mono(),
                Column::make('error', __('Message')),
                ...($child ? [] : [Column::make('project.title', __('Project'))]),
                Column::make('status', __('Status'))->badge(['open' => 'red', 'read' => 'yellow', 'fixed' => 'blue', 'done' => 'green']),
                Column::make('created_at', __('When'))->date(),
            ])
            ->actions([
                Action::make('details', __('Details'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show(($row['issue_code'] ?? '').' '.($row['exception'] ?? ''), $api->get('/projects/'.$project($row, $scope).'/exceptions/'.$row['id']))),
                ...array_map(fn (string $status, string $label) => Action::make('status-'.strtolower($status), __('Mark as :status', ['status' => __($label)]))
                    ->icon(match ($status) {
                        'OPEN' => 'arrow-uturn-left', 'READ' => 'eye', 'FIXED' => 'wrench', default => 'check'
                    })
                    ->when(fn ($row) => ($row['status'] ?? '') !== $status)
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Marked as :status.', ['status' => __($label)]), fn () => $api->patch('/projects/'.$project($row, $scope).'/exceptions/'.$row['id'].'/status', ['status' => $status]))),
                    array_keys(self::STATUSES), self::STATUSES),
                Action::make('comment', __('Change status with a note'))->icon('chat-bubble-left-ellipsis')->fields([
                    Field::select('status', __('Status'), self::STATUSES)->required()->default(fn ($row) => $row['status'] ?? 'READ'),
                    Field::textarea('comment', __('Note')),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Status saved.'), fn () => $api->patch('/projects/'.$project($row, $scope).'/exceptions/'.$row['id'].'/status', array_filter($data)))),
            ]);

        return $child ? $section->childOf('projects', 'project_title') : $section;
    }

    private function projects(): Section
    {
        $fields = [
            Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? null),
            Field::text('url', __('Address'))->placeholder('https://app.example.com')->default(fn ($row) => $row['url'] ?? null),
            Field::checkbox('uptime_enabled', __('Watch uptime'))->default(fn ($row) => (bool) ($row['uptime_enabled'] ?? false)),
            Field::text('uptime_url', __('Uptime URL'))->placeholder('https://app.example.com/up')->default(fn ($row) => $row['uptime_url'] ?? null),
            Field::select('group_id', __('Group'), fn (ApiClient $api) => ['' => __('None'), ...self::groupOptions($api)])->default(fn ($row) => $row['group_id'] ?? ''),
            Field::textarea('description', __('Description'))->default(fn ($row) => $row['description'] ?? null),
        ];
        $body = fn (array $data) => [...$data, 'group_id' => $data['group_id'] ?: null];

        return Section::make('projects', __('Projects'))
            ->icon('folder')
            ->list(fn (ApiClient $api) => self::every($api, '/projects'))
            ->columns([
                Column::make('title', __('Project')),
                Column::make('url', __('Address'))->mono(),
                Column::make('group.title', __('Group')),
                Column::make('uptime_enabled', __('Uptime'))->bool(),
            ])
            ->create(__('New project'), $fields, function (ApiClient $api, array $row, array $data) use ($body) {
                $project = (array) ($api->post('/projects', $body($data))['data'] ?? []);

                return Outcome::show(__('Installation of :project', ['project' => $data['title']]), self::installation($api, $project), __('Project created.'));
            })
            ->actions([
                Action::make('exceptions', __('Exceptions'))->icon('bug-ant')->opens('project-exceptions', fn ($row) => ['project' => $row['id'], 'project_title' => $row['title']]),
                Action::make('install', __('Installation keys'))->icon('key')->run(fn (ApiClient $api, array $row) => Outcome::show(__('Installation of :project', ['project' => $row['title']]), self::installation($api, $row))),
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields)
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Project saved.'), fn () => $api->patch('/projects/'.$row['id'], $body($data)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the project and its exceptions?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Project deleted.'), fn () => $api->delete('/projects/'.$row['id']))),
            ]);
    }

    /**
     * What an app needs to report to Boogle.
     *
     * @param  array<mixed>  $project
     * @return array<string, mixed>
     */
    private static function installation(ApiClient $api, array $project): array
    {
        return [
            'key' => $project['key'] ?? null,
            'token' => $project['api_token'] ?? null,
            'endpoint' => preg_replace('#/api/admin$#', '/api/log', $api->baseUrl),
        ];
    }

    private function groups(): Section
    {
        $fields = [
            Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? null),
            Field::text('issue_prefix', __('Issue prefix'))->placeholder('SITE')->default(fn ($row) => $row['issue_prefix'] ?? null)->help(__('SITE makes issues #SITE1, #SITE2…; empty is BUG; OUT is for uptime')),
            Field::textarea('description', __('Description'))->default(fn ($row) => $row['description'] ?? null),
        ];

        return Section::make('groups', __('Groups'))
            ->icon('rectangle-group')
            ->list(fn (ApiClient $api) => self::every($api, '/groups'))
            ->columns([
                Column::make('title', __('Group')),
                Column::make('issue_prefix', __('Prefix'))->mono(),
                Column::make('projects_count', __('Projects')),
                Column::make('description', __('Description')),
            ])
            ->create(__('New group'), $fields, fn (ApiClient $api, array $row, array $data) => tap(__('Group created.'), fn () => $api->post('/groups', $data)))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields)
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Group saved.'), fn () => $api->patch('/groups/'.$row['id'], $data))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the group? Its projects stay.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Group deleted.'), fn () => $api->delete('/groups/'.$row['id']))),
            ]);
    }
}
