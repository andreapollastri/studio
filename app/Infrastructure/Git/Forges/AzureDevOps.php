<?php

namespace App\Infrastructure\Git\Forges;

use App\Infrastructure\Action;
use App\Infrastructure\Column;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Field;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\GitClient;
use App\Infrastructure\Git\Thread;
use App\Infrastructure\Outcome;
use App\Infrastructure\Section;
use Illuminate\Support\Str;

/**
 * Azure DevOps Services, the installation's organization, with the person's
 * own personal access token: pull requests waiting for their vote, work
 * items assigned to them and, repository by repository, pull requests,
 * branches and pipelines.
 */
final class AzureDevOps extends Forge
{
    private const VERSION = '7.1';

    /** How many projects are searched for pull requests when no project is picked. */
    private const PROJECTS = 20;

    private const ZERO = '0000000000000000000000000000000000000000';

    /** The votes on a pull request, by name: their number and their label. */
    private const VOTES = [
        'approve' => [10, 'Approve'],
        'suggestions' => [5, 'Approve with suggestions'],
        'reset' => [0, 'Reset my vote'],
        'wait' => [-5, 'Wait for author'],
        'reject' => [-10, 'Reject'],
    ];

    private const CLOSED = "'Closed', 'Done', 'Removed', 'Resolved', 'Completed', 'Cut'";

    public function key(): string
    {
        return 'azure';
    }

    public function label(): string
    {
        return 'Azure DevOps';
    }

    public function description(): string
    {
        return __('Pull requests waiting for your vote, work items assigned to you and, for each repository, branches and pipelines: vote, complete, comment and triage from Studio.');
    }

    public function icon(): string
    {
        return 'cube-transparent';
    }

    public function docs(): string
    {
        return 'https://learn.microsoft.com/rest/api/azure/devops/';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Organization'))->required()->placeholder('https://dev.azure.com/organization'),
            Field::password('token', __('Personal access token'))->required()
                ->help(__('The same token clones, pushes and drives this page: see below.')),
        ];
    }

    public function client(array $credentials): GitClient
    {
        $url = self::instanceUrl($credentials['url'] ?? '');

        if (preg_match('#^https://([a-z0-9-]+)\.visualstudio\.com#i', $url, $m)) {
            $url = 'https://dev.azure.com/'.$m[1];
        }

        return new GitClient($url, ['Authorization' => 'Basic '.base64_encode(':'.self::string($credentials, 'token'))]);
    }

    public function verify(object $api): string
    {
        /** @var GitClient $api */
        return 'ok · '.(self::me($api)['name'] ?: '?');
    }

    public function host(array $credentials): string
    {
        $url = $this->client($credentials)->baseUrl;

        return 'dev.azure.com/'.strtolower(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))[0]);
    }

    public function gitUsername(string $login): string
    {
        // Azure DevOps ignores the user name when the password is a personal access token.
        return $login !== '' ? $login : 'studio';
    }

    public function identity(GitClient $api): array
    {
        $me = self::me($api);

        return ['login' => $me['email'] ?: $me['name'], 'name' => $me['name'] ?: null, 'email' => $me['email'] ?: null];
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->pulls('pulls'),
            $this->workItems(),
            $this->repositories(),
            $this->pulls('repo-pulls'),
            $this->branches(),
            $this->pipelines(),
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    /** A path with the API version every Azure DevOps call wants. */
    private static function v(string $path, string $version = self::VERSION): string
    {
        return $path.(str_contains($path, '?') ? '&' : '?').'api-version='.$version;
    }

    private static function segment(mixed $value): string
    {
        return rawurlencode((string) $value);
    }

    /** @return array{id: string, name: string, email: string} */
    private static function me(GitClient $api): array
    {
        // connectionData answers for any valid token of the organization, whatever its scopes; it takes no api-version.
        $me = (array) ($api->remember('user', fn () => $api->get('/_apis/connectionData'))['authenticatedUser'] ?? []);

        return [
            'id' => (string) ($me['id'] ?? ''),
            'name' => (string) ($me['providerDisplayName'] ?? ''),
            'email' => (string) data_get($me, 'properties.Account.$value', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function values(GitClient $api, string $path, array $query = []): array
    {
        return array_values(array_filter((array) ($api->get(self::v($path), $query)['value'] ?? []), 'is_array'));
    }

    /** @return array<string, string> */
    private static function projectOptions(GitClient $api): array
    {
        return collect(self::values($api, '/_apis/projects', ['$top' => 200]))->sortBy('name')
            ->mapWithKeys(fn ($p) => [(string) $p['id'] => (string) $p['name']])->all();
    }

    /** @return array<string, string> */
    private static function branchOptions(GitClient $api, mixed $project, mixed $repo): array
    {
        return collect(self::values($api, '/'.self::segment($project).'/_apis/git/repositories/'.self::segment($repo).'/refs', ['filter' => 'heads/']))
            ->mapWithKeys(fn ($r) => [Str::after((string) $r['name'], 'refs/heads/') => Str::after((string) $r['name'], 'refs/heads/')])->all();
    }

    private static function branch(mixed $ref): string
    {
        return Str::after((string) $ref, 'refs/heads/');
    }

    /**
     * @param  array<string, mixed>  $pull
     * @return array<string, mixed>
     */
    private static function pull(GitClient $api, array $pull): array
    {
        $project = (string) data_get($pull, 'repository.project.name', '');
        $repo = (string) data_get($pull, 'repository.name', '');

        return [
            ...$pull,
            '_project' => (string) data_get($pull, 'repository.project.id', ''),
            '_repo' => (string) data_get($pull, 'repository.id', ''),
            '_ref' => $repo.'!'.($pull['pullRequestId'] ?? ''),
            '_web' => $api->baseUrl.'/'.self::segment($project).'/_git/'.self::segment($repo).'/pullrequest/'.($pull['pullRequestId'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function pullUrl(array $row, string $suffix = ''): string
    {
        return '/'.self::segment($row['_project']).'/_apis/git/repositories/'.self::segment($row['_repo']).'/pullRequests/'.$row['pullRequestId'].$suffix;
    }

    /** @param  array<string, mixed>  $row */
    private static function state(array $row): string
    {
        return ($row['status'] ?? '') === 'active' && (bool) ($row['isDraft'] ?? false) ? 'draft' : (string) ($row['status'] ?? '');
    }

    private static function vote(mixed $vote): string
    {
        return match ((int) $vote) {
            10 => __('approved'),
            5 => __('approved with suggestions'),
            -5 => __('waiting for author'),
            -10 => __('rejected'),
            default => __('no vote'),
        };
    }

    /**
     * Pull requests of one project, or of the first projects when none is picked.
     *
     * @param  array<string, string>  $criteria
     * @return list<array<string, mixed>>
     */
    private static function pullsOf(GitClient $api, ?string $project, array $criteria): array
    {
        $projects = filled($project) ? [$project] : array_slice(array_keys(self::projectOptions($api)), 0, self::PROJECTS);
        $rows = [];

        foreach ($projects as $id) {
            foreach (self::values($api, '/'.self::segment($id).'/_apis/git/pullrequests', ['$top' => 100, ...$criteria]) as $pull) {
                $rows[] = self::pull($api, $pull);
            }
        }

        return array_values(collect($rows)->sortByDesc('creationDate')->all());
    }

    /**
     * Work items a WIQL query finds, with the fields the list shows.
     *
     * @return list<array<string, mixed>>
     */
    private static function query(GitClient $api, string $wiql, ?string $project = null, int $top = 100): array
    {
        $prefix = filled($project) ? '/'.self::segment($project) : '';
        $ids = array_slice(array_column((array) ($api->post(self::v($prefix.'/_apis/wit/wiql?$top='.$top), ['query' => $wiql])['workItems'] ?? []), 'id'), 0, $top);

        if ($ids === []) {
            return [];
        }

        $items = self::values($api, '/_apis/wit/workitems', [
            'ids' => implode(',', $ids),
            'fields' => 'System.Id,System.Title,System.State,System.WorkItemType,System.TeamProject,System.AssignedTo,System.ChangedDate,System.Tags',
        ]);
        $order = array_flip($ids);

        // Field names have dots (System.Title): flat keys, so dot paths reach them.
        return array_values(collect($items)
            ->map(function ($item) use ($api) {
                $fields = (array) ($item['fields'] ?? []);

                return [
                    'id' => $item['id'],
                    'title' => $fields['System.Title'] ?? '',
                    'type' => $fields['System.WorkItemType'] ?? '',
                    'state' => $fields['System.State'] ?? '',
                    'project' => $fields['System.TeamProject'] ?? '',
                    'assigned' => data_get($fields['System.AssignedTo'] ?? null, 'displayName'),
                    'tags' => $fields['System.Tags'] ?? null,
                    'changed' => $fields['System.ChangedDate'] ?? null,
                    '_web' => $api->baseUrl.'/'.self::segment($fields['System.TeamProject'] ?? '').'/_workitems/edit/'.$item['id'],
                ];
            })
            ->sortBy(fn ($item) => $order[$item['id']] ?? PHP_INT_MAX)->all());
    }

    /**
     * @param  list<array{op: string, path: string, value?: mixed}>  $operations
     * @return array<mixed>
     */
    private static function patchItem(GitClient $api, string $path, array $operations, string $method = 'PATCH'): array
    {
        return $api->sendBody($method, self::v($path), (string) json_encode($operations), 'application/json-patch+json');
    }

    /* ---------------------------------------------------------------- overview */

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (GitClient $api) {
            $me = self::me($api);
            $reviews = self::pullsOf($api, null, ['searchCriteria.reviewerId' => $me['id'], 'searchCriteria.status' => 'active']);
            $mine = self::pullsOf($api, null, ['searchCriteria.creatorId' => $me['id'], 'searchCriteria.status' => 'active']);
            $items = self::query($api, 'SELECT [System.Id] FROM WorkItems WHERE [System.AssignedTo] = @Me AND [System.State] NOT IN ('.self::CLOSED.') ORDER BY [System.ChangedDate] DESC', null, 50);
            $titles = fn (array $rows) => collect($rows)->take(10)->mapWithKeys(fn ($r) => [$r['_ref'] => (string) ($r['title'] ?? '')])->all();

            return [
                __('Waiting for my vote (:count)', ['count' => count($reviews)]) => $titles($reviews),
                __('My active pull requests (:count)', ['count' => count($mine)]) => $titles($mine),
                __('Work items assigned to me (:count)', ['count' => count($items) >= 50 ? '50+' : count($items)]) => collect($items)->take(10)
                    ->mapWithKeys(fn ($i) => [$i['type'].' '.$i['id'] => (string) $i['title']])->all(),
                __('Account') => [
                    __('Signed in as') => $me['name'].($me['email'] !== '' ? ' ('.$me['email'].')' : ''),
                    __('Organization') => $api->baseUrl,
                ],
            ];
        })->icon('home')->description(__('What waits for you in the organization.'));
    }

    /* ---------------------------------------------------------------- pull requests */

    private function pulls(string $key): Section
    {
        $child = $key === 'repo-pulls';

        $section = Section::make($key, __('Pull requests'))
            ->icon('git-pull-request')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [
                    Field::select('which', __('Which'), ['reviewer' => __('Waiting for my vote'), 'creator' => __('Opened by me'), '' => __('All')])->default('reviewer'),
                    Field::select('project', __('Project'), fn (GitClient $api) => self::projectOptions($api))->help(__('the first :count', ['count' => self::PROJECTS])),
                ]),
                Field::select('status', __('State'), ['active' => __('Active'), 'completed' => __('Completed'), 'abandoned' => __('Abandoned'), 'all' => __('Any')])->default('active'),
            ])
            ->list(function (GitClient $api, array $scope) use ($child) {
                $criteria = ['searchCriteria.status' => ($scope['status'] ?? '') ?: 'active'];

                if ($child) {
                    return array_map(fn ($p) => self::pull($api, $p), self::values($api,
                        '/'.self::segment($scope['project']).'/_apis/git/repositories/'.self::segment($scope['repo']).'/pullrequests', ['$top' => 200, ...$criteria]));
                }

                $criteria += match ($scope['which'] ?? 'reviewer') {
                    'reviewer' => ['searchCriteria.reviewerId' => self::me($api)['id']],
                    'creator' => ['searchCriteria.creatorId' => self::me($api)['id']],
                    default => [],
                };

                return self::pullsOf($api, $scope['project'] ?? null, $criteria);
            })
            ->columns([
                Column::make('_ref', __('Pull request'))->mono()->using(fn ($row) => $child ? '!'.$row['pullRequestId'] : $row['_ref']),
                Column::make('title', __('Title'))->link('_web'),
                Column::make('sourceRefName', __('Branch'))->mono()->using(fn ($row) => self::branch($row['sourceRefName'] ?? '').' → '.self::branch($row['targetRefName'] ?? '')),
                Column::make('createdBy.displayName', __('Author')),
                Column::make('reviewers', __('Votes'))->using(fn ($row) => array_map(fn ($r) => ($r['displayName'] ?? '?').': '.self::vote($r['vote'] ?? 0),
                    array_filter((array) ($row['reviewers'] ?? []), fn ($r) => (int) ($r['vote'] ?? 0) !== 0))),
                Column::make('status', __('State'))->using(fn ($row) => self::state($row))->badge(['active' => 'green', 'draft' => 'zinc', 'completed' => 'purple', 'abandoned' => 'red']),
                Column::make('creationDate', __('Opened'))->date(),
            ])
            ->actions($this->pullActions());

        if (! $child) {
            return $section;
        }

        return $section->childOf('repositories', 'repo_name')->create(__('New pull request'), self::pullFields(true),
            fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope, $data));
    }

    /** @return list<Field> */
    private static function pullFields(bool $withSource): array
    {
        $branches = fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project'], $scope['repo']);

        return [
            ...($withSource ? [Field::select('source', __('From branch'), $branches)->required()] : []),
            Field::select('target', __('Into branch'), $branches)->required(),
            Field::text('title', __('Title'))->required()->default(fn ($row) => $row['_name'] ?? ''),
            Field::textarea('description', __('Description'))->help(__('Markdown.')),
            Field::checkbox('isDraft', __('Draft')),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $data
     */
    private static function openPull(GitClient $api, array $scope, array $data): Outcome
    {
        if ($data['source'] === $data['target']) {
            throw new ConnectorException(__('Pick two different branches.'));
        }

        $pull = $api->post(self::v('/'.self::segment($scope['project']).'/_apis/git/repositories/'.self::segment($scope['repo']).'/pullrequests'), array_filter([
            'sourceRefName' => 'refs/heads/'.$data['source'],
            'targetRefName' => 'refs/heads/'.$data['target'],
            'title' => $data['title'],
            'description' => $data['description'],
            'isDraft' => (bool) $data['isDraft'],
        ], fn ($v) => $v !== null));

        return Outcome::show(__('Pull request !:id', ['id' => $pull['pullRequestId'] ?? '?']), self::pull($api, $pull)['_web'], __('Pull request opened.'));
    }

    /** @return list<Action> */
    private function pullActions(): array
    {
        $active = fn ($row) => ($row['status'] ?? '') === 'active';

        return [
            Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row) {
                $pull = $api->get(self::v(self::pullUrl($row)));
                $comments = collect(self::values($api, self::pullUrl($row, '/threads')))
                    ->reject(fn ($t) => (bool) ($t['isDeleted'] ?? false))
                    ->flatMap(fn ($t) => collect((array) ($t['comments'] ?? []))
                        ->filter(fn ($c) => ($c['commentType'] ?? '') === 'text' && ! ($c['isDeleted'] ?? false))
                        ->map(fn ($c) => [
                            'author' => data_get($c, 'author.displayName').(data_get($t, 'threadContext.filePath') ? ' · '.data_get($t, 'threadContext.filePath') : ''),
                            'at' => $c['publishedDate'] ?? null,
                            'body' => $c['content'] ?? null,
                        ]))
                    ->sortBy('at')->values()->all();

                return Outcome::show($row['_ref'].' '.($row['title'] ?? ''), Thread::text([
                    __('State') => self::state($pull),
                    __('Author') => data_get($pull, 'createdBy.displayName'),
                    __('Opened') => Thread::when($pull['creationDate'] ?? null),
                    __('Branches') => self::branch($pull['sourceRefName'] ?? '').' → '.self::branch($pull['targetRefName'] ?? ''),
                    __('Merge status') => $pull['mergeStatus'] ?? null,
                    __('Reviewers') => array_map(fn ($r) => ($r['displayName'] ?? '?').' ('.self::vote($r['vote'] ?? 0).(($r['isRequired'] ?? false) ? ', '.__('required') : '').')', (array) ($pull['reviewers'] ?? [])),
                    __('Completes by itself') => isset($pull['autoCompleteSetBy']),
                ], $pull['description'] ?? null, $comments));
            }),
            Action::make('files', __('Files changed'))->icon('document-text')->run(function (GitClient $api, array $row) {
                $iterations = self::values($api, self::pullUrl($row, '/iterations'));
                $last = end($iterations)['id'] ?? null;

                if ($last === null) {
                    return Outcome::show(__('Files of :ref', ['ref' => $row['_ref']]), __('No changes.'));
                }

                $changes = (array) ($api->get(self::v(self::pullUrl($row, '/iterations/'.$last.'/changes')), ['$top' => 2000])['changeEntries'] ?? []);

                return Outcome::show(__('Files of :ref', ['ref' => $row['_ref']]), collect($changes)
                    ->map(fn ($c) => str_pad((string) ($c['changeType'] ?? ''), 10).' '.data_get($c, 'item.path'))->implode("\n") ?: __('No changes.'));
            }),
            Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                Field::textarea('body', __('Comment'))->required()->help(__('Markdown.')),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Comment added.'), fn () => $api->post(self::v(self::pullUrl($row, '/threads')), [
                'comments' => [['parentCommentId' => 0, 'content' => $data['body'], 'commentType' => 1]], 'status' => 1,
            ]))),
            Action::make('approve', __('Approve'))->icon('check-badge')->when($active)
                ->run(fn (GitClient $api, array $row) => tap(__('Approved.'), fn () => $api->put(self::v(self::pullUrl($row, '/reviewers/'.self::me($api)['id'])), ['vote' => 10]))),
            Action::make('vote', __('Vote'))->icon('hand-thumb-up')->when($active)->fields([
                Field::select('vote', __('Vote'), array_map(fn (array $vote) => (string) __($vote[1]), self::VOTES))->required()->default('approve'),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Vote saved.'), fn () => $api->put(self::v(self::pullUrl($row, '/reviewers/'.self::me($api)['id'])), ['vote' => self::VOTES[$data['vote']][0] ?? 0]))),
            Action::make('publish', __('Publish the draft'))->icon('megaphone')->when(fn ($row) => $active($row) && (bool) ($row['isDraft'] ?? false))
                ->run(fn (GitClient $api, array $row) => tap(__('Published: reviewers are notified.'), fn () => $api->patch(self::v(self::pullUrl($row)), ['isDraft' => false]))),
            Action::make('complete', __('Complete'))->icon('git-merge')->when($active)->confirm(__('Complete (merge) this pull request?'))->fields([
                Field::select('mergeStrategy', __('How'), ['noFastForward' => __('Merge commit'), 'squash' => __('Squash'), 'rebase' => __('Rebase and fast-forward'), 'rebaseMerge' => __('Rebase with merge commit')])->required()->default('noFastForward'),
                Field::text('mergeCommitMessage', __('Commit message'))->default(fn ($row) => __('Merged PR :id: :title', ['id' => $row['pullRequestId'] ?? '', 'title' => $row['title'] ?? ''])),
                Field::checkbox('deleteSourceBranch', __('Delete the branch afterwards'))->default(true),
                Field::checkbox('auto', __('When the policies pass'))->help(__('Sets auto-complete instead of completing now.')),
            ])->run(function (GitClient $api, array $row, array $data) {
                $options = [
                    'mergeStrategy' => $data['mergeStrategy'],
                    'deleteSourceBranch' => (bool) $data['deleteSourceBranch'],
                    'mergeCommitMessage' => $data['mergeCommitMessage'] ?? '',
                ];

                if ($data['auto']) {
                    $api->patch(self::v(self::pullUrl($row)), ['autoCompleteSetBy' => ['id' => self::me($api)['id']], 'completionOptions' => $options]);

                    return __('Set to complete when the policies pass.');
                }

                $commit = data_get($api->get(self::v(self::pullUrl($row))), 'lastMergeSourceCommit.commitId');
                $api->patch(self::v(self::pullUrl($row)), ['status' => 'completed', 'lastMergeSourceCommit' => ['commitId' => $commit], 'completionOptions' => $options]);

                return __('Completed.');
            }),
            Action::make('edit', __('Edit'))->icon('pencil')->fields([
                Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? ''),
                Field::textarea('description', __('Description'))->default(fn ($row) => $row['description'] ?? ''),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->patch(self::v(self::pullUrl($row)), ['title' => $data['title'], 'description' => $data['description'] ?? '']))),
            Action::make('abandon', __('Abandon'))->icon('x-circle')->when($active)->confirm(__('Abandon this pull request?'))
                ->run(fn (GitClient $api, array $row) => tap(__('Abandoned.'), fn () => $api->patch(self::v(self::pullUrl($row)), ['status' => 'abandoned']))),
            Action::make('reactivate', __('Reactivate'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['status'] ?? '') === 'abandoned')
                ->run(fn (GitClient $api, array $row) => tap(__('Reactivated.'), fn () => $api->patch(self::v(self::pullUrl($row)), ['status' => 'active']))),
        ];
    }

    /* ---------------------------------------------------------------- work items */

    private function workItems(): Section
    {
        $item = fn (array $row, string $suffix = '') => '/'.self::segment($row['project']).'/_apis/wit/workitems/'.$row['id'].$suffix;
        $open = fn ($row) => ! in_array($row['state'], ['Closed', 'Done', 'Removed', 'Resolved', 'Completed', 'Cut'], true);

        return Section::make('work-items', __('Work items'))
            ->icon('clipboard-document-check')
            ->filters([
                Field::select('which', __('Which'), ['assigned' => __('Assigned to me'), 'created' => __('Created by me')])->default('assigned'),
                Field::select('state', __('State'), ['open' => __('Open'), 'closed' => __('Closed'), '' => __('Any')])->default('open'),
                Field::select('project', __('Project'), fn (GitClient $api) => self::projectOptions($api)),
            ])
            ->list(function (GitClient $api, array $scope) {
                $where = [
                    ($scope['which'] ?? 'assigned') === 'created' ? '[System.CreatedBy] = @Me' : '[System.AssignedTo] = @Me',
                    ...match ($scope['state'] ?? 'open') {
                        'open' => ['[System.State] NOT IN ('.self::CLOSED.')'],
                        'closed' => ['[System.State] IN ('.self::CLOSED.')'],
                        default => [],
                    },
                    ...(filled($scope['project'] ?? null) ? ['[System.TeamProject] = @project'] : []),
                ];

                return self::query($api, 'SELECT [System.Id] FROM WorkItems WHERE '.implode(' AND ', $where).' ORDER BY [System.ChangedDate] DESC', $scope['project'] ?? null, 200);
            })
            ->columns([
                Column::make('id', __('Item'))->mono(),
                Column::make('type', __('Type'))->badge(['bug' => 'red', 'task' => 'blue', 'user story' => 'green', 'issue' => 'amber', 'feature' => 'purple', 'epic' => 'purple', '*' => 'zinc']),
                Column::make('title', __('Title'))->link('_web'),
                Column::make('state', __('State'))->badge(),
                Column::make('assigned', __('Assigned to')),
                Column::make('project', __('Project')),
                Column::make('tags', __('Tags')),
                Column::make('changed', __('Updated'))->date(),
            ])
            ->create(__('New work item'), [
                Field::select('project', __('Project'), fn (GitClient $api) => self::projectOptions($api))->required(),
                Field::text('type', __('Type'))->required()->default('Task')->help(__('As the project\'s process names it: Task, Bug, User Story, Issue, Product Backlog Item…')),
                Field::text('title', __('Title'))->required(),
                Field::textarea('description', __('Description')),
                Field::text('tags', __('Tags'))->placeholder('frontend; urgent'),
                Field::checkbox('assign_me', __('Assign to me'))->default(true),
            ], function (GitClient $api, array $row, array $data) {
                $created = self::patchItem($api, '/'.self::segment($data['project']).'/_apis/wit/workitems/$'.rawurlencode($data['type']), array_values(array_filter([
                    ['op' => 'add', 'path' => '/fields/System.Title', 'value' => $data['title']],
                    filled($data['description']) ? ['op' => 'add', 'path' => '/fields/System.Description', 'value' => nl2br(e($data['description']))] : null,
                    filled($data['tags']) ? ['op' => 'add', 'path' => '/fields/System.Tags', 'value' => $data['tags']] : null,
                    $data['assign_me'] ? ['op' => 'add', 'path' => '/fields/System.AssignedTo', 'value' => self::me($api)['email'] ?: self::me($api)['name']] : null,
                ])), 'POST');

                return Outcome::show(__(':type :id', ['type' => $data['type'], 'id' => $created['id'] ?? '?']), (string) data_get($created, '_links.html.href', ''), __('Work item created.'));
            })
            ->actions([
                Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row) use ($item) {
                    $full = $api->get(self::v('/_apis/wit/workitems/'.$row['id']));
                    $comments = array_map(fn ($c) => ['author' => data_get($c, 'createdBy.displayName'), 'at' => $c['createdDate'] ?? null, 'body' => self::plain($c['text'] ?? null)],
                        (array) ($api->get(self::v($item($row, '/comments'), '7.1-preview.4'))['comments'] ?? []));
                    $f = fn (string $name) => data_get($full, 'fields', [])[$name] ?? null;

                    return Outcome::show($row['type'].' '.$row['id'].' '.$row['title'], Thread::text([
                        __('State') => $f('System.State'),
                        __('Reason') => $f('System.Reason'),
                        __('Assigned to') => data_get($f('System.AssignedTo'), 'displayName'),
                        __('Created by') => data_get($f('System.CreatedBy'), 'displayName'),
                        __('Created') => Thread::when($f('System.CreatedDate')),
                        __('Area') => $f('System.AreaPath'),
                        __('Iteration') => $f('System.IterationPath'),
                        __('Priority') => $f('Microsoft.VSTS.Common.Priority'),
                        __('Tags') => $f('System.Tags'),
                    ], self::plain($f('System.Description') ?? $f('Microsoft.VSTS.TCM.ReproSteps')), array_reverse($comments)));
                }),
                Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                    Field::textarea('body', __('Comment'))->required(),
                ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Comment added.'), fn () => $api->post(self::v($item($row, '/comments'), '7.1-preview.4'), ['text' => nl2br(e($data['body']))]))),
                Action::make('state', __('Change state'))->icon('arrow-path-rounded-square')->fields([
                    Field::text('state', __('State'))->required()->default(fn ($row) => $row['state'] ?? '')
                        ->help(__('One of the states of this type in the project\'s process: New, Active, Resolved, Closed, Done…')),
                ])->run(fn (GitClient $api, array $row, array $data) => tap(__('State changed.'), fn () => self::patchItem($api, $item($row), [
                    ['op' => 'add', 'path' => '/fields/System.State', 'value' => $data['state']],
                ]))),
                Action::make('assign-me', __('Assign to me'))->icon('user')->when($open)
                    ->run(fn (GitClient $api, array $row) => tap(__('Assigned to you.'), fn () => self::patchItem($api, $item($row), [
                        ['op' => 'add', 'path' => '/fields/System.AssignedTo', 'value' => self::me($api)['email'] ?: self::me($api)['name']],
                    ]))),
                Action::make('edit', __('Edit'))->icon('pencil')->fields([
                    Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? ''),
                    Field::text('tags', __('Tags'))->default(fn ($row) => $row['tags'] ?? ''),
                ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => self::patchItem($api, $item($row), [
                    ['op' => 'add', 'path' => '/fields/System.Title', 'value' => $data['title']],
                    ['op' => 'add', 'path' => '/fields/System.Tags', 'value' => (string) ($data['tags'] ?? '')],
                ]))),
            ]);
    }

    /** Azure DevOps keeps descriptions and comments as HTML: as text to read. */
    private static function plain(mixed $html): ?string
    {
        if (! is_string($html) || $html === '') {
            return null;
        }

        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h\d)>#i'], "\n", $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    /* ---------------------------------------------------------------- repositories */

    private function repositories(): Section
    {
        $scope = fn ($row) => [
            'project' => data_get($row, 'project.id'), 'repo' => $row['id'],
            'repo_name' => data_get($row, 'project.name').'/'.$row['name'], 'default_branch' => self::branch($row['defaultBranch'] ?? ''),
        ];
        $repoPath = fn (array $row) => '/'.self::segment(data_get($row, 'project.id')).'/_apis/git/repositories/'.self::segment($row['id']);

        return Section::make('repositories', __('Repositories'))
            ->icon('folder')
            ->list(fn (GitClient $api) => collect(self::values($api, '/_apis/git/repositories'))->sortBy(fn ($r) => data_get($r, 'project.name').'/'.$r['name'])->values()->all())
            ->columns([
                Column::make('name', __('Repository'))->link('webUrl'),
                Column::make('project.name', __('Project')),
                Column::make('defaultBranch', __('Default branch'))->mono()->using(fn ($row) => self::branch($row['defaultBranch'] ?? '')),
                Column::make('size', __('Size'))->using(fn ($row) => isset($row['size']) ? round(((int) $row['size']) / 1048576, 1).' MB' : null),
                Column::make('isDisabled', __('Disabled'))->bool(),
            ])
            ->create(__('New repository'), [
                Field::select('project', __('Project'), fn (GitClient $api) => self::projectOptions($api))->required(),
                Field::text('name', __('Name'))->required(),
            ], function (GitClient $api, array $row, array $data) {
                $repo = $api->post(self::v('/'.self::segment($data['project']).'/_apis/git/repositories'), ['name' => $data['name'], 'project' => ['id' => $data['project']]]);

                return Outcome::show(__('Repository :name', ['name' => $repo['name'] ?? $data['name']]), [
                    'web' => $repo['webUrl'] ?? null, 'https' => $repo['remoteUrl'] ?? null, 'ssh' => $repo['sshUrl'] ?? null,
                ], __('Repository created: push a first commit to start it.'));
            })
            ->actions([
                Action::make('pulls', __('Pull requests'))->icon('git-pull-request')->opens('repo-pulls', $scope),
                Action::make('branches', __('Branches'))->icon('git-branch')->opens('branches', $scope),
                Action::make('pipelines', __('Pipelines'))->icon('play-circle')->opens('pipelines', $scope),
                Action::make('clone', __('Clone addresses'))->icon('clipboard')->run(fn (GitClient $api, array $row) => Outcome::show($row['name'], [
                    'https' => $row['remoteUrl'] ?? null, 'ssh' => $row['sshUrl'] ?? null,
                ])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the repository? Azure DevOps keeps it in the recycle bin for a while.'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Repository deleted.'), fn () => $api->delete(self::v($repoPath($row))))),
            ]);
    }

    /* ---------------------------------------------------------------- branches */

    private function branches(): Section
    {
        $refs = fn (array $scope) => '/'.self::segment($scope['project']).'/_apis/git/repositories/'.self::segment($scope['repo']).'/refs';

        return Section::make('branches', __('Branches'))
            ->icon('git-branch')
            ->id('name')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => array_map(fn ($r) => [
                ...$r,
                '_name' => self::branch($r['name']),
                '_default' => self::branch($r['name']) === ($scope['default_branch'] ?? null),
            ], self::values($api, $refs($scope), ['filter' => 'heads/', 'includeStatuses' => 'false'])))
            ->columns([
                Column::make('_name', __('Branch'))->mono(),
                Column::make('_default', __('Default'))->bool(),
                Column::make('objectId', __('Commit'))->mono()->using(fn ($row) => substr((string) ($row['objectId'] ?? ''), 0, 8)),
                Column::make('creator.displayName', __('Created by')),
                Column::make('isLocked', __('Locked'))->bool(),
            ])
            ->create(__('New branch'), [
                Field::text('name', __('Name'))->required()->placeholder('feature/login'),
                Field::select('from', __('From'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project'], $scope['repo']))->required(),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($refs) {
                $from = self::values($api, $refs($scope), ['filter' => 'heads/'.$data['from']]);
                $sha = collect($from)->firstWhere('name', 'refs/heads/'.$data['from'])['objectId'] ?? throw new ConnectorException(__('The branch :name is gone.', ['name' => $data['from']]));
                self::updateRefs($api, $refs($scope), [['name' => 'refs/heads/'.$data['name'], 'oldObjectId' => self::ZERO, 'newObjectId' => $sha]]);

                return __('Branch created.');
            })
            ->actions([
                Action::make('pull', __('Open a pull request'))->icon('git-pull-request')->when(fn ($row) => ! $row['_default'])->fields(self::pullFields(false))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope, ['source' => $row['_name'], ...$data])),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ! $row['_default'] && ! ($row['isLocked'] ?? false))
                    ->danger(__('Delete the branch? Commits only on it are lost.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Branch deleted.'), fn () => self::updateRefs($api, $refs($scope), [
                        ['name' => $row['name'], 'oldObjectId' => $row['objectId'], 'newObjectId' => self::ZERO],
                    ]))),
            ]);
    }

    /**
     * Create or delete refs; Azure answers 200 with a failure per ref when one is refused.
     *
     * @param  list<array<string, string>>  $updates
     */
    private static function updateRefs(GitClient $api, string $path, array $updates): void
    {
        foreach ((array) ($api->sendBody('POST', self::v($path), (string) json_encode($updates), 'application/json')['value'] ?? []) as $result) {
            if (is_array($result) && ($result['success'] ?? true) === false) {
                throw new ConnectorException(__('Azure DevOps refused: :reason', ['reason' => $result['customMessage'] ?? $result['updateStatus'] ?? '?']));
            }
        }
    }

    /* ---------------------------------------------------------------- pipelines */

    private function pipelines(): Section
    {
        $builds = fn (array $scope, string $suffix = '') => '/'.self::segment($scope['project']).'/_apis/build/builds'.$suffix;

        return Section::make('pipelines', __('Pipelines'))
            ->icon('play-circle')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => self::values($api, $builds($scope), [
                'repositoryId' => $scope['repo'], 'repositoryType' => 'TfsGit', '$top' => 50, 'queryOrder' => 'queueTimeDescending',
            ]))
            ->columns([
                Column::make('buildNumber', __('Run'))->mono()->link('_links.web.href'),
                Column::make('definition.name', __('Pipeline')),
                Column::make('sourceBranch', __('Branch'))->mono()->using(fn ($row) => self::branch($row['sourceBranch'] ?? '')),
                Column::make('reason', __('Trigger')),
                Column::make('result', __('Result'))->using(fn ($row) => $row['result'] ?? $row['status'] ?? '')
                    ->badge(['succeeded' => 'green', 'partiallysucceeded' => 'yellow', 'failed' => 'red', 'canceled' => 'zinc', 'inprogress' => 'blue', 'notstarted' => 'yellow', '*' => 'zinc']),
                Column::make('requestedFor.displayName', __('By')),
                Column::make('queueTime', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('queue', __('Run a pipeline'))->icon('play')->primary()->fields([
                    Field::select('definition', __('Pipeline'), fn (GitClient $api, array $scope) => collect(self::values($api, '/'.self::segment($scope['project']).'/_apis/build/definitions', [
                        'repositoryId' => $scope['repo'], 'repositoryType' => 'TfsGit',
                    ]))->mapWithKeys(fn ($d) => [(string) $d['id'] => (string) $d['name']])->all())->required(),
                    Field::select('branch', __('Branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project'], $scope['repo']))->required(),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Pipeline queued.'), fn () => $api->post(self::v($builds($scope)), [
                    'definition' => ['id' => (int) $data['definition']], 'sourceBranch' => 'refs/heads/'.$data['branch'],
                ]))),
            ])
            ->actions([
                Action::make('timeline', __('Jobs'))->icon('eye')->run(function (GitClient $api, array $row, array $data, array $scope) use ($builds) {
                    $records = collect((array) ($api->get(self::v($builds($scope, '/'.$row['id'].'/timeline')))['records'] ?? []))
                        ->whereIn('type', ['Stage', 'Job'])->sortBy('order');
                    $text = $records->map(fn ($r) => ($r['type'] === 'Stage' ? '■ ' : '   ').match ($r['result'] ?? null) {
                        'succeeded' => '✓', 'failed' => '✗', 'skipped', 'canceled' => '–', default => '…'
                    }.' '.$r['name'].' · '.($r['result'] ?? $r['state'] ?? ''))->implode("\n");

                    return Outcome::show(($row['definition']['name'] ?? '').' '.$row['buildNumber'], $text !== '' ? $text : __('No jobs.'));
                }),
                Action::make('retry', __('Retry failed jobs'))->icon('arrow-path')->when(fn ($row) => in_array($row['result'] ?? '', ['failed', 'partiallySucceeded', 'canceled'], true))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Retrying.'), fn () => $api->patch(self::v($builds($scope, '/'.$row['id'].'?retry=true')), ['id' => $row['id']]))),
                Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => in_array($row['status'] ?? '', ['inProgress', 'notStarted'], true))->confirm(__('Cancel this run?'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Cancelling.'), fn () => $api->patch(self::v($builds($scope, '/'.$row['id'])), ['status' => 'cancelling']))),
            ]);
    }
}
