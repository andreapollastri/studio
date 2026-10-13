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

/**
 * gitlab.com or a self-managed GitLab with the person's own token: merge
 * requests waiting for their review, their issues and to-dos and, project by
 * project, issues, merge requests, branches, pipelines and releases.
 */
final class GitLab extends Forge
{
    public function key(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function description(): string
    {
        return __('Merge requests waiting for a review, issues, to-dos and, for each project, branches, pipelines and releases: review, approve, merge, comment and triage from Studio.');
    }

    public function icon(): string
    {
        return 'code-bracket';
    }

    public function docs(): string
    {
        return 'https://docs.gitlab.com/api/rest/';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Address'))->default('https://gitlab.com')->help(__('gitlab.com, or the address of a self-managed GitLab.')),
            Field::password('token', __('Personal access token'))->required()->placeholder('glpat-…')
                ->help(__('The same token clones, pushes and drives this page: see below.')),
        ];
    }

    public function client(array $credentials): GitClient
    {
        $url = self::instanceUrl($credentials['url'] ?? '');

        return new GitClient(($url !== '' ? $url : 'https://gitlab.com').'/api/v4', ['PRIVATE-TOKEN' => self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var GitClient $api */
        return 'ok · '.($api->get('/user')['username'] ?? '?');
    }

    public function host(array $credentials): string
    {
        $url = self::instanceUrl($credentials['url'] ?? '') ?: 'https://gitlab.com';

        return strtolower((string) parse_url($url, PHP_URL_HOST)).rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    public function gitUsername(string $login): string
    {
        return 'oauth2';
    }

    public function identity(GitClient $api): array
    {
        $user = $api->get('/user');

        return [
            'login' => (string) ($user['username'] ?? ''),
            'name' => $user['name'] ?? null,
            'email' => ($user['commit_email'] ?? null) ?: (($user['public_email'] ?? null) ?: ($user['email'] ?? null)),
        ];
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->mergeRequests('merge-requests'),
            $this->issues('issues'),
            $this->todos(),
            $this->projects(),
            $this->issues('project-issues'),
            $this->mergeRequests('project-merge-requests'),
            $this->branches(),
            $this->pipelines(),
            $this->releases(),
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    /** @return array{id: int, username: string} */
    private static function me(GitClient $api): array
    {
        $me = $api->remember('user', fn () => $api->get('/user'));

        return ['id' => (int) ($me['id'] ?? 0), 'username' => (string) ($me['username'] ?? '')];
    }

    /**
     * Every page of a list GitLab pages with ?page=.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(GitClient $api, string $path, array $query = [], int $maxPages = 5): array
    {
        $query = ['per_page' => 100, ...array_filter($query, fn ($value) => $value !== null && $value !== '')];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter($answer, 'is_array')),
            fn (array $answer, int $page) => count($answer) >= $query['per_page'] ? [...$query, 'page' => $page + 1] : null,
            $maxPages,
        );
    }

    public function repositorySection(): string
    {
        return 'projects';
    }

    public function repository(GitClient $api, string $path): array
    {
        return $api->get(self::project($path));
    }

    private static function project(mixed $id): string
    {
        return '/projects/'.rawurlencode((string) $id);
    }

    /** @return array<string, string> */
    private static function projectOptions(GitClient $api): array
    {
        return collect(self::every($api, '/projects', ['membership' => 'true', 'archived' => 'false', 'order_by' => 'last_activity_at', 'simple' => 'true'], 3))
            ->mapWithKeys(fn ($p) => [(string) $p['id'] => (string) $p['path_with_namespace']])
            ->all();
    }

    /** @return array<string, string> */
    private static function branchOptions(GitClient $api, mixed $project): array
    {
        return collect(self::every($api, self::project($project).'/repository/branches', [], 3))
            ->mapWithKeys(fn ($b) => [(string) $b['name'] => (string) $b['name']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function item(array $item): array
    {
        return [...$item, '_ref' => (string) (data_get($item, 'references.full') ?? $item['project_id'].'#'.$item['iid'])];
    }

    /** @param  array<string, mixed>  $row */
    private static function url(array $row, string $kind, string $suffix = ''): string
    {
        return self::project($row['project_id']).'/'.$kind.'/'.$row['iid'].$suffix;
    }

    /** @param  array<string, mixed>  $row */
    private static function state(array $row): string
    {
        return ($row['state'] ?? '') === 'opened' && (bool) ($row['draft'] ?? $row['work_in_progress'] ?? false) ? 'draft' : (string) ($row['state'] ?? '');
    }

    /* ---------------------------------------------------------------- overview */

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (GitClient $api) {
            $me = self::me($api);
            $list = function (string $path, array $query) use ($api) {
                $rows = array_values(array_filter($api->get($path, [...$query, 'per_page' => 10]), 'is_array'));

                return [count($rows) >= 10 ? '10+' : (string) count($rows), collect($rows)->mapWithKeys(fn ($r) => [self::item($r)['_ref'] => (string) ($r['title'] ?? '')])->all()];
            };

            [$reviews, $reviewRows] = $list('/merge_requests', ['scope' => 'all', 'reviewer_username' => $me['username'], 'state' => 'opened']);
            [$mine, $mineRows] = $list('/merge_requests', ['scope' => 'created_by_me', 'state' => 'opened']);
            [$assigned, $assignedRows] = $list('/issues', ['scope' => 'assigned_to_me', 'state' => 'opened']);
            $todos = array_values(array_filter($api->get('/todos', ['state' => 'pending', 'per_page' => 100]), 'is_array'));

            return [
                __('Waiting for my review (:count)', ['count' => $reviews]) => $reviewRows,
                __('My open merge requests (:count)', ['count' => $mine]) => $mineRows,
                __('Issues assigned to me (:count)', ['count' => $assigned]) => $assignedRows,
                __('Account') => [
                    __('Signed in as') => $me['username'],
                    __('Pending to-dos') => count($todos) >= 100 ? '100+' : count($todos),
                ],
            ];
        })->icon('home')->description(__('What waits for you across every project the token reaches.'));
    }

    /* ---------------------------------------------------------------- issues */

    private function issues(string $key): Section
    {
        $child = $key === 'project-issues';
        $fields = fn (bool $withProject) => [
            ...($withProject ? [Field::select('project', __('Project'), fn (GitClient $api) => self::projectOptions($api))->required()] : []),
            Field::text('title', __('Title'))->required(),
            Field::textarea('description', __('Description'))->help(__('Markdown.')),
            Field::text('labels', __('Labels'))->placeholder('bug, urgent'),
            Field::checkbox('assign_me', __('Assign to me')),
        ];
        $create = function (GitClient $api, mixed $project, array $data) {
            $issue = $api->post(self::project($project).'/issues', array_filter([
                'title' => $data['title'], 'description' => $data['description'], 'labels' => $data['labels'],
                'assignee_ids' => $data['assign_me'] ? [self::me($api)['id']] : null,
            ]));

            return Outcome::show(__('Issue created'), (string) ($issue['web_url'] ?? ''), __('Issue created.'));
        };

        $section = Section::make($key, __('Issues'))
            ->icon('exclamation-circle')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [Field::select('which', __('Which'), ['assigned_to_me' => __('Assigned to me'), 'created_by_me' => __('Opened by me')])->default('assigned_to_me')]),
                Field::select('state', __('State'), ['opened' => __('Open'), 'closed' => __('Closed'), '' => __('Any')])->default('opened'),
            ])
            ->list(fn (GitClient $api, array $scope) => array_map(fn ($item) => self::item($item), $child
                ? self::every($api, self::project($scope['project']).'/issues', ['state' => $scope['state'] ?? null, 'order_by' => 'updated_at'], 3)
                : self::every($api, '/issues', ['scope' => ($scope['which'] ?? '') ?: 'assigned_to_me', 'state' => $scope['state'] ?? null, 'order_by' => 'updated_at'], 3)))
            ->columns([
                Column::make('_ref', __('Issue'))->mono()->using(fn ($row) => $child ? '#'.$row['iid'] : $row['_ref']),
                Column::make('title', __('Title'))->link('web_url'),
                Column::make('labels', __('Labels')),
                Column::make('assignees', __('Assignees'))->using(fn ($row) => array_column((array) ($row['assignees'] ?? []), 'username')),
                Column::make('state', __('State'))->badge(['opened' => 'green', 'closed' => 'purple']),
                Column::make('user_notes_count', __('Comments')),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions($this->conversationActions('issues'));

        if ($child) {
            return $section->childOf('projects', 'repo_name')
                ->create(__('New issue'), $fields(false), fn (GitClient $api, array $row, array $data, array $scope) => $create($api, $scope['project'], $data));
        }

        return $section->create(__('New issue'), $fields(true), fn (GitClient $api, array $row, array $data) => $create($api, $data['project'], $data));
    }

    /* ---------------------------------------------------------------- merge requests */

    private function mergeRequests(string $key): Section
    {
        $child = $key === 'project-merge-requests';

        $section = Section::make($key, __('Merge requests'))
            ->icon('git-pull-request')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [Field::select('which', __('Which'), [
                    'review' => __('Waiting for my review'), 'created_by_me' => __('Opened by me'), 'assigned_to_me' => __('Assigned to me'),
                ])->default('review')]),
                Field::select('state', __('State'), ['opened' => __('Open'), 'merged' => __('Merged'), 'closed' => __('Closed'), '' => __('Any')])->default('opened'),
            ])
            ->list(function (GitClient $api, array $scope) use ($child) {
                $query = ['state' => $scope['state'] ?? null, 'order_by' => 'updated_at'];

                if ($child) {
                    return array_map(fn ($item) => self::item($item), self::every($api, self::project($scope['project']).'/merge_requests', $query, 3));
                }

                $which = ($scope['which'] ?? '') ?: 'review';
                $query += $which === 'review' ? ['scope' => 'all', 'reviewer_username' => self::me($api)['username']] : ['scope' => $which];

                return array_map(fn ($item) => self::item($item), self::every($api, '/merge_requests', $query, 3));
            })
            ->columns([
                Column::make('_ref', __('Merge request'))->mono()->using(fn ($row) => $child ? '!'.$row['iid'] : $row['_ref']),
                Column::make('title', __('Title'))->link('web_url'),
                Column::make('source_branch', __('Branch'))->mono()->using(fn ($row) => ($row['source_branch'] ?? '').' → '.($row['target_branch'] ?? '')),
                Column::make('author.username', __('Author')),
                Column::make('state', __('State'))->using(fn ($row) => self::state($row))->badge(['opened' => 'green', 'draft' => 'zinc', 'merged' => 'purple', 'closed' => 'red', 'locked' => 'zinc']),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions($this->conversationActions('merge_requests'));

        if (! $child) {
            return $section;
        }

        return $section->childOf('projects', 'repo_name')->create(__('New merge request'), self::mergeRequestFields(true),
            fn (GitClient $api, array $row, array $data, array $scope) => self::openMergeRequest($api, $scope['project'], $data));
    }

    /** @return list<Field> */
    private static function mergeRequestFields(bool $withSource): array
    {
        return [
            ...($withSource ? [Field::select('source_branch', __('From branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project']))->required()] : []),
            Field::select('target_branch', __('Into branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project']))->required(),
            Field::text('title', __('Title'))->required()->default(fn ($row) => $row['name'] ?? ''),
            Field::textarea('description', __('Description'))->help(__('Markdown.')),
            Field::checkbox('draft', __('Draft')),
            Field::checkbox('remove_source_branch', __('Delete the branch once merged'))->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function openMergeRequest(GitClient $api, mixed $project, array $data): Outcome
    {
        if ($data['source_branch'] === $data['target_branch']) {
            throw new ConnectorException(__('Pick two different branches.'));
        }

        $mr = $api->post(self::project($project).'/merge_requests', array_filter([
            'source_branch' => $data['source_branch'], 'target_branch' => $data['target_branch'],
            'title' => ($data['draft'] ? 'Draft: ' : '').$data['title'], 'description' => $data['description'],
            'remove_source_branch' => (bool) $data['remove_source_branch'],
        ], fn ($value) => $value !== null));

        return Outcome::show(__('Merge request !:iid', ['iid' => $mr['iid'] ?? '?']), (string) ($mr['web_url'] ?? ''), __('Merge request opened.'));
    }

    /**
     * What is done on an issue or a merge request: they share notes, labels and state.
     *
     * @return list<Action>
     */
    private function conversationActions(string $kind): array
    {
        $mr = $kind === 'merge_requests';
        $open = fn ($row) => ($row['state'] ?? '') === 'opened';

        return [
            Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row) use ($kind, $mr) {
                $notes = array_values(array_filter(self::every($api, self::url($row, $kind, '/notes'), ['sort' => 'asc', 'order_by' => 'created_at'], 3), fn ($n) => ! ($n['system'] ?? false)));
                $facts = [
                    __('State') => self::state($row),
                    __('Author') => data_get($row, 'author.username'),
                    __('Opened') => Thread::when($row['created_at'] ?? null),
                    __('Labels') => (array) ($row['labels'] ?? []),
                    __('Assignees') => array_column((array) ($row['assignees'] ?? []), 'username'),
                ];

                if ($mr) {
                    $detail = $api->get(self::url($row, $kind));
                    $approvals = $api->get(self::url($row, $kind, '/approvals'));
                    $facts += [
                        __('Branches') => ($detail['source_branch'] ?? '').' → '.($detail['target_branch'] ?? ''),
                        __('Changes') => $detail['changes_count'] ?? null,
                        __('Merge status') => $detail['detailed_merge_status'] ?? $detail['merge_status'] ?? null,
                        __('Conflicts') => (bool) ($detail['has_conflicts'] ?? false),
                        __('Pipeline') => data_get($detail, 'head_pipeline.status'),
                        __('Reviewers') => array_column((array) ($detail['reviewers'] ?? []), 'username'),
                        __('Approved by') => array_map(fn ($a) => data_get($a, 'user.username'), (array) ($approvals['approved_by'] ?? [])),
                    ];
                }

                return Outcome::show($row['_ref'].' '.($row['title'] ?? ''), Thread::text($facts, $row['description'] ?? null,
                    array_map(fn ($n) => ['author' => data_get($n, 'author.username'), 'at' => $n['created_at'] ?? null, 'body' => $n['body'] ?? null], $notes)));
            }),
            ...($mr ? [
                Action::make('diff', __('Changes'))->icon('document-text')->run(fn (GitClient $api, array $row) => Outcome::show(
                    __('Changes of :ref', ['ref' => $row['_ref']]),
                    Thread::diff(array_map(fn ($d) => [
                        match (true) {
                            (bool) ($d['new_file'] ?? false) => 'added ',
                            (bool) ($d['deleted_file'] ?? false) => 'removed ',
                            (bool) ($d['renamed_file'] ?? false) => 'renamed '.($d['old_path'] ?? '').' → ',
                            default => 'modified ',
                        }.($d['new_path'] ?? ''),
                        $d['diff'] ?? null,
                    ], self::every($api, self::url($row, $kind, '/diffs'), [], 3))),
                )),
            ] : []),
            Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                Field::textarea('body', __('Comment'))->required()->help(__('Markdown.')),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Comment added.'), fn () => $api->post(self::url($row, $kind, '/notes'), ['body' => $data['body']]))),
            ...($mr ? [
                Action::make('approve', __('Approve'))->icon('check-badge')->when($open)
                    ->run(fn (GitClient $api, array $row) => tap(__('Approved.'), fn () => $api->post(self::url($row, $kind, '/approve')))),
                Action::make('unapprove', __('Revoke my approval'))->icon('x-mark')->when($open)
                    ->run(fn (GitClient $api, array $row) => tap(__('Approval revoked.'), fn () => $api->post(self::url($row, $kind, '/unapprove')))),
                Action::make('reviewers', __('Ask for a review'))->icon('user-plus')->when($open)->fields([
                    Field::text('reviewers', __('Reviewers'))->required()->placeholder('alice, bob')->help(__('User names; they join the reviewers already asked.')),
                ])->run(function (GitClient $api, array $row, array $data) use ($kind) {
                    $ids = array_map(function (string $username) use ($api) {
                        $user = $api->get('/users', ['username' => ltrim($username, '@')])[0] ?? null;

                        return is_array($user) ? (int) $user['id'] : throw new ConnectorException(__('No GitLab user :name.', ['name' => $username]));
                    }, self::lines($data['reviewers']));
                    $current = array_map(fn ($r) => (int) $r['id'], (array) ($api->get(self::url($row, $kind))['reviewers'] ?? []));
                    $api->put(self::url($row, $kind), ['reviewer_ids' => array_values(array_unique([...$current, ...$ids]))]);

                    return __('Review requested.');
                }),
                Action::make('merge', __('Merge'))->icon('git-merge')->when($open)->confirm(__('Merge this merge request?'))->fields([
                    Field::checkbox('squash', __('Squash the commits'))->default(fn ($row) => (bool) ($row['squash'] ?? false)),
                    Field::checkbox('should_remove_source_branch', __('Delete the branch afterwards'))->default(fn ($row) => (bool) ($row['force_remove_source_branch'] ?? false)),
                    Field::checkbox('auto_merge', __('When the pipeline succeeds'))->help(__('Merges as soon as the pipeline passes, instead of now.')),
                ])->run(function (GitClient $api, array $row, array $data) use ($kind) {
                    $api->put(self::url($row, $kind, '/merge'), [
                        'squash' => (bool) $data['squash'],
                        'should_remove_source_branch' => (bool) $data['should_remove_source_branch'],
                        ...($data['auto_merge'] ? ['merge_when_pipeline_succeeds' => true, 'auto_merge' => true] : []),
                    ]);

                    return $data['auto_merge'] ? __('Set to merge when the pipeline succeeds.') : __('Merged.');
                }),
            ] : []),
            Action::make('assign-me', __('Assign to me'))->icon('user')->when($open)
                ->run(fn (GitClient $api, array $row) => tap(__('Assigned to you.'), fn () => $api->put(self::url($row, $kind), [
                    'assignee_ids' => array_values(array_unique([...array_map(fn ($a) => (int) $a['id'], (array) ($row['assignees'] ?? [])), self::me($api)['id']])),
                ]))),
            Action::make('edit', __('Edit'))->icon('pencil')->fields([
                Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? ''),
                Field::textarea('description', __('Description'))->default(fn ($row) => $row['description'] ?? ''),
                Field::text('labels', __('Labels'))->default(fn ($row) => implode(', ', (array) ($row['labels'] ?? []))),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->put(self::url($row, $kind), [
                'title' => $data['title'], 'description' => $data['description'] ?? '', 'labels' => implode(',', self::lines($data['labels'])),
            ]))),
            Action::make('close', __('Close'))->icon('x-circle')->when($open)->confirm($mr ? __('Close this merge request without merging?') : __('Close this issue?'))
                ->run(fn (GitClient $api, array $row) => tap(__('Closed.'), fn () => $api->put(self::url($row, $kind), ['state_event' => 'close']))),
            Action::make('reopen', __('Reopen'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['state'] ?? '') === 'closed')
                ->run(fn (GitClient $api, array $row) => tap(__('Reopened.'), fn () => $api->put(self::url($row, $kind), ['state_event' => 'reopen']))),
        ];
    }

    /* ---------------------------------------------------------------- to-dos */

    private function todos(): Section
    {
        return Section::make('todos', __('To-dos'))
            ->icon('bell')
            ->list(fn (GitClient $api) => self::every($api, '/todos', ['state' => 'pending'], 2))
            ->columns([
                Column::make('project.path_with_namespace', __('Project'))->mono(),
                Column::make('target.title', __('Subject'))->link('target_url'),
                Column::make('target_type', __('Kind'))->badge(['mergerequest' => 'blue', 'issue' => 'green', '*' => 'zinc']),
                Column::make('action_name', __('Why'))->badge(),
                Column::make('author.username', __('From')),
                Column::make('created_at', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('done-all', __('Mark all as done'))->icon('check')->confirm(__('Mark every to-do as done?'))
                    ->run(fn (GitClient $api) => tap(__('All done.'), fn () => $api->post('/todos/mark_as_done'))),
            ])
            ->actions([
                Action::make('done', __('Mark as done'))->icon('check')
                    ->run(fn (GitClient $api, array $row) => tap(__('Done.'), fn () => $api->post('/todos/'.$row['id'].'/mark_as_done'))),
            ]);
    }

    /* ---------------------------------------------------------------- projects */

    private function projects(): Section
    {
        $scope = fn ($row) => ['project' => $row['id'], 'repo_name' => $row['path_with_namespace'], 'default_branch' => $row['default_branch'] ?? ''];

        return Section::make('projects', __('Projects'))
            ->icon('folder')
            ->list(fn (GitClient $api) => self::every($api, '/projects', ['membership' => 'true', 'order_by' => 'last_activity_at']))
            ->columns([
                Column::make('path_with_namespace', __('Project'))->link('web_url'),
                Column::make('visibility', __('Visibility'))->badge(['private' => 'amber', 'internal' => 'blue', 'public' => 'green']),
                Column::make('default_branch', __('Default branch'))->mono(),
                Column::make('open_issues_count', __('Open issues')),
                Column::make('archived', __('Archived'))->bool(),
                Column::make('last_activity_at', __('Last activity'))->date(),
            ])
            ->create(__('New project'), [
                Field::select('namespace_id', __('Group'), fn (GitClient $api) => ['' => self::me($api)['username'], ...collect(self::every($api, '/namespaces', [], 2))
                    ->where('kind', 'group')->mapWithKeys(fn ($n) => [(string) $n['id'] => (string) $n['full_path']])->all()]),
                Field::text('name', __('Name'))->required(),
                Field::text('description', __('Description')),
                Field::select('visibility', __('Visibility'), ['private' => __('Private'), 'internal' => __('Internal'), 'public' => __('Public')])->required()->default('private'),
                Field::checkbox('initialize_with_readme', __('Start with a README'))->default(true),
            ], function (GitClient $api, array $row, array $data) {
                $project = $api->post('/projects', array_filter([...$data, 'namespace_id' => $data['namespace_id'] ? (int) $data['namespace_id'] : null], fn ($v) => $v !== null));

                return Outcome::show(__('Project :name', ['name' => $project['path_with_namespace'] ?? $data['name']]), [
                    'web' => $project['web_url'] ?? null, 'ssh' => $project['ssh_url_to_repo'] ?? null, 'https' => $project['http_url_to_repo'] ?? null,
                ], __('Project created.'));
            })
            ->actions([
                Action::make('merge-requests', __('Merge requests'))->icon('git-pull-request')->opens('project-merge-requests', $scope),
                Action::make('issues', __('Issues'))->icon('exclamation-circle')->opens('project-issues', $scope),
                Action::make('branches', __('Branches'))->icon('git-branch')->opens('branches', $scope),
                Action::make('pipelines', __('Pipelines'))->icon('play-circle')->opens('pipelines', $scope),
                Action::make('releases', __('Releases'))->icon('tag')->opens('releases', $scope),
                Action::make('clone', __('Clone addresses'))->icon('clipboard')->run(fn (GitClient $api, array $row) => Outcome::show($row['path_with_namespace'], [
                    'ssh' => $row['ssh_url_to_repo'] ?? null, 'https' => $row['http_url_to_repo'] ?? null,
                ])),
                Action::make('archive', __('Archive'))->icon('archive-box')->when(fn ($row) => ! ($row['archived'] ?? false))->confirm(__('Archive the project? It becomes read-only.'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Archived.'), fn () => $api->post(self::project($row['id']).'/archive'))),
                Action::make('unarchive', __('Unarchive'))->icon('archive-box-x-mark')->when(fn ($row) => (bool) ($row['archived'] ?? false))
                    ->run(fn (GitClient $api, array $row) => tap(__('Unarchived.'), fn () => $api->post(self::project($row['id']).'/unarchive'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the project with its issues, merge requests and history?'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Project deleted (GitLab may keep it for a few days before removing it for good).'), fn () => $api->delete(self::project($row['id'])))),
            ]);
    }

    /* ---------------------------------------------------------------- branches */

    private function branches(): Section
    {
        return Section::make('branches', __('Branches'))
            ->icon('git-branch')
            ->id('name')
            ->childOf('projects', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => self::every($api, self::project($scope['project']).'/repository/branches', [], 3))
            ->columns([
                Column::make('name', __('Branch'))->mono()->link('web_url'),
                Column::make('default', __('Default'))->bool(),
                Column::make('protected', __('Protected'))->bool(),
                Column::make('merged', __('Merged'))->bool(),
                Column::make('commit.short_id', __('Commit'))->mono(),
                Column::make('commit.committed_date', __('Last commit'))->date(),
            ])
            ->create(__('New branch'), [
                Field::text('branch', __('Name'))->required()->placeholder('feature/login'),
                Field::select('ref', __('From'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project']))->required(),
            ], fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Branch created.'), fn () => $api->post(self::project($scope['project']).'/repository/branches', $data)))
            ->actions([
                Action::make('merge-request', __('Open a merge request'))->icon('git-pull-request')->when(fn ($row) => ! ($row['default'] ?? false))
                    ->fields(self::mergeRequestFields(false))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => self::openMergeRequest($api, $scope['project'], ['source_branch' => $row['name'], ...$data])),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ! ($row['default'] ?? false) && ! ($row['protected'] ?? false))
                    ->danger(__('Delete the branch? Commits only on it are lost.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Branch deleted.'), fn () => $api->delete(self::project($scope['project']).'/repository/branches/'.rawurlencode($row['name'])))),
            ]);
    }

    /* ---------------------------------------------------------------- pipelines */

    private function pipelines(): Section
    {
        $path = fn (array $scope, string $suffix = '') => self::project($scope['project']).'/pipelines'.$suffix;

        return Section::make('pipelines', __('Pipelines'))
            ->icon('play-circle')
            ->childOf('projects', 'repo_name')
            ->filters([
                Field::select('status', __('Status'), ['' => __('Any'), 'running' => __('Running'), 'pending' => __('Pending'), 'failed' => __('Failed'), 'success' => __('Succeeded'), 'canceled' => __('Cancelled')]),
            ])
            ->list(fn (GitClient $api, array $scope) => array_values(array_filter($api->get($path($scope), array_filter(['per_page' => 50, 'status' => $scope['status'] ?? null])), 'is_array')))
            ->columns([
                Column::make('id', __('Pipeline'))->mono()->using(fn ($row) => '#'.$row['id'])->link('web_url'),
                Column::make('ref', __('Branch'))->mono(),
                Column::make('sha', __('Commit'))->mono()->using(fn ($row) => substr((string) ($row['sha'] ?? ''), 0, 8)),
                Column::make('source', __('Trigger')),
                Column::make('status', __('Status'))->badge(['success' => 'green', 'failed' => 'red', 'canceled' => 'zinc', 'running' => 'blue', 'pending' => 'yellow', '*' => 'zinc']),
                Column::make('created_at', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('run', __('Run a pipeline'))->icon('play')->primary()->fields([
                    Field::select('ref', __('Branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['project']))->required(),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Pipeline started.'), fn () => $api->post(self::project($scope['project']).'/pipeline', ['ref' => $data['ref']]))),
            ])
            ->actions([
                Action::make('jobs', __('Jobs'))->icon('eye')->run(function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                    $text = collect(self::every($api, $path($scope, '/'.$row['id'].'/jobs'), [], 2))
                        ->groupBy('stage')
                        ->map(fn ($jobs, $stage) => '■ '.$stage."\n".$jobs->map(fn ($job) => '   '.match ($job['status'] ?? null) {
                            'success' => '✓', 'failed' => '✗', 'skipped', 'canceled' => '–', default => '…'
                        }.' '.$job['name'].' · '.$job['status'].(isset($job['duration']) ? ' · '.round((float) $job['duration']).'s' : ''))->implode("\n"))
                        ->implode("\n\n");

                    return Outcome::show(__('Pipeline #:id', ['id' => $row['id']]), $text !== '' ? $text : __('No jobs.'));
                }),
                Action::make('retry', __('Retry failed jobs'))->icon('arrow-path')->when(fn ($row) => in_array($row['status'] ?? '', ['failed', 'canceled'], true))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Retrying.'), fn () => $api->post($path($scope, '/'.$row['id'].'/retry')))),
                Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => in_array($row['status'] ?? '', ['running', 'pending', 'created', 'waiting_for_resource', 'preparing', 'scheduled'], true))
                    ->confirm(__('Cancel this pipeline?'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Cancelling.'), fn () => $api->post($path($scope, '/'.$row['id'].'/cancel')))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the pipeline with its jobs and logs?'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Pipeline deleted.'), fn () => $api->delete($path($scope, '/'.$row['id'])))),
            ]);
    }

    /* ---------------------------------------------------------------- releases */

    private function releases(): Section
    {
        $path = fn (array $scope) => self::project($scope['project']).'/releases';

        return Section::make('releases', __('Releases'))
            ->icon('tag')
            ->id('tag_name')
            ->childOf('projects', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => self::every($api, $path($scope), [], 2))
            ->columns([
                Column::make('name', __('Release'))->using(fn ($row) => ($row['name'] ?? '') ?: $row['tag_name'])->link('_links.self'),
                Column::make('tag_name', __('Tag'))->mono(),
                Column::make('upcoming_release', __('Upcoming'))->bool(),
                Column::make('author.username', __('By')),
                Column::make('released_at', __('Released'))->date(),
            ])
            ->create(__('New release'), [
                Field::text('tag_name', __('Tag'))->required()->placeholder('v1.2.0'),
                Field::text('ref', __('From'))->placeholder('main')->help(__('A branch or a commit, for a tag that does not exist yet.')),
                Field::text('name', __('Title')),
                Field::textarea('description', __('Notes'))->help(__('Markdown.')),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                $release = $api->post($path($scope), array_filter($data, fn ($v) => $v !== null));

                return Outcome::show(__('Release :tag', ['tag' => $data['tag_name']]), (string) data_get($release, '_links.self', ''), __('Release created.'));
            })
            ->actions([
                Action::make('notes', __('Notes'))->icon('eye')->run(fn (GitClient $api, array $row) => Outcome::show(($row['name'] ?? '') ?: $row['tag_name'], (string) ($row['description'] ?? ''))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the release? The tag stays.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Release deleted.'), fn () => $api->delete($path($scope).'/'.rawurlencode($row['tag_name'])))),
            ]);
    }
}
