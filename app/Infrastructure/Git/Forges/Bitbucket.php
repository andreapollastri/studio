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
 * Bitbucket Cloud with the person's own API token: their pull requests,
 * those they review in the repositories touched last and, repository by
 * repository, pull requests, issues (where the tracker is on), branches and
 * Pipelines.
 */
final class Bitbucket extends Forge
{
    /** How many recently updated repositories are searched for pull requests waiting for a review. */
    private const REVIEW_REPOSITORIES = 20;

    public function key(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function description(): string
    {
        return __('Your pull requests and those waiting for your review, and for each repository pull requests, issues, branches and Pipelines: review, approve, merge and comment from Studio.');
    }

    public function icon(): string
    {
        return 'archive-box';
    }

    public function docs(): string
    {
        return 'https://developer.atlassian.com/cloud/bitbucket/rest/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API token'))->required()
                ->help(__('The same token clones, pushes and drives this page: see below.')),
        ];
    }

    public function client(array $credentials): GitClient
    {
        return new GitClient('https://api.bitbucket.org/2.0', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var GitClient $api */
        return 'ok · '.($api->get('/user')['display_name'] ?? '?');
    }

    public function host(array $credentials): string
    {
        return 'bitbucket.org';
    }

    public function gitUsername(string $login): string
    {
        return 'x-bitbucket-api-token-auth';
    }

    public function identity(GitClient $api): array
    {
        $user = $api->get('/user');

        try {
            $email = collect((array) ($api->get('/user/emails')['values'] ?? []))->first(fn ($e) => is_array($e) && ($e['is_primary'] ?? false))['email'] ?? null;
        } catch (ConnectorException) {
            $email = null;
        }

        return [
            'login' => (string) ($user['username'] ?? $user['nickname'] ?? ''),
            'name' => $user['display_name'] ?? null,
            'email' => $email,
        ];
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->pulls('pulls'),
            $this->repositories(),
            $this->pulls('repo-pulls'),
            $this->issues(),
            $this->branches(),
            $this->pipelines(),
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    /** @return array{uuid: string, name: string} */
    private static function me(GitClient $api): array
    {
        $me = $api->remember('user', fn () => $api->get('/user'));

        return ['uuid' => (string) ($me['uuid'] ?? ''), 'name' => (string) ($me['display_name'] ?? '')];
    }

    public function repositorySection(): string
    {
        return 'repositories';
    }

    public function repository(GitClient $api, string $path): array
    {
        return $api->get(self::repo($path));
    }

    private static function repo(string $fullName): string
    {
        return '/repositories/'.implode('/', array_map('rawurlencode', explode('/', $fullName)));
    }

    /**
     * Every page of a Bitbucket list: values, then the next address.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(GitClient $api, string $path, array $query = [], int $maxPages = 5): array
    {
        // Bitbucket wants a list as the same name repeated (state=OPEN&state=MERGED), not state[0]=.
        $pairs = [];

        foreach (['pagelen' => 50, ...array_filter($query, fn ($v) => $v !== null && $v !== '')] as $name => $value) {
            foreach ((array) $value as $one) {
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $one);
            }
        }

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path.'?'.implode('&', $pairs),
            [],
            fn (array $answer) => array_values(array_filter((array) ($answer['values'] ?? []), 'is_array')),
            fn (array $answer) => is_string($answer['next'] ?? null) ? $answer['next'] : null,
            $maxPages,
        );
    }

    /**
     * The slugs of the workspaces the person belongs to. Bitbucket removed its
     * cross-workspace lists (CHANGE-2770, April 2026): repositories and pull
     * requests are asked for workspace by workspace.
     *
     * @return list<string>
     */
    private static function workspaces(GitClient $api): array
    {
        /** @var list<string> */
        return $api->remember('workspaces', fn () => collect(self::every($api, '/user/workspaces', ['sort' => 'slug'], 2))
            ->map(fn ($access) => (string) data_get($access, 'workspace.slug', ''))
            ->filter()->unique()->values()->all());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function repositoriesOf(GitClient $api, array $query = [], int $maxPages = 5): array
    {
        $rows = [];

        foreach (self::workspaces($api) as $workspace) {
            array_push($rows, ...self::every($api, '/repositories/'.rawurlencode($workspace), ['role' => 'member', 'sort' => '-updated_on', ...$query], $maxPages));
        }

        return array_values(collect($rows)->sortByDesc('updated_on')->all());
    }

    /**
     * The pull requests a person opened, in every workspace they belong to.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function pullsBy(GitClient $api, string $uuid, array $query = [], int $maxPages = 3): array
    {
        $rows = [];

        foreach (self::workspaces($api) as $workspace) {
            foreach (self::every($api, '/workspaces/'.rawurlencode($workspace).'/pullrequests/'.rawurlencode($uuid), $query, $maxPages) as $pull) {
                $rows[] = self::pull($pull);
            }
        }

        return array_values(collect($rows)->sortByDesc('updated_on')->all());
    }

    /** @return array<string, string> */
    private static function branchOptions(GitClient $api, string $repo): array
    {
        return collect(self::every($api, self::repo($repo).'/refs/branches', ['sort' => '-target.date'], 3))
            ->mapWithKeys(fn ($b) => [(string) $b['name'] => (string) $b['name']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pull
     * @return array<string, mixed>
     */
    private static function pull(array $pull): array
    {
        $repo = (string) data_get($pull, 'destination.repository.full_name', '');

        return [...$pull, '_repo' => $repo, '_ref' => $repo.'#'.($pull['id'] ?? '')];
    }

    /** @param  array<string, mixed>  $row */
    private static function pullUrl(array $row, string $suffix = ''): string
    {
        return self::repo($row['_repo']).'/pullrequests/'.$row['id'].$suffix;
    }

    /** @param  array<string, mixed>  $row */
    private static function state(array $row): string
    {
        return ($row['state'] ?? '') === 'OPEN' && (bool) ($row['draft'] ?? false) ? 'draft' : strtolower((string) ($row['state'] ?? ''));
    }

    /**
     * Open pull requests where the person is a reviewer, in the repositories updated last.
     *
     * @return list<array<string, mixed>>
     */
    private static function reviewing(GitClient $api, string $state = 'OPEN'): array
    {
        $uuid = self::me($api)['uuid'];
        $rows = [];

        foreach (array_slice(self::repositoriesOf($api, ['pagelen' => self::REVIEW_REPOSITORIES], 1), 0, self::REVIEW_REPOSITORIES) as $repo) {
            foreach (self::every($api, self::repo((string) $repo['full_name']).'/pullrequests', ['q' => 'reviewers.uuid="'.$uuid.'"', 'state' => $state ?: null], 1) as $pull) {
                $rows[] = self::pull($pull);
            }
        }

        return array_values(collect($rows)->sortByDesc('updated_on')->all());
    }

    /* ---------------------------------------------------------------- overview */

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (GitClient $api) {
            $me = self::me($api);
            $reviews = self::reviewing($api);
            $mine = self::pullsBy($api, $me['uuid'], ['state' => 'OPEN'], 2);
            $titles = fn (array $rows) => collect($rows)->take(10)->mapWithKeys(fn ($r) => [$r['_ref'] => (string) ($r['title'] ?? '')])->all();

            return [
                __('Waiting for my review (:count)', ['count' => count($reviews)]) => $titles($reviews),
                __('My open pull requests (:count)', ['count' => count($mine)]) => $titles($mine),
                __('Account') => [
                    __('Signed in as') => $me['name'],
                    __('Reviews looked for in') => trans_choice('the :count repository updated last|the :count repositories updated last', self::REVIEW_REPOSITORIES),
                ],
            ];
        })->icon('home')->description(__('What waits for you. Reviews are looked for in the repositories updated last.'));
    }

    /* ---------------------------------------------------------------- pull requests */

    private function pulls(string $key): Section
    {
        $child = $key === 'repo-pulls';

        $section = Section::make($key, __('Pull requests'))
            ->icon('git-pull-request')
            ->id('_ref')
            ->filters([
                ...($child ? [] : [Field::select('which', __('Which'), ['reviewing' => __('Waiting for my review'), 'author' => __('Opened by me')])->default('reviewing')]),
                Field::select('state', __('State'), ['OPEN' => __('Open'), 'MERGED' => __('Merged'), 'DECLINED' => __('Declined'), '' => __('Any')])->default('OPEN'),
            ])
            ->list(function (GitClient $api, array $scope) use ($child) {
                $state = (string) ($scope['state'] ?? '');
                $states = $state !== '' ? $state : ['OPEN', 'MERGED', 'DECLINED', 'SUPERSEDED'];

                return match (true) {
                    $child => array_map(fn ($p) => self::pull($p), self::every($api, self::repo($scope['repo']).'/pullrequests', ['state' => $states], 3)),
                    ($scope['which'] ?? 'reviewing') === 'author' => self::pullsBy($api, self::me($api)['uuid'], ['state' => $states], 3),
                    default => self::reviewing($api, $state),
                };
            })
            ->columns([
                Column::make('_ref', __('Pull request'))->mono()->using(fn ($row) => $child ? '#'.$row['id'] : $row['_ref']),
                Column::make('title', __('Title'))->link('links.html.href'),
                Column::make('source.branch.name', __('Branch'))->mono()->using(fn ($row) => data_get($row, 'source.branch.name').' → '.data_get($row, 'destination.branch.name')),
                Column::make('author.display_name', __('Author')),
                Column::make('state', __('State'))->using(fn ($row) => self::state($row))->badge(['open' => 'green', 'draft' => 'zinc', 'merged' => 'purple', 'declined' => 'red', 'superseded' => 'zinc']),
                Column::make('comment_count', __('Comments')),
                Column::make('updated_on', __('Updated'))->date(),
            ])
            ->actions($this->pullActions());

        if (! $child) {
            return $section;
        }

        return $section->childOf('repositories', 'repo_name')->create(__('New pull request'), self::pullFields(true),
            fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope['repo'], $data));
    }

    /** @return list<Field> */
    private static function pullFields(bool $withSource): array
    {
        return [
            ...($withSource ? [Field::select('source', __('From branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required()] : []),
            Field::select('destination', __('Into branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
            Field::text('title', __('Title'))->required()->default(fn ($row) => $row['name'] ?? ''),
            Field::textarea('description', __('Description'))->help(__('Markdown.')),
            Field::checkbox('draft', __('Draft')),
            Field::checkbox('close_source_branch', __('Delete the branch once merged'))->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function openPull(GitClient $api, string $repo, array $data): Outcome
    {
        if ($data['source'] === $data['destination']) {
            throw new ConnectorException(__('Pick two different branches.'));
        }

        $pull = $api->post(self::repo($repo).'/pullrequests', array_filter([
            'title' => $data['title'],
            'description' => $data['description'],
            'source' => ['branch' => ['name' => $data['source']]],
            'destination' => ['branch' => ['name' => $data['destination']]],
            'close_source_branch' => (bool) $data['close_source_branch'],
            'draft' => (bool) $data['draft'],
        ], fn ($v) => $v !== null));

        return Outcome::show(__('Pull request #:id', ['id' => $pull['id'] ?? '?']), (string) data_get($pull, 'links.html.href', ''), __('Pull request opened.'));
    }

    /** @return list<Action> */
    private function pullActions(): array
    {
        $open = fn ($row) => ($row['state'] ?? '') === 'OPEN';

        return [
            Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row) {
                $pull = $api->get(self::pullUrl($row));
                $participants = collect((array) ($pull['participants'] ?? []));
                $comments = collect(self::every($api, self::pullUrl($row, '/comments'), [], 3))
                    ->reject(fn ($c) => (bool) ($c['deleted'] ?? false))
                    ->map(fn ($c) => [
                        'author' => data_get($c, 'user.display_name').(isset($c['inline']['path']) ? ' · '.$c['inline']['path'] : ''),
                        'at' => $c['created_on'] ?? null,
                        'body' => data_get($c, 'content.raw'),
                    ])->sortBy('at')->values()->all();

                return Outcome::show($row['_ref'].' '.($row['title'] ?? ''), Thread::text([
                    __('State') => self::state($pull),
                    __('Author') => data_get($pull, 'author.display_name'),
                    __('Opened') => Thread::when($pull['created_on'] ?? null),
                    __('Branches') => data_get($pull, 'source.branch.name').' → '.data_get($pull, 'destination.branch.name'),
                    __('Reviewers') => array_map(fn ($r) => $r['display_name'] ?? '', (array) ($pull['reviewers'] ?? [])),
                    __('Approved by') => $participants->where('approved', true)->map(fn ($p) => data_get($p, 'user.display_name'))->values()->all(),
                    __('Changes requested by') => $participants->where('state', 'changes_requested')->map(fn ($p) => data_get($p, 'user.display_name'))->values()->all(),
                ], $pull['description'] ?? null, $comments));
            }),
            Action::make('diff', __('Changes'))->icon('document-text')->run(fn (GitClient $api, array $row) => Outcome::show(
                __('Changes of :ref', ['ref' => $row['_ref']]),
                Str::limit($api->text(self::pullUrl($row, '/diff')), 200_000, "\n…".__('cut here: open it on the provider to read the rest.')) ?: __('No changes.'),
            )),
            Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                Field::textarea('body', __('Comment'))->required()->help(__('Markdown.')),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Comment added.'), fn () => $api->post(self::pullUrl($row, '/comments'), ['content' => ['raw' => $data['body']]]))),
            Action::make('approve', __('Approve'))->icon('check-badge')->when($open)
                ->run(fn (GitClient $api, array $row) => tap(__('Approved.'), fn () => $api->post(self::pullUrl($row, '/approve')))),
            Action::make('unapprove', __('Revoke my approval'))->icon('x-mark')->when($open)
                ->run(fn (GitClient $api, array $row) => tap(__('Approval revoked.'), fn () => $api->delete(self::pullUrl($row, '/approve')))),
            Action::make('request-changes', __('Request changes'))->icon('chat-bubble-left-right')->when($open)
                ->run(fn (GitClient $api, array $row) => tap(__('Changes requested.'), fn () => $api->post(self::pullUrl($row, '/request-changes')))),
            Action::make('merge', __('Merge'))->icon('git-merge')->when($open)->confirm(__('Merge this pull request?'))->fields([
                Field::select('merge_strategy', __('How'), ['merge_commit' => __('Merge commit'), 'squash' => __('Squash'), 'fast_forward' => __('Fast-forward')])->required()->default('merge_commit'),
                Field::text('message', __('Commit message')),
                Field::checkbox('close_source_branch', __('Delete the branch afterwards'))->default(fn ($row) => (bool) ($row['close_source_branch'] ?? false)),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Merged.'), fn () => $api->post(self::pullUrl($row, '/merge'), array_filter([
                'type' => 'pullrequest_merge_parameters', 'merge_strategy' => $data['merge_strategy'], 'message' => $data['message'], 'close_source_branch' => (bool) $data['close_source_branch'],
            ], fn ($v) => $v !== null)))),
            Action::make('edit', __('Edit'))->icon('pencil')->fields([
                Field::text('title', __('Title'))->required()->default(fn ($row) => $row['title'] ?? ''),
                Field::textarea('description', __('Description'))->default(fn ($row) => $row['description'] ?? ''),
            ])->run(fn (GitClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->put(self::pullUrl($row), ['title' => $data['title'], 'description' => $data['description'] ?? '']))),
            Action::make('decline', __('Decline'))->icon('x-circle')->when($open)->confirm(__('Decline this pull request? Bitbucket cannot reopen it.'))
                ->run(fn (GitClient $api, array $row) => tap(__('Declined.'), fn () => $api->post(self::pullUrl($row, '/decline')))),
        ];
    }

    /* ---------------------------------------------------------------- repositories */

    private function repositories(): Section
    {
        $scope = fn ($row) => ['repo' => $row['full_name'], 'repo_name' => $row['full_name'], 'default_branch' => data_get($row, 'mainbranch.name', '')];

        return Section::make('repositories', __('Repositories'))
            ->icon('folder')
            ->id('full_name')
            ->list(fn (GitClient $api) => self::repositoriesOf($api))
            ->columns([
                Column::make('full_name', __('Repository'))->link('links.html.href'),
                Column::make('is_private', __('Visibility'))->using(fn ($row) => ($row['is_private'] ?? false) ? 'private' : 'public')->badge(['private' => 'amber', 'public' => 'green']),
                Column::make('project.name', __('Project')),
                Column::make('language', __('Language')),
                Column::make('mainbranch.name', __('Default branch'))->mono(),
                Column::make('updated_on', __('Updated'))->date(),
            ])
            ->create(__('New repository'), [
                Field::select('workspace', __('Workspace'), fn (GitClient $api) => collect(self::every($api, '/user/workspaces', ['sort' => 'slug'], 2))
                    ->mapWithKeys(fn ($p) => [(string) data_get($p, 'workspace.slug') => (string) data_get($p, 'workspace.name')])->all())->required(),
                Field::text('slug', __('Name'))->required()->rules(['regex:/^[a-z0-9._-]+$/'])->help(__('Lower case letters, digits, dots, dashes and underscores.')),
                Field::text('project', __('Project key'))->placeholder('PROJ')->help(__('Empty for the workspace\'s default project.')),
                Field::text('description', __('Description')),
                Field::checkbox('is_private', __('Private'))->default(true),
            ], function (GitClient $api, array $row, array $data) {
                $repo = $api->post(self::repo($data['workspace'].'/'.$data['slug']), array_filter([
                    'scm' => 'git', 'is_private' => (bool) $data['is_private'], 'description' => $data['description'],
                    'project' => filled($data['project']) ? ['key' => $data['project']] : null,
                ], fn ($v) => $v !== null));

                return Outcome::show(__('Repository :name', ['name' => $repo['full_name'] ?? $data['slug']]), [
                    'web' => data_get($repo, 'links.html.href'),
                    ...collect((array) data_get($repo, 'links.clone', []))->mapWithKeys(fn ($l) => [(string) ($l['name'] ?? '') => $l['href'] ?? null])->all(),
                ], __('Repository created.'));
            })
            ->actions([
                Action::make('pulls', __('Pull requests'))->icon('git-pull-request')->opens('repo-pulls', $scope),
                Action::make('issues', __('Issues'))->icon('exclamation-circle')->when(fn ($row) => (bool) ($row['has_issues'] ?? false))->opens('repo-issues', $scope),
                Action::make('branches', __('Branches'))->icon('git-branch')->opens('branches', $scope),
                Action::make('pipelines', __('Pipelines'))->icon('play-circle')->opens('pipelines', $scope),
                Action::make('clone', __('Clone addresses'))->icon('clipboard')->run(fn (GitClient $api, array $row) => Outcome::show($row['full_name'],
                    collect((array) data_get($row, 'links.clone', []))->mapWithKeys(fn ($l) => [(string) ($l['name'] ?? '') => $l['href'] ?? null])->all())),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the repository with its pull requests and history? This cannot be undone.'))
                    ->run(fn (GitClient $api, array $row) => tap(__('Repository deleted.'), fn () => $api->delete(self::repo($row['full_name'])))),
            ]);
    }

    /* ---------------------------------------------------------------- issues */

    private function issues(): Section
    {
        $states = ['new' => __('New'), 'open' => __('Open'), 'resolved' => __('Resolved'), 'on hold' => __('On hold'), 'invalid' => __('Invalid'), 'duplicate' => __('Duplicate'), 'wontfix' => __('Won\'t fix'), 'closed' => __('Closed')];
        $path = fn (array $scope, mixed $id = null, string $suffix = '') => self::repo($scope['repo']).'/issues'.($id !== null ? '/'.$id : '').$suffix;

        return Section::make('repo-issues', __('Issues'))
            ->icon('exclamation-circle')
            ->childOf('repositories', 'repo_name')
            ->description(__('The issue tracker of the repository, where it is turned on.'))
            ->filters([
                Field::select('state', __('State'), ['unresolved' => __('Unresolved'), '' => __('Any'), ...$states])->default('unresolved'),
            ])
            ->list(fn (GitClient $api, array $scope) => self::every($api, $path($scope), [
                'sort' => '-updated_on',
                'q' => match ($scope['state'] ?? '') {
                    'unresolved' => '(state="new" OR state="open" OR state="on hold")',
                    '' => null,
                    default => 'state="'.$scope['state'].'"',
                },
            ], 3))
            ->columns([
                Column::make('id', __('Issue'))->mono()->using(fn ($row) => '#'.$row['id']),
                Column::make('title', __('Title'))->link('links.html.href'),
                Column::make('kind', __('Kind'))->badge(['bug' => 'red', 'enhancement' => 'blue', 'proposal' => 'purple', 'task' => 'zinc']),
                Column::make('priority', __('Priority'))->badge(['blocker' => 'red', 'critical' => 'red', 'major' => 'amber', '*' => 'zinc']),
                Column::make('assignee.display_name', __('Assignee')),
                Column::make('state', __('State'))->badge(['new' => 'green', 'open' => 'green', 'on hold' => 'yellow', '*' => 'purple']),
                Column::make('updated_on', __('Updated'))->date(),
            ])
            ->create(__('New issue'), [
                Field::text('title', __('Title'))->required(),
                Field::textarea('content', __('Description'))->help(__('Markdown.')),
                Field::select('kind', __('Kind'), ['bug' => __('Bug'), 'enhancement' => __('Enhancement'), 'proposal' => __('Proposal'), 'task' => __('Task')])->required()->default('bug'),
                Field::select('priority', __('Priority'), ['trivial' => __('Trivial'), 'minor' => __('Minor'), 'major' => __('Major'), 'critical' => __('Critical'), 'blocker' => __('Blocker')])->required()->default('major'),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                $issue = $api->post($path($scope), array_filter([
                    'title' => $data['title'], 'kind' => $data['kind'], 'priority' => $data['priority'],
                    'content' => filled($data['content']) ? ['raw' => $data['content']] : null,
                ]));

                return Outcome::show(__('Issue #:id', ['id' => $issue['id'] ?? '?']), (string) data_get($issue, 'links.html.href', ''), __('Issue created.'));
            })
            ->actions([
                Action::make('details', __('Read'))->icon('eye')->run(function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                    $comments = array_map(fn ($c) => ['author' => data_get($c, 'user.display_name'), 'at' => $c['created_on'] ?? null, 'body' => data_get($c, 'content.raw')],
                        self::every($api, $path($scope, $row['id'], '/comments'), [], 3));

                    return Outcome::show('#'.$row['id'].' '.$row['title'], Thread::text([
                        __('State') => $row['state'] ?? null,
                        __('Kind') => $row['kind'] ?? null,
                        __('Priority') => $row['priority'] ?? null,
                        __('Reporter') => data_get($row, 'reporter.display_name'),
                        __('Assignee') => data_get($row, 'assignee.display_name'),
                        __('Opened') => Thread::when($row['created_on'] ?? null),
                    ], data_get($row, 'content.raw'), $comments));
                }),
                Action::make('comment', __('Comment'))->icon('chat-bubble-left')->fields([
                    Field::textarea('body', __('Comment'))->required(),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Comment added.'), fn () => $api->post($path($scope, $row['id'], '/comments'), ['content' => ['raw' => $data['body']]]))),
                Action::make('state', __('Change state'))->icon('arrow-path-rounded-square')->fields([
                    Field::select('state', __('State'), $states)->required()->default(fn ($row) => $row['state'] ?? 'open'),
                    Field::textarea('message', __('Comment')),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('State changed.'), fn () => $api->post($path($scope, $row['id'], '/changes'), array_filter([
                    'changes' => ['state' => ['new' => $data['state']]],
                    'message' => filled($data['message']) ? ['raw' => $data['message']] : null,
                ])))),
                Action::make('assign-me', __('Assign to me'))->icon('user')
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Assigned to you.'), fn () => $api->put($path($scope, $row['id']), ['assignee' => ['uuid' => self::me($api)['uuid']]]))),
            ]);
    }

    /* ---------------------------------------------------------------- branches */

    private function branches(): Section
    {
        $path = fn (array $scope, string $suffix = '') => self::repo($scope['repo']).'/refs/branches'.$suffix;

        return Section::make('branches', __('Branches'))
            ->icon('git-branch')
            ->id('name')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => array_map(
                fn ($b) => [...$b, '_default' => $b['name'] === ($scope['default_branch'] ?? null)],
                self::every($api, $path($scope), ['sort' => '-target.date'], 3),
            ))
            ->columns([
                Column::make('name', __('Branch'))->mono()->link('links.html.href'),
                Column::make('_default', __('Default'))->bool(),
                Column::make('target.hash', __('Commit'))->mono()->using(fn ($row) => substr((string) data_get($row, 'target.hash'), 0, 7)),
                Column::make('target.author.raw', __('Last author'))->using(fn ($row) => data_get($row, 'target.author.user.display_name') ?? Str::before((string) data_get($row, 'target.author.raw'), ' <')),
                Column::make('target.date', __('Last commit'))->date(),
            ])
            ->create(__('New branch'), [
                Field::text('name', __('Name'))->required()->placeholder('feature/login'),
                Field::select('from', __('From'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
            ], function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                $hash = data_get($api->get($path($scope, '/'.rawurlencode($data['from']))), 'target.hash');
                $api->post($path($scope), ['name' => $data['name'], 'target' => ['hash' => $hash]]);

                return __('Branch created.');
            })
            ->actions([
                Action::make('pull', __('Open a pull request'))->icon('git-pull-request')->when(fn ($row) => ! $row['_default'])->fields(self::pullFields(false))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => self::openPull($api, $scope['repo'], ['source' => $row['name'], ...$data])),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ! $row['_default'])->danger(__('Delete the branch? Commits only on it are lost.'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Branch deleted.'), fn () => $api->delete($path($scope, '/'.rawurlencode($row['name']))))),
            ]);
    }

    /* ---------------------------------------------------------------- pipelines */

    private function pipelines(): Section
    {
        $path = fn (array $scope, string $suffix = '') => self::repo($scope['repo']).'/pipelines'.$suffix;
        $status = fn ($row) => strtolower((string) (data_get($row, 'state.result.name') ?? data_get($row, 'state.stage.name') ?? data_get($row, 'state.name', '')));

        return Section::make('pipelines', __('Pipelines'))
            ->icon('play-circle')
            ->id('uuid')
            ->childOf('repositories', 'repo_name')
            ->list(fn (GitClient $api, array $scope) => array_values(array_filter((array) ($api->get($path($scope), ['sort' => '-created_on', 'pagelen' => 50])['values'] ?? []), 'is_array')))
            ->columns([
                Column::make('build_number', __('Pipeline'))->mono()->using(fn ($row) => '#'.$row['build_number'])
                    ->link(fn ($row) => 'https://bitbucket.org/'.data_get($row, 'repository.full_name').'/pipelines/results/'.$row['build_number']),
                Column::make('target.ref_name', __('Branch'))->mono(),
                Column::make('trigger.name', __('Trigger'))->using(fn ($row) => strtolower((string) data_get($row, 'trigger.name'))),
                Column::make('state', __('Status'))->using($status)
                    ->badge(['successful' => 'green', 'failed' => 'red', 'error' => 'red', 'stopped' => 'zinc', 'in_progress' => 'blue', 'running' => 'blue', 'pending' => 'yellow', '*' => 'zinc']),
                Column::make('creator.display_name', __('By')),
                Column::make('created_on', __('When'))->date(),
            ])
            ->toolbar([
                Action::make('run', __('Run a pipeline'))->icon('play')->primary()->fields([
                    Field::select('ref', __('Branch'), fn (GitClient $api, array $scope) => self::branchOptions($api, $scope['repo']))->required(),
                ])->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Pipeline started.'), fn () => $api->post($path($scope), [
                    'target' => ['type' => 'pipeline_ref_target', 'ref_type' => 'branch', 'ref_name' => $data['ref']],
                ]))),
            ])
            ->actions([
                Action::make('steps', __('Steps'))->icon('eye')->run(function (GitClient $api, array $row, array $data, array $scope) use ($path) {
                    $text = collect(self::every($api, $path($scope, '/'.rawurlencode($row['uuid']).'/steps'), [], 2))
                        ->map(fn ($step) => match (strtolower((string) data_get($step, 'state.result.name'))) {
                            'successful' => '✓', 'failed', 'error' => '✗', 'stopped', 'not_run' => '–', default => '…'
                        }.' '.($step['name'] ?? __('step')).' · '.strtolower((string) (data_get($step, 'state.result.name') ?? data_get($step, 'state.name'))))
                        ->implode("\n");

                    return Outcome::show(__('Pipeline #:number', ['number' => $row['build_number']]), $text !== '' ? $text : __('No steps.'));
                }),
                Action::make('rerun', __('Run again'))->icon('arrow-path')->when(fn ($row) => data_get($row, 'state.name') === 'COMPLETED' && data_get($row, 'target.ref_type') === 'branch')
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Pipeline started.'), fn () => $api->post($path($scope), [
                        'target' => ['type' => 'pipeline_ref_target', 'ref_type' => 'branch', 'ref_name' => data_get($row, 'target.ref_name')],
                    ]))),
                Action::make('stop', __('Stop'))->icon('stop')->when(fn ($row) => data_get($row, 'state.name') !== 'COMPLETED')->confirm(__('Stop this pipeline?'))
                    ->run(fn (GitClient $api, array $row, array $data, array $scope) => tap(__('Stopping.'), fn () => $api->post($path($scope, '/'.rawurlencode($row['uuid']).'/stopPipeline')))),
            ]);
    }
}
