<?php

use App\Infrastructure\ApiClient;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Admin\Connectors as AdminConnectors;
use App\Livewire\Admin\Projects;
use App\Livewire\Settings\GitAccounts;
use App\Livewire\Tools\Git;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['studio.git.provider' => 'github', 'studio.git.url' => null]);
});

function gitAccount(User $user, string $forge, array $credentials = [], array $attributes = []): ConnectorAccount
{
    return ConnectorAccount::factory()->of($forge, ['token' => 'tok-'.$forge, ...$credentials])->create([
        'user_id' => $user->id, 'name' => ucfirst($forge).' '.$user->id, ...$attributes,
    ]);
}

test('every git host describes itself consistently', function () {
    expect(array_keys(Forges::all()))->toBe(['github', 'gitlab', 'bitbucket', 'azure']);

    foreach (Forges::all() as $key => $forge) {
        /** @var Forge $forge */
        $sections = $forge->sections();
        $keys = array_map(fn ($s) => $s->key, $sections);

        expect($forge->key())->toBe($key)
            ->and($forge->fields())->not->toBeEmpty()
            ->and($keys)->toBe(array_values(array_unique($keys)))
            ->and($sections[0]->kind)->toBe('info');

        foreach ($sections as $section) {
            expect($section->list)->not->toBeNull("{$key}/{$section->key} has no list");

            if ($section->isChild()) {
                expect($keys)->toContain($section->parent);
            }

            foreach ([...$section->actions, ...$section->toolbar] as $action) {
                expect($action->run !== null || $action->opens !== null)->toBeTrue("{$key}/{$section->key}/{$action->key} does nothing");

                if ($action->opens !== null) {
                    expect($forge->section($action->opens)?->parent)->toBe($section->key);
                }
            }
        }
    }
});

test('every git host is there for everyone, under one Git page whose picker moves across accounts and hosts', function () {
    $client = User::factory()->client()->create();

    $this->actingAs($client)->get(route('projects.index'))->assertSee(route('git'));
    $this->actingAs($client)->get(route('git'))->assertOk()->assertSee('No git account yet');
    $this->actingAs($client)->get(route('git', 'gitlab'))->assertOk();
    $this->actingAs($client)->get('/git/hetzner')->assertNotFound();

    $github = gitAccount($client, 'github', [], ['name' => 'Personal', 'host' => 'github.com']);
    $gitlab = gitAccount($client, 'gitlab', ['url' => 'https://gitlab.acme.test'], ['name' => 'Client', 'host' => 'gitlab.acme.test']);
    Http::fake(['*' => Http::response([])]);

    Livewire::actingAs($client)->test(Git::class)
        ->assertSet('connector', 'github')
        ->assertSet('account', $github->uuid)
        ->assertSee('Personal · github.com')
        ->assertSee('Client · gitlab.acme.test')
        ->call('useAccount', $gitlab->uuid)
        ->assertSet('connector', 'gitlab')
        ->assertSet('account', $gitlab->uuid)
        ->assertSet('tab', 'overview');

    // Administration → Connectors keeps the infrastructure and the team's service accounts, not git switches.
    Livewire::actingAs(User::factory()->admin()->create())->test(AdminConnectors::class)->assertSee('Git hosting')->assertSee('Service accounts');
    Livewire::actingAs($client)->test(AdminConnectors::class)->assertForbidden();
});

test('everyone adds their own accounts in Settings, with the identity their commits carry', function () {
    $dev = User::factory()->create(['name' => 'Ada Dev', 'email' => 'ada@studio.test']);
    $other = User::factory()->create();
    gitAccount($other, 'github', [], ['name' => 'Their GitHub']);

    Http::fake([
        'api.github.com/user/emails' => Http::response([['email' => 'ada@company.test', 'primary' => true, 'verified' => true]]),
        'api.github.com/user' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ghp_good')
            ? Http::response(['id' => 7, 'login' => 'ada', 'name' => 'Ada Lovelace'])
            : Http::response(['message' => 'Bad credentials'], 401),
        'api.github.com/*' => Http::response(['items' => [], 'total_count' => 0, 'resources' => []]),
    ]);

    $page = Livewire::actingAs($dev)->test(GitAccounts::class)
        ->assertDontSee('Their GitHub')
        ->call('add', 'github')
        ->set('name', 'Work')
        ->set('credentials.token', 'ghp_bad')
        ->call('save')
        ->assertHasErrors('credentials')
        ->set('credentials.token', 'ghp_good')
        ->call('save')
        ->assertHasNoErrors();

    $account = ConnectorAccount::query()->gitFor($dev)->sole();

    expect($account->name)->toBe('Work')
        ->and($account->user_id)->toBe($dev->id)
        ->and($account->login)->toBe('ada')
        ->and($account->host)->toBe('github.com')
        ->and($account->gitCredentials())->toBe([
            'provider' => 'github', 'token' => 'ghp_good', 'username' => 'x-access-token', 'login' => 'ada',
            'name' => 'Ada Lovelace', 'email' => 'ada@company.test', 'host' => 'github.com',
        ])
        ->and(DB::table('connector_accounts')->where('id', $account->id)->value('credentials'))->not->toContain('ghp_good');

    // The identity as typed wins; the token stays when left empty.
    $page->call('edit', $account->uuid)->set('git_email', 'ada@personal.test')->call('save')->assertHasNoErrors();
    expect($account->fresh()->git_email)->toBe('ada@personal.test')->and($account->fresh()->credential('token'))->toBe('ghp_good');

    // Nobody else sees, checks or removes it.
    Livewire::actingAs($other)->test(GitAccounts::class)->assertDontSee('ada@personal.test')->call('remove', $account->uuid)->assertNotFound();
    Livewire::actingAs($other)->test(GitAccounts::class)->call('check', $account->uuid)->assertNotFound();
    expect($account->fresh())->not->toBeNull();

    // The Accounts page has it under Git.
    $this->actingAs($dev)->get(route('connections.edit', ['tab' => 'git']))->assertOk()->assertSee('Work')->assertSee('ada@personal.test');
});

test('the workspaces take a new token or identity at once, and the first account names the person', function () {
    Queue::fake();
    $dev = User::factory()->create(['handle' => null, 'email' => 'ada@studio.test']);

    Http::fake([
        'api.github.com/user/emails' => Http::response([], 403),
        'api.github.com/user' => Http::response(['id' => 7, 'login' => 'ada-l', 'name' => 'Ada']),
    ]);

    $page = Livewire::actingAs($dev)->test(GitAccounts::class)
        ->call('add', 'github')->set('name', 'Personal')->set('credentials.token', 'ghp_one')->call('save')->assertHasNoErrors();

    $account = ConnectorAccount::query()->gitFor($dev)->sole();
    expect($dev->fresh()->handle)->toBe('ada-l')->and($account->git_email)->toBe('7+ada-l@users.noreply.github.com');
    Queue::assertPushed(SyncWorkspaceCredentials::class, 1);

    // Renaming only: nothing to send.
    $page->call('edit', $account->uuid)->set('name', 'Mine')->call('save')->assertHasNoErrors();
    Queue::assertPushed(SyncWorkspaceCredentials::class, 1);

    $page->call('edit', $account->uuid)->set('git_email', 'ada@home.test')->call('save')->assertHasNoErrors();
    Queue::assertPushed(SyncWorkspaceCredentials::class, 2);

    $page->call('remove', $account->uuid);
    Queue::assertPushed(SyncWorkspaceCredentials::class, 3);
    expect(ConnectorAccount::query()->gitFor($dev)->exists())->toBeFalse();
});

test('administrators keep service accounts that deploy project sites, which nobody commits with', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->create();

    Http::fake([
        'api.github.com/user/emails' => Http::response([], 403),
        'api.github.com/user' => Http::response(['id' => 9, 'login' => 'acme-deploy-bot', 'name' => null]),
    ]);

    Livewire::actingAs($dev)->test(GitAccounts::class, ['shared' => true])->assertForbidden();

    Livewire::actingAs($admin)->test(GitAccounts::class, ['shared' => true])
        ->call('add', 'github')->set('name', 'Deploy bot')->set('credentials.token', 'ghp_bot')->call('save')->assertHasNoErrors();

    $bot = ConnectorAccount::query()->whereNull('user_id')->sole();

    expect($bot->login)->toBe('acme-deploy-bot')
        ->and(ConnectorAccount::query()->gitFor($admin)->exists())->toBeFalse()
        ->and(ConnectorAccount::query()->gitFor($dev)->exists())->toBeFalse();
    Queue::assertNotPushed(SyncWorkspaceCredentials::class);

    // It is offered first for a new project, and a project deployed with it keeps it until another is chosen.
    Livewire::actingAs($admin)->test(Projects::class)->call('create')->assertSet('git_account', $bot->uuid)
        ->set('name', 'Shop')->set('repo_url', 'https://github.com/acme/shop.git')->call('save')->assertHasNoErrors();

    $project = Project::query()->where('name', 'Shop')->sole();
    expect($project->git_account_id)->toBe($bot->id)->and($project->deployAccount()?->account->is($bot))->toBeTrue();

    Livewire::actingAs($admin)->test(GitAccounts::class, ['shared' => true])->call('remove', $bot->uuid);
    expect($bot->fresh())->not->toBeNull();
});

test('a remote finds the account of its host', function () {
    $user = User::factory()->create();
    $github = gitAccount($user, 'github', [], ['host' => 'github.com']);
    $azure = gitAccount($user, 'azure', ['url' => 'https://dev.azure.com/Contoso'], ['host' => null]);
    $gitlab = gitAccount($user, 'gitlab', ['url' => 'gitlab.acme.test'], ['host' => null]);

    expect($github->matchesRemote('https://github.com/acme/shop.git'))->toBeTrue()
        ->and($github->matchesRemote('git@github.com:acme/shop.git'))->toBeTrue()
        ->and($github->matchesRemote('https://gitlab.com/acme/shop.git'))->toBeFalse()
        ->and($azure->gitCredentials()['host'])->toBe('dev.azure.com/contoso')
        ->and($azure->matchesRemote('https://contoso@dev.azure.com/contoso/Shop/_git/shop'))->toBeTrue()
        ->and($azure->matchesRemote('git@ssh.dev.azure.com:v3/contoso/Shop/shop'))->toBeTrue()
        ->and($azure->matchesRemote('https://dev.azure.com/fabrikam/Shop/_git/shop'))->toBeFalse()
        ->and($gitlab->gitCredentials()['host'])->toBe('gitlab.acme.test')
        ->and($gitlab->gitCredentials()['username'])->toBe('oauth2')
        ->and($gitlab->matchesRemote('ssh://git@gitlab.acme.test:2222/team/app.git'))->toBeTrue()
        ->and(ConnectorAccount::query()->gitFor($user)->count())->toBe(3);
});

test('GitHub pull requests waiting for a review are approved and merged from Studio', function () {
    $user = User::factory()->create();
    gitAccount($user, 'github');

    $pr = [
        'number' => 12, 'title' => 'Faster checkout', 'state' => 'open', 'draft' => false, 'user' => ['login' => 'bob'],
        'repository_url' => 'https://api.github.com/repos/acme/shop', 'html_url' => 'https://github.com/acme/shop/pull/12',
        'pull_request' => ['merged_at' => null], 'labels' => [], 'assignees' => [], 'updated_at' => '2026-10-12T10:00:00Z',
    ];

    Http::fake([
        'api.github.com/user' => Http::response(['id' => 1, 'login' => 'ada']),
        'api.github.com/search/issues*' => Http::response(['total_count' => 1, 'items' => [$pr]]),
        'api.github.com/repos/acme/shop/pulls/12/reviews' => Http::response(['id' => 1]),
        'api.github.com/repos/acme/shop/pulls/12/merge' => Http::response(['merged' => true]),
        'api.github.com/repos/acme/shop/pulls/12' => Http::response(['head' => ['ref' => 'feature/checkout', 'repo' => ['full_name' => 'acme/shop']]]),
        'api.github.com/repos/acme/shop/git/refs/heads/feature/checkout' => Http::response(null, 204),
        'api.github.com/repos/acme/shop/issues/12/comments' => Http::response(['id' => 3]),
    ]);

    Livewire::actingAs($user)->test(Git::class, ['connector' => 'github', 'tab' => 'pulls'])
        ->assertSee('acme/shop#12')->assertSee('Faster checkout')->assertSee('https://github.com/acme/shop/pull/12')
        ->call('start', 'approve', 'acme/shop#12')
        ->call('start', 'comment', 'acme/shop#12')->set('form.body', 'LGTM')->call('submit')->assertHasNoErrors()
        ->call('start', 'merge', 'acme/shop#12')->set('form.method', 'squash')->set('form.delete_branch', true)->call('submit')->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/search/issues') && str_contains(urldecode($r->url()), 'review-requested:ada') && str_contains(urldecode($r->url()), 'is:open'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls/12/reviews') && $r['event'] === 'APPROVE');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/issues/12/comments') && $r['body'] === 'LGTM');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/pulls/12/merge') && $r['merge_method'] === 'squash');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/git/refs/heads/feature/checkout'));
});

test('GitLab merge requests are approved and merged, issues closed', function () {
    ConnectorSetting::query()->create(['connector' => 'gitlab', 'enabled' => true]);
    $user = User::factory()->create();
    gitAccount($user, 'gitlab', ['url' => 'https://gitlab.acme.test']);

    Http::fake([
        'gitlab.acme.test/api/v4/user' => Http::response(['id' => 5, 'username' => 'ada']),
        'gitlab.acme.test/api/v4/merge_requests*' => Http::response([[
            'iid' => 4, 'project_id' => 9, 'title' => 'Add search', 'state' => 'opened', 'draft' => false, 'references' => ['full' => 'team/app!4'],
            'source_branch' => 'search', 'target_branch' => 'main', 'author' => ['username' => 'bob'], 'web_url' => 'https://gitlab.acme.test/team/app/-/merge_requests/4',
        ]]),
        'gitlab.acme.test/api/v4/projects/9/merge_requests/4/approve' => Http::response([]),
        'gitlab.acme.test/api/v4/projects/9/merge_requests/4/merge' => Http::response([]),
    ]);

    Livewire::actingAs($user)->test(Git::class, ['connector' => 'gitlab', 'tab' => 'merge-requests'])
        ->assertSee('team/app!4')->assertSee('Add search')->assertSee('search → main')
        ->call('start', 'approve', 'team/app!4')
        ->call('start', 'merge', 'team/app!4')->set('form.squash', true)->call('submit')->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/merge_requests?') && str_contains($r->url(), 'reviewer_username=ada') && $r->hasHeader('PRIVATE-TOKEN', 'tok-gitlab'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/projects/9/merge_requests/4/approve'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/merge_requests/4/merge') && $r['squash'] === true);
});

test('Bitbucket asks for several states as the same parameter repeated', function () {
    ConnectorSetting::query()->create(['connector' => 'bitbucket', 'enabled' => true]);
    $user = User::factory()->create();
    gitAccount($user, 'bitbucket');

    Http::fake([
        'api.bitbucket.org/2.0/user' => Http::response(['uuid' => '{u-1}', 'display_name' => 'Ada']),
        'api.bitbucket.org/2.0/pullrequests/*' => Http::response(['values' => [[
            'id' => 3, 'title' => 'Fix login', 'state' => 'OPEN', 'author' => ['display_name' => 'Ada'],
            'source' => ['branch' => ['name' => 'login']], 'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'acme/web']],
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/web/pull-requests/3']],
        ]]]),
        'api.bitbucket.org/2.0/repositories/acme/web/pullrequests/3/approve' => Http::response([]),
    ]);

    Livewire::actingAs($user)->test(Git::class, ['connector' => 'bitbucket', 'tab' => 'pulls'])
        ->set('filters.which', 'author')
        ->set('filters.state', '')
        ->assertSee('acme/web#3')->assertSee('Fix login')
        ->call('start', 'approve', 'acme/web#3');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/pullrequests/%7Bu-1%7D') && str_contains($r->url(), 'state=OPEN&state=MERGED'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pullrequests/3/approve') && $r->hasHeader('Authorization', 'Bearer tok-bitbucket'));
});

test('Azure DevOps work items come from WIQL, pull requests get a vote', function () {
    ConnectorSetting::query()->create(['connector' => 'azure', 'enabled' => true]);
    $user = User::factory()->create();
    gitAccount($user, 'azure', ['url' => 'https://dev.azure.com/contoso']);

    Http::fake([
        'dev.azure.com/contoso/_apis/connectionData' => Http::response(['authenticatedUser' => ['id' => 'me-1', 'providerDisplayName' => 'Ada', 'properties' => ['Account' => ['$value' => 'ada@contoso.test']]]]),
        'dev.azure.com/contoso/_apis/wit/wiql*' => Http::response(['workItems' => [['id' => 42]]]),
        'dev.azure.com/contoso/_apis/wit/workitems?*' => Http::response(['value' => [['id' => 42, 'fields' => [
            'System.Title' => 'Broken invoice PDF', 'System.State' => 'Active', 'System.WorkItemType' => 'Bug', 'System.TeamProject' => 'Billing',
        ]]]]),
        'dev.azure.com/contoso/Billing/_apis/wit/workitems/42*' => Http::response(['id' => 42]),
        'dev.azure.com/contoso/_apis/projects*' => Http::response(['value' => [['id' => 'p-1', 'name' => 'Billing']]]),
        'dev.azure.com/contoso/p-1/_apis/git/pullrequests*' => Http::response(['value' => [[
            'pullRequestId' => 8, 'title' => 'New tax rules', 'status' => 'active', 'createdBy' => ['displayName' => 'Bob'],
            'sourceRefName' => 'refs/heads/tax', 'targetRefName' => 'refs/heads/main', 'repository' => ['id' => 'r-1', 'name' => 'billing', 'project' => ['id' => 'p-1', 'name' => 'Billing']],
        ]]]),
        'dev.azure.com/contoso/p-1/_apis/git/repositories/r-1/pullRequests/8/reviewers/me-1*' => Http::response(['vote' => 10]),
    ]);

    Livewire::actingAs($user)->test(Git::class, ['connector' => 'azure', 'tab' => 'work-items'])
        ->assertSee('Broken invoice PDF')->assertSee('Bug')
        ->call('start', 'state', '42')->set('form.state', 'Resolved')->call('submit')->assertHasNoErrors()
        ->call('select', 'pulls')
        ->assertSee('billing!8')->assertSee('tax → main')
        ->call('start', 'approve', 'billing!8');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/_apis/wit/wiql') && str_contains($r['query'], '[System.AssignedTo] = @Me'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/Billing/_apis/wit/workitems/42?api-version=7.1')
        && $r->hasHeader('Content-Type', 'application/json-patch+json') && json_decode($r->body(), true)[0]['value'] === 'Resolved');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/pullRequests/8/reviewers/me-1?api-version=7.1') && $r['vote'] === 10
        && $r->hasHeader('Authorization', 'Basic '.base64_encode(':tok-azure')));
});

test('every tab and every form of every git host renders, whatever the provider answers', function () {
    Http::fake(['*' => Http::response([])]);
    $user = User::factory()->create();

    foreach (Forges::all() as $key => $forge) {
        ConnectorSetting::query()->updateOrCreate(['connector' => $key], ['enabled' => true]);
        gitAccount($user, $key, ['url' => 'https://dev.azure.com/x']);

        foreach ($forge->sections() as $section) {
            $page = Livewire::actingAs($user)->test(Git::class, ['connector' => $key])
                ->set('tab', $section->key)
                ->set('scope', $section->isChild() ? ['repo' => 'acme/x', 'repo_name' => 'acme/x', 'project' => 'p', 'default_branch' => 'main'] : [])
                ->assertOk()
                ->assertSee($section->label);

            foreach ($section->toolbar as $action) {
                $page->call('start', $action->key)->assertOk();
            }
        }

        Livewire::actingAs($user)->test(GitAccounts::class)->call('add', $key)->assertOk()->assertSee('Commit email')->assertSee('How to create the token');
    }
});

test('a list follows the provider\'s next page with its query', function () {
    Http::fake([
        'x.test/items?page=2' => Http::response(['data' => [['id' => 2]], 'next' => null]),
        'x.test/items*' => Http::response(['data' => [['id' => 1]], 'next' => 'https://x.test/items?page=2']),
    ]);

    $rows = (new ApiClient('https://x.test'))->all('/items', ['per_page' => 1], fn ($a) => $a['data'], fn ($a) => $a['next']);

    expect(array_column($rows, 'id'))->toBe([1, 2]);
});
