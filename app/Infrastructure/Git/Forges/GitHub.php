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
 * GitHub with the person's own token: what waits for them (reviews, their
 * pull requests, issues assigned to them, notifications) and, repository by
 * repository, issues, pull requests, branches, Actions runs and releases.
 */
final class GitHub extends Forge
{
    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function description(): string
    {
        return __('Pull requests waiting for a review, issues, notifications and, for each repository, branches, Actions runs and releases: review, merge, comment and triage from Studio.');
    }

    public function icon(): string
    {
        return 'code-bracket-square';
    }

    public function docs(): string
    {
        return 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('Personal access token'))->required()->placeholder('ghp_… / github_pat_…')
                ->help(__('The same token clones, pushes and drives this page: see below.')),
        ];
    }

    public function client(array $credentials): GitClient
    {
        return new GitClient('https://api.github.com', [
            'Authorization' => 'Bearer '.self::string($credentials, 'token'),
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
    }

    public function verify(object $api): string
    {
        /** @var GitClient $api */
        $user = $api->get('/user');

        return 'ok · '.($user['login'] ?? '?');
    }

    public function host(array $credentials): string
    {
        return 'github.com';
    }

    public function gitUsername(string $login): string
    {
        return 'x-access-token';
    }

    public function identity(GitClient $api): array
    {
        $user = $api->get('/user');

        try {
            // Needs the user:email scope; without it, the public email or GitHub's noreply address.
            $email = collect($api->get('/user/emails'))->first(fn ($e) => is_array($e) && ($e['primary'] ?? false))['email'] ?? null;
        } catch (ConnectorException) {
            $email = null;
        }

        return [
            'login' => (string) ($user['login'] ?? ''),
            'name' => $user['name'] ?? null,
            'email' => $email ?? $user['email'] ?? (isset($user['id'], $user['login']) ? $user['id'].'+'.$user['login'].'@users.noreply.github.com' : null),
        ];
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->pulls('pulls'),
            $this->issues('issues'),
            $this->notifications(),
            $this->repositories(),
            $this->issues('repo-issues'),
            $this->pulls('repo-pulls'),
            $this->branches(),
            $this->runs(),
            $this->releases(),
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    private static function me(GitClient $api): string
    {
        return (string) $api->remember('login', fn () => $api->get('/user')['login'] ?? '');
    }

    /** owner/name as a path: each part encoded, the slash kept. */
    private static function path(string $value): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $value)));
    }

    /**
     * Every page of a list GitHub pages with ?page=.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(GitClient $api, string $path, array $query = [], int $maxPages = 5, ?string $key = null): array
    {
        $query = ['per_page' => 100, ...$query];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter((array) ($key !== null ? ($answer[$key] ?? []) : $answer), 'is_array')),
            fn (array $answer, int $page) => count((array) ($key !== null ? ($answer[$key] ?? []) : $answer)) >= $query['per_page'] ? [...$query, 'page' => $page + 1] : null,
            $maxPages,
        );
    }

    /** @return array<string, string> */
    private static function repoOptions(GitClient $api): array
    {
        return collect(self::every($api, '/user/repos', ['sort' => 'pushed', 'affiliation' => 'owner,collaborator,organization_member']))
            ->reject(fn ($repo) => (bool) ($repo['archived'] ?? false))
            ->mapWithKeys(fn ($repo) => [(string) $repo['full_name'] => (string) $repo['full_name']])
            ->all();
    }

    /** @return array<string, string> */
    private static function branchOptions(GitClient $api, string $repo): array
    {
        return collect(self::every($api, '/repos/'.self::path($repo).'/branches', [], 3))
            ->mapWithKeys(fn ($branch) => [(string) $branch['name'] => (string) $branch['name']])
            ->all();
    }

    /**
     * An issue or a pull request with the repository it lives in.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function item(array $item, ?string $repo = null): array
    {
        $repo ??= (string) preg_replace('#^.*/repos/#', '', (string) ($item['repository_url'] ?? data_get($item, 'base.repo.full_name', '')));

        return [...$item, '_repo' => $repo, '_ref' => $repo.'#'.($item['number'] ?? '')];
    }

    /** @param  array<string, mixed>  $row */
    private static function merged(array $row): bool
    {
        return filled($row['merged_at'] ?? data_get($row, 'pull_request.merged_at'));
    }

    /** @param  array<string, mixed>  $row */
    private static function state(array $row): string
    {
        return match (true) {
            self::merged($row) => 'merged',
            ($row['state'] ?? '') === 'open' && (bool) ($row['draft'] ?? false) => 'draft',
            default => (string) ($row['state'] ?? ''),
        };
    }

    /** @param  array<string, mixed>  $row */
    private static function issueUrl(array $row, string $suffix = ''): string
    {
        return '/repos/'.self::path((string) $row['_repo']).'/issues/'.$row['number'].$suffix;
    }

    /** @param  array<string, mixed>  $row */
    private static function pullUrl(array $row, string $suffix = ''): string
    {
        return '/repos/'.self::path((string) $row['_repo']).'/pulls/'.$row['number'].$suffix;
    }

    /**
     * The search of issues and pull requests, newest activity first.
     *
     * @return list<array<string, mixed>>
     */
    private static function search(GitClient $api, string $query, int $perPage = 100): array
    {
        $answer = $api->get('/search/issues', ['q' => $query, 'sort' => 'updated', 'order' => 'desc', 'per_page' => $perPage]);

        return array_map(fn ($item) => self::item($item), array_values(array_filter((array) ($answer['items'] ?? []), 'is_array')));
    }

    /**
     * The search query of a list across repositories: whose, which state, which repository.
     *
     * @param  array<string, mixed>  $scope
     */
    private static function query(GitClient $api, string $kind, array $scope, string $default): string
    {
        $which = (string) ($scope['which'] ?? $default);
        $state = (string) ($scope['state'] ?? 'open');

        return implode(' ', array_filter([
            'is:'.$kind,
            'archived:false',
            ($which !== '' ? $which : 'involves').':'.self::me($api),
            match ($state) {
                'open' => 'is:open',
                'closed' => $kind === 'pr' ? 'is:closed is:unmerged' : 'is:closed',
                'merged' => 'is:merged',
                default => null,
            },
            filled($scope['repo_filter'] ?? null) ? 'repo:'.$scope['repo_filter'] : null,
        ]));
    }

    /* ---------------------------------------------------------------- overview */

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (GitClient $api) {
            $me = self::me($api);
            $user = $api->get('/user');
            $limits = $api->get('/rate_limit');
            $list = function (string $query) use ($api) {
                $answer = $api->get('/search/issues', ['q' => $query, 'sort' => 'updated', 'order' => 'desc', 'per_page' => 10]);
                $rows = collect((array) ($answer['items'] ?? []))->filter(fn ($item) => is_array($item))
                    ->mapWithKeys(fn ($item) => [self::item($item)['_ref'] => (string) ($item['title'] ?? '')])->all();

                return [(int) ($answer['total_count'] ?? 0), $rows];
            };

            [$reviews, $reviewRows] = $list("is:pr is:open archived:false review-requested:{$me}");
            [$mine, $mineRows] = $list("is:pr is:open archived:false author:{$me}");
            [$assigned, $assignedRows] = $list("is:issue is:open archived:false assignee:{$me}");

            return [
                __('Waiting for my review (:count)', ['count' => $reviews]) => $reviewRows,
                __('My open pull requests (:count)', ['count' => $mine]) => $mineRows,
                __('Issues assigned to me (:count)', ['count' => $assigned]) => $assignedRows,
                __('Account') => [
                    __('Signed in as') => $me.(filled($user['name'] ?? null) ? ' ('.$user['name'].')' : ''),
                    __('Repositories') => (int) ($user['public_repos'] ?? 0) + (int) ($user['total_private_repos'] ?? 0),
                    __('API requests left') => data_get($limits, 'resources.core.remaining').' / '.data_get($limits, 'resources.core.limit'),
                    __('Searches left') => data_get($limits, 'resources.search.remaining').' / '.data_get($limits, 'resources.search.limit'),
                ],
            ];
        })->icon('home')->description(__('What waits for you across every repository the token reaches.'));
    }

    /* ---------------------------------------------------------------- issues */

    private function issues(string $key): Section
    {
        $child = $key === 'repo-issues';
        $issueFields = fn (bool $withRepo) => [
            ...($withRepo ? [Field::select('repo', __('Repository'), fn (GitClient $api) => self::repoOptions($api))->required()] : []),
            Field::text('title', __('Title'))->required(),
            Field::textarea('body', __('Description'))->help(__('Markdown.')),
            Field::text('labels', __('Labels'))->placeholder('bug, urgent'),
            Field::text('assignees', __('Assignees'))->placeholder('octocat'),
        ];
        $create = fn (GitClient $api, string $repo, array $data) => Outcome::show(
            __('Issue created'),
            (string) ($api->post('/repos/'.self::path($repo).'/issues', array_filter([
                'title' => $data['title'], 'body' => $data['body'], 'labels' => self::lines($data['labels']), 'assignees' => self::lines($data['assignees']),
            ]))['html_url'] ?? ''),
            __('Issue created.'),
        );

        $section = Section::make($key, __('Issues'))
            ->icon('exclamation-circle')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [Field::select('which', __('Which'), [
                    'assignee' => __('Assigned to me'), 'author' => __('Opened by me'), 'mentions' => __('Mentioning me'), '' => __('Involving me'),
                ])->default('assignee')]),
                Field::select('state', __('State'), ['open' => __('Open'), 'closed' => __('Closed'), '' => __('Any')])->default('open'),
            ])
            ->list(fn (GitClient $api, array $scope) => $child
                ? array_values(array_map(fn ($item) => self::item($item, $scope['repo']), array_filter(
                    self::every($api, '/repos/'.self::path($scope['repo']).'/issues', ['state' => ($scope['state'] ?? '') ?: 'all', 'sort' => 'updated'], 3),
                    fn ($item) => ! isset($item['pull_request']),
                )))
                : self::search($api, self::query($api, 'issue', $scope, 'assignee')))
            ->columns([
                Column::make($child ? 'number' : '_ref', __('Issue'))->mono()->using(fn ($row) => $child ? '#'.$row['number'] : $row['_ref']),
                Column::make('title', __('Title'))->link('html_url'),
                Column::make('labels', __('Labels'))->using(fn ($row) => array_column((array) ($row['labels'] ?? []), 'name')),
                Column::make('assignees', __('Assignees'))->using(fn ($row) => array_column((array) ($row['assignees'] ?? []), 'login')),
                Column::make('state', __('State'))->badge(['open' => 'green', 'closed' => 'purple']),
                Column::make('comments', __('Comments')),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions($this->conversationActions(false));

        if ($child) {
            return $section->childOf('repositories', 'repo_name')
                ->create(__('New issue'), $issueFields(false), fn (GitClient $api, array $row, array $data, array $scope) => $create($api, $scope['repo'], $data));
        }

        return $section->create(__('New issue'), $issueFields(true), fn (GitClient $api, array $row, array $data) => $create($api, $data['repo'], $data));
    }

    /* ---------------------------------------------------------------- pull requests */

    private function pulls(string $key): Section
    {
        $child = $key === 'repo-pulls';

        $section = Section::make($key, __('Pull requests'))
            ->icon('git-pull-request')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [Field::select('which', __('Which'), [
                    'review-requested' => __('Waiting for my review'), 'author' => __('Opened by me'), 'assignee' => __('Assigned to me'),
                    'mentions' => __('Mentioning me'), '' => __('Involving me'),
                ])->default('review-requested')]),
                Field::select('state', __('State'), ['open' => __('Open'), 'closed' => __('Closed'), 'merged' => __('Merged'), '' => __('Any')])->default('open'),
            ])
            ->list(fn (GitClient $api, array $scope) => $child
                ? array_values(array_filter(
                    array_map(fn ($item) => self::item($item, $scope['repo']), self::every($api, '/repos/'.self::path($scope['repo']).'/pulls', [
                        'state' => in_array($scope['state'] ?? '', ['open', 'closed'], true) ? $scope['state'] : (($scope['state'] ?? '') === 'merged' ? 'closed' : 'all'),
                        'sort' => 'updated', 'direction' => 'desc',
                    ], 3)),
                    fn ($row) => match ($scope['state'] ?? '') {
                        'merged' => self::merged($row),
                        'closed' => ! self::merged($row),
                        default => true,
                    },
                ))
                : self::search($api, self::query($api, 'pr', $scope, 'review-requested')))
            ->columns([
                Column::make($child ? 'number' : '_ref', __('Pull request'))->mono()->using(fn ($row) => $child ? '#'.$row['number'] : $row['_ref']),
                Column::make('title', __('Title'))->link('html_url'),
                ...($child ? [Column::make('head.ref', __('Branch'))->mono()->using(fn ($row) => data_get($row, 'head.ref').' → '.data_get($row, 'base.ref'))] : []),
                Column::make('user.login', __('Author')),
                Column::make('state', __('State'))->using(fn ($row) => self::state($row))->badge(['open' => 'green', 'draft' => 'zinc', 'merged' => 'purple', 'closed' => 'red']),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions($this->conversationActions(true));

        if (! $child) {
            return $section;
        }

        return $section->childOf('repositories', 'repo_name')->create(__('New pull request'), [
            Field::select('head', __('From branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
            Field::select('base', __('Into branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
            Field::text('title', __('Title'))->required(),
            Field::textarea('body', __('Description'))->help(__('Markdown.')),
            Field::checkbox('draft', __('Draft')),
        ], fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope['repo'], $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function openPull(GitClient $api, string $repo, array $data): Outcome
    {
        if ($data['head'] === $data['base']) {
            throw new ConnectorException(__('Pick two different branches.'));
        }

        $pull = $api->post('/repos/'.self::path($repo).'/pulls', array_filter([
            'head' => $data['head'], 'base' => $data['base'], 'title' => $data['title'], 'body' => $data['body'], 'draft' => (bool) $data['draft'],
        ], fn ($value) => $value !== null));

        return Outcome::show(__('Pull request #:number', ['number' => $pull['number'] ?? '?']), (string) ($pull['html_url'] ?? ''), __('Pull request opened.'));
    }

    /**
     * What is done on an issue or a pull request: they share comments, labels and state.
     *
     * @return list<Action>
     */
    private function conversationActions(bool $pull): array
    {
        $open = fn ($row) => ($row['state'] ?? '') === 'open';

        return [
            Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row) use ($pull) {
                $comments = array_map(fn ($c) => ['author' => data_get($c, 'user.login'), 'at' => $c['created_at'] ?? null, 'body' => $c['body'] ?? null],
                    self::every($api, self::issueUrl($row, '/comments'), [], 3));
                $facts = [
                    __('State') => self::state($row),
                    __('Author') => data_get($row, 'user.login'),
                    __('Opened') => Thread::when($row['created_at'] ?? null),
                    __('Labels') => array_column((array) ($row['labels'] ?? []), 'name'),
                    __('Assignees') => array_column((array) ($row['assignees'] ?? []), 'login'),
                ];

                if ($pull) {
                    $detail = $api->get(self::pullUrl($row));
                    $reviews = collect(self::every($api, self::pullUrl($row, '/reviews'), [], 2));
                    $facts += [
                        __('Branches') => data_get($detail, 'head.label').' → '.data_get($detail, 'base.ref'),
                        __('Changes') => __(':files files, +:add −:del', ['files' => $detail['changed_files'] ?? 0, 'add' => $detail['additions'] ?? 0, 'del' => $detail['deletions'] ?? 0]),
                        __('Mergeable') => $detail['mergeable_state'] ?? null,
                        __('Reviewers asked') => array_column((array) ($detail['requested_reviewers'] ?? []), 'login'),
                        __('Reviews') => $reviews->filter(fn ($r) => ($r['state'] ?? '') !== 'COMMENTED')
                            ->mapWithKeys(fn ($r) => [data_get($r, 'user.login') => strtolower(str_replace('_', ' ', (string) $r['state']))])
                            ->map(fn ($state, $who) => $who.' '.$state)->values()->all(),
                    ];
                    $comments = collect([...$comments, ...$reviews->filter(fn ($r) => filled($r['body'] ?? null))
                        ->map(fn ($r) => ['author' => data_get($r, 'user.login').' ('.strtolower(str_replace('_', ' ', (string) $r['state'])).')', 'at' => $r['submitted_at'] ?? null, 'body' => $r['body']])->all()])
                        ->sortBy('at')->values()->all();
                }

                return Outcome::show($row['_ref'].' '.($row['title'] ?? ''), Thread::text($facts, $row['body'] ?? null, $comments));
            }),
            ...($pull ? [
                Action::make('diff', __('Changes'))->icon('document-text')->run(fn (GitClient $api, array $row) => Outcome::show(
                    __('Changes of :ref', ['ref' => $row['_ref']]),
                    Thread::diff(array_map(fn ($f) => [($f['status'] ?? '').' '.($f['filename'] ?? ''), $f['patch'] ?? null], self::every($api, self::pullUrl($row, '/files'), [], 3))),
                )),
            ] : []),
            Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                Field::textarea('body', __('Comment'))->required()->help(__('Markdown.')),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Comment added.'), fn () => $api->post(self::issueUrl($row, '/comments'), ['body' => $data['body']]))),
            ...($pull ? [
                Action::make('approve', __('Approve'))->icon('check-badge')->when(fn ($row) => $open($row) && data_get($row, 'user.login') !== null)
                    ->run(fn (GitClient $api, array $row) => tap(__('Approved.'), fn () => $api->post(self::pullUrl($row, '/reviews'), ['event' => 'APPROVE']))),
                Action::make('review', __('Review'))->icon('chat-bubble-left-right')->when($open)->fields([
                    Field::select('event', __('Verdict'), ['APPROVE' => __('Approve'), 'REQUEST_CHANGES' => __('Request changes'), 'COMMENT' => __('Comment only')])->required()->default('COMMENT'),
                    Field::textarea('body', __('Review'))->help(__('Needed to request changes or to comment.')),
                ])->run(function (GitClient $api, array $row, array $data) {
                    if ($data['event'] !== 'APPROVE' && blank($data['body'])) {
                        throw new ConnectorException(__('Write what to change.'));
                    }

                    $api->post(self::pullUrl($row, '/reviews'), array_filter(['event' => $data['event'], 'body' => $data['body']]));

                    return __('Review sent.');
                }),
                Action::make('reviewers', __('Ask for a review'))->icon('user-plus')->when($open)->fields([
                    Field::text('reviewers', __('Reviewers'))->required()->placeholder('octocat, hubot'),
                ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Review requested.'), fn () => $api->post(self::pullUrl($row, '/requested_reviewers'), ['reviewers' => self::lines($data['reviewers'])]))),
                Action::make('merge', __('Merge'))->icon('git-merge')->when($open)->confirm(__('Merge this pull request?'))->fields([
                    Field::select('method', __('How'), ['merge' => __('Merge commit'), 'squash' => __('Squash and merge'), 'rebase' => __('Rebase and merge')])->required()->default('merge'),
                    Field::text('commit_title', __('Commit title'))->default(fn ($row) => ($row['title'] ?? '').' (#'.($row['number'] ?? '').')'),
                    Field::checkbox('delete_branch', __('Delete the branch afterwards')),
                ])->run(function (GitClient $api, array $row, array $data) {
                    $api->put(self::pullUrl($row, '/merge'), array_filter(['merge_method' => $data['method'], 'commit_title' => $data['commit_title']]));

                    if ($data['delete_branch']) {
                        $pull = $api->get(self::pullUrl($row));

                        if (data_get($pull, 'head.repo.full_name') === $row['_repo']) {
                            $api->delete('/repos/'.self::path($row['_repo']).'/git/refs/heads/'.self::path((string) data_get($pull, 'head.ref')));
                        }
                    }

                    return __('Merged.');
                }),
            ] : [
                Action::make('assign-me', __('Assign to me'))->icon('user')->when($open)
                    ->run(fn (GitClient $api, array $row) => tap(__('Assigned to you.'), fn () => $api->post(self::issueUrl($row, '/assignees'), ['assignees' => [self::me($api)]]))),
            ]),
            Action::make('edit', __('Edit'))->icon('pencil')->fields([
                Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? ''),
                Field::textarea('body', __('Description'))->default(fn ($row) => $row['body'] ?? ''),
                Field::text('labels', __('Labels'))->default(fn ($row) => implode(', ', array_column((array) ($row['labels'] ?? []), 'name'))),
                Field::text('assignees', __('Assignees'))->default(fn ($row) => implode(', ', array_column((array) ($row['assignees'] ?? []), 'login'))),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->patch(self::issueUrl($row), [
                'title' => $data['title'], 'body' => $data['body'] ?? '', 'labels' => self::lines($data['labels']), 'assignees' => self::lines($data['assignees']),
            ]))),
            Action::make('close', __('Close'))->icon('x-circle')->when($open)->confirm($pull ? __('Close this pull request without merging?') : __('Close this issue?'))
                ->run(fn (GitClient $api, array $row) => tap(__('Closed.'), fn () => $api->patch(self::issueUrl($row), ['state' => 'closed']))),
            Action::make('reopen', __('Reopen'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['state'] ?? '') === 'closed' && ! self::merged($row))
                ->run(fn (GitClient $api, array $row) => tap(__('Reopened.'), fn () => $api->patch(self::issueUrl($row), ['state' => 'open']))),
        ];
    }

    /* ---------------------------------------------------------------- notifications */

    private function notifications(): Section
    {
        return Section::make('notifications', __('Notifications'))
            ->icon('bell')
            ->description(__('Unread notifications. Classic tokens only: fine-grained ones cannot read them.'))
            ->list(fn (GitClient $api) => self::every($api, '/notifications', [], 2))
            ->columns([
                Column::make('repository.full_name', __('Repository'))->mono(),
                Column::make('subject.title', __('Subject')),
                Column::make('subject.type', __('Kind'))->badge(['pullrequest' => 'blue', 'issue' => 'green', 'release' => 'purple', '*' => 'zinc']),
                Column::make('reason', __('Why'))->badge(),
                Column::make('updated_at', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('read-all', __('Mark all as read'))->icon('check')->confirm(__('Mark every notification as read?'))
                    ->run(fn (GitClient $api) => tap(__('All read.'), fn () => $api->put('/notifications', ['read' => true]))),
            ])
            ->actions([
                Action::make('read', __('Mark as read'))->icon('check')
                    ->run(fn (GitClient $api, array $row) => tap(__('Read.'), fn () => $api->patch('/notifications/threads/'.$row['id']))),
                Action::make('mute', __('Unsubscribe'))->icon('bell-slash')
                    ->run(fn (GitClient $api, array $row) => tap(__('You will not hear about it again.'), fn () => $api->put('/notifications/threads/'.$row['id'].'/subscription', ['ignored' => true]))),
            ]);
    }

    /* ---------------------------------------------------------------- repositories */

    private function repositories(): Section
    {
        $scope = fn ($row) => ['repo' => $row['full_name'], 'repo_name' => $row['full_name'], 'default_branch' => $row['default_branch'] ?? ''];

        return Section::make('repositories', __('Repositories'))
            ->icon('folder')
            ->id('full_name')
            ->list(fn (GitClient $api) => self::every($api, '/user/repos', ['sort' => 'pushed', 'affiliation' => 'owner,collaborator,organization_member']))
            ->columns([
                Column::make('full_name', __('Repository'))->link('html_url'),
                Column::make('private', __('Visibility'))->using(fn ($row) => ($row['private'] ?? false) ? 'private' : 'public')->badge(['private' => 'amber', 'public' => 'green']),
                Column::make('language', __('Language')),
                Column::make('default_branch', __('Default branch'))->mono(),
                Column::make('open_issues_count', __('Open issues and PRs')),
                Column::make('archived', __('Archived'))->bool(),
                Column::make('pushed_at', __('Last push'))->date(),
            ])
            ->create(__('New repository'), [
                Field::select('owner', __('Owner'), fn (GitClient $api) => ['' => self::me($api), ...collect(self::every($api, '/user/orgs', [], 2))->mapWithKeys(fn ($o) => [(string) $o['login'] => (string) $o['login']])->all()]),
                Field::text('name', __('Name'))->required()->rules(['regex:/^[A-Za-z0-9._-]+$/']),
                Field::text('description', __('Description')),
                Field::checkbox('private', __('Private'))->default(true),
                Field::checkbox('auto_init', __('Start with a README'))->default(true),
            ], function (GitClient $api, array $row, array $data) {
                $repo = $api->post(filled($data['owner']) ? '/orgs/'.rawurlencode($data['owner']).'/repos' : '/user/repos', array_filter([
                    'name' => $data['name'], 'description' => $data['description'], 'private' => (bool) $data['private'], 'auto_init' => (bool) $data['auto_init'],
                ], fn ($value) => $value !== null));

                return Outcome::show(__('Repository :name', ['name' => $repo['full_name'] ?? $data['name']]), [
                    'web' => $repo['html_url'] ?? null, 'ssh' => $repo['ssh_url'] ?? null, 'https' => $repo['clone_url'] ?? null,
                ], __('Repository created.'));
            })
            ->actions([
                Action::make('pulls', __('Pull requests'))->icon('git-pull-request')->opens('repo-pulls', $scope),
                Action::make('issues', __('Issues'))->icon('exclamation-circle')->opens('repo-issues', $scope),
                Action::make('branches', __('Branches'))->icon('git-branch')->opens('branches', $scope),
                Action::make('runs', __('Actions'))->icon('play-circle')->opens('runs', $scope),
                Action::make('releases', __('Releases'))->icon('tag')->opens('releases', $scope),
                Action::make('clone', __('Clone addresses'))->icon('clipboard')->run(fn (GitClient $api, array $row) => Outcome::show($row['full_name'], [
                    'ssh' => $row['ssh_url'] ?? null, 'https' => $row['clone_url'] ?? null,
                ])),
                Action::make('edit', __('Settings'))->icon('cog-6-tooth')->fields([
                    Field::text('description', __('Description'))->default(fn ($row) => $row['description'] ?? ''),
                    Field::text('homepage', __('Website'))->default(fn ($row) => $row['homepage'] ?? ''),
                    Field::checkbox('private', __('Private'))->default(fn ($row) => (bool) ($row['private'] ?? false)),
                ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->patch('/repos/'.self::path($row['full_name']), [
                    'description' => $data['description'] ?? '', 'homepage' => $data['homepage'] ?? '', 'private' => (bool) $data['private'],
                ]))),
                Action::make('archive', __('Archive'))->icon('archive-box')->when(fn ($row) => ! ($row['archived'] ?? false))->confirm(__('Archive the repository? It becomes read-only.'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Archived.'), fn () => $api->patch('/repos/'.self::path($row['full_name']), ['archived' => true]))),
                Action::make('unarchive', __('Unarchive'))->icon('archive-box-x-mark')->when(fn ($row) => (bool) ($row['archived'] ?? false))
                    ->run(fn (GitClient $api, array $row) => tap(__('Unarchived.'), fn () => $api->patch('/repos/'.self::path($row['full_name']), ['archived' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the repository with its issues, pull requests and history? This cannot be undone.'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Repository deleted.'), fn () => $api->delete('/repos/'.self::path($row['full_name'])))),
            ]);
    }

    /* ---------------------------------------------------------------- branches */

    private function branches(): Section
    {
        $repo = fn (array $scope) => '/repos/'.self::path($scope['repo']);

        return Section::make('branches', __('Branches'))
            ->icon('git-branch')
            ->id('name')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => array_map(
                fn ($branch) => [...$branch, '_default' => $branch['name'] === ($scope['default_branch'] ?? null)],
                self::every($api, $repo($scope).'/branches', [], 3),
            ))
            ->columns([
                Column::make('name', __('Branch'))->mono(),
                Column::make('_default', __('Default'))->bool(),
                Column::make('protected', __('Protected'))->bool(),
                Column::make('commit.sha', __('Commit'))->mono()->using(fn ($row) => substr((string) data_get($row, 'commit.sha'), 0, 7)),
            ])
            ->create(__('New branch'), [
                Field::text('name', __('Name'))->required()->placeholder('feature/login'),
                Field::select('from', __('From'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($repo) {
                $sha = data_get($api->get($repo($scope).'/branches/'.self::path($data['from'])), 'commit.sha');
                $api->post($repo($scope).'/git/refs', ['ref' => 'refs/heads/'.$data['name'], 'sha' => $sha]);

                return __('Branch created.');
            })
            ->actions([
                Action::make('pull', __('Open a pull request'))->icon('git-pull-request')->when(fn ($row) => ! $row['_default'])->fields([
                    Field::select('base', __('Into branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
                    Field::text('title', __('Title'))->required()->default(fn ($row) => $row['name']),
                    Field::textarea('body', __('Description')),
                    Field::checkbox('draft', __('Draft')),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope['repo'], ['head' => $row['name'], ...$data])),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ! $row['_default'] && ! ($row['protected'] ?? false))
                    ->danger(__('Delete the branch? Commits only on it are lost.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Branch deleted.'), fn () => $api->delete($repo($scope).'/git/refs/heads/'.self::path($row['name'])))),
            ]);
    }

    /* ---------------------------------------------------------------- actions runs */

    private function runs(): Section
    {
        $repo = fn (array $scope) => '/repos/'.self::path($scope['repo']);

        return Section::make('runs', __('Actions'))
            ->icon('play-circle')
            ->childOf('repositories', 'repo_name')
            ->filters([
                Field::select('status', __('Status'), ['' => __('Any'), 'in_progress' => __('Running'), 'queued' => __('Queued'), 'failure' => __('Failed'), 'success' => __('Succeeded')]),
            ])
            ->list(fn (GitClient $api, array $scope) => array_values((array) ($api->get($repo($scope).'/actions/runs', array_filter([
                'per_page' => 50, 'status' => $scope['status'] ?? null,
            ]))['workflow_runs'] ?? [])))
            ->columns([
                Column::make('run_number', __('Run'))->mono()->using(fn ($row) => '#'.$row['run_number']),
                Column::make('display_title', __('Title'))->link('html_url'),
                Column::make('name', __('Workflow')),
                Column::make('head_branch', __('Branch'))->mono(),
                Column::make('event', __('Event')),
                Column::make('conclusion', __('Result'))->using(fn ($row) => $row['conclusion'] ?? $row['status'] ?? '')
                    ->badge(['success' => 'green', 'failure' => 'red', 'cancelled' => 'zinc', 'in_progress' => 'blue', 'queued' => 'yellow', '*' => 'zinc']),
                Column::make('actor.login', __('By')),
                Column::make('created_at', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('dispatch', __('Run a workflow'))->icon('play')->primary()->fields([
                    Field::select('workflow', __('Workflow'), fn (GitClient $api, array $scope) => collect((array) ($api->get($repo($scope).'/actions/workflows', ['per_page' => 100])['workflows'] ?? []))
                        ->where('state', 'active')->mapWithKeys(fn ($w) => [(string) $w['id'] => (string) $w['name']])->all())->required()
                        ->help(__('Only workflows with a workflow_dispatch trigger can be started by hand.')),
                    Field::select('ref', __('Branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Workflow started: it shows here in a few seconds.'), fn () => $api->post($repo($scope).'/actions/workflows/'.$data['workflow'].'/dispatches', ['ref' => $data['ref']]))),
            ])
            ->actions([
                Action::make('jobs', __('Jobs'))->icon('eye')->run(function (GitClient $api, array $row, array $data, array $scope) use ($repo) {
                    $text = collect((array) ($api->get($repo($scope).'/actions/runs/'.$row['id'].'/jobs', ['per_page' => 100])['jobs'] ?? []))
                        ->map(fn ($job) => '■ '.$job['name'].' · '.($job['conclusion'] ?? $job['status'])."\n".collect((array) ($job['steps'] ?? []))
                            ->map(fn ($step) => '   '.match ($step['conclusion'] ?? null) {
                                'success' => '✓', 'failure' => '✗', 'skipped' => '–', default => '…'
                            }.' '.$step['name'])->implode("\n"))
                        ->implode("\n\n");

                    return Outcome::show($row['display_title'] ?? $row['name'] ?? '', $text !== '' ? $text : __('No jobs.'));
                }),
                Action::make('rerun-failed', __('Re-run failed jobs'))->icon('arrow-path')->when(fn ($row) => ($row['conclusion'] ?? null) === 'failure')
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Re-running the failed jobs.'), fn () => $api->post($repo($scope).'/actions/runs/'.$row['id'].'/rerun-failed-jobs'))),
                Action::make('rerun', __('Re-run all jobs'))->icon('arrow-path')->when(fn ($row) => ($row['status'] ?? '') === 'completed')
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Re-running.'), fn () => $api->post($repo($scope).'/actions/runs/'.$row['id'].'/rerun'))),
                Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => ($row['status'] ?? '') !== 'completed')->confirm(__('Cancel this run?'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Cancelling.'), fn () => $api->post($repo($scope).'/actions/runs/'.$row['id'].'/cancel'))),
            ]);
    }

    /* ---------------------------------------------------------------- releases */

    private function releases(): Section
    {
        $repo = fn (array $scope) => '/repos/'.self::path($scope['repo']);

        return Section::make('releases', __('Releases'))
            ->icon('tag')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => self::every($api, $repo($scope).'/releases', [], 2))
            ->columns([
                Column::make('name', __('Release'))->using(fn ($row) => $row['name'] ?: $row['tag_name'])->link('html_url'),
                Column::make('tag_name', __('Tag'))->mono(),
                Column::make('draft', __('Draft'))->bool(),
                Column::make('prerelease', __('Pre-release'))->bool(),
                Column::make('author.login', __('By')),
                Column::make('published_at', __('Published'))->date(),
            ])
            ->create(__('New release'), [
                Field::text('tag_name', __('Tag'))->required()->placeholder('v1.2.0'),
                Field::text('target_commitish', __('From'))->placeholder('main')->help(__('A branch or a commit, for a tag that does not exist yet; empty for the default branch.')),
                Field::text('name', __('Title')),
                Field::textarea('body', __('Notes'))->help(__('Markdown; empty to have GitHub write them from the merged pull requests.')),
                Field::checkbox('draft', __('Draft')),
                Field::checkbox('prerelease', __('Pre-release')),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($repo) {
                $release = $api->post($repo($scope).'/releases', [
                    ...array_filter($data, fn ($value) => $value !== null),
                    'generate_release_notes' => blank($data['body']),
                ]);

                return Outcome::show(__('Release :tag', ['tag' => $data['tag_name']]), (string) ($release['html_url'] ?? ''), __('Release created.'));
            })
            ->actions([
                Action::make('notes', __('Notes'))->icon('eye')->run(fn (GitClient $api, array $row) => Outcome::show(($row['name'] ?: $row['tag_name']), (string) ($row['body'] ?? ''))),
                Action::make('publish', __('Publish'))->icon('megaphone')->when(fn ($row) => (bool) ($row['draft'] ?? false))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Published.'), fn () => $api->patch($repo($scope).'/releases/'.$row['id'], ['draft' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the release? The tag stays.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Release deleted.'), fn () => $api->delete($repo($scope).'/releases/'.$row['id']))),
            ]);
    }
}
