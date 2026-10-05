<?php

use App\Enums\SiteStatus;
use App\Git\Providers\Bitbucket;
use App\Git\Providers\GitHub;
use App\Git\Providers\GitProvider;
use App\Jobs\DeployProjectSite;
use App\Livewire\Admin\Projects;
use App\Livewire\Settings\Connections;
use App\Models\Project;
use App\Models\User;
use App\Server\ProjectSites;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function useProvider(string $provider, ?string $url = null): GitProvider
{
    config(['studio.git.provider' => $provider, 'studio.git.url' => $url]);

    return GitProvider::current();
}

/** POST a raw JSON body to the project's webhook with the given headers. */
function deliver(Project $project, array $payload, array $headers, ?string $body = null)
{
    $body ??= json_encode($payload);

    return test()->call('POST', route('api.git.webhook', $project), [], [], [], test()->transformHeadersToServerVars([...$headers, 'Content-Type' => 'application/json']), $body);
}

test('the installation has exactly the forges Larapilot integrates with', function () {
    expect(array_keys(GitProvider::PROVIDERS))->toBe(['github', 'gitlab', 'bitbucket', 'azure'])
        ->and(GitProvider::labels())->toBe(['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'azure' => 'Azure DevOps'])
        ->and(useProvider('github'))->toBeInstanceOf(GitHub::class)
        ->and(useProvider('gitlab')->baseUrl())->toBe('https://gitlab.com')
        ->and(useProvider('gitlab', 'https://git.acme.test/')->baseUrl())->toBe('https://git.acme.test')
        ->and(useProvider('bitbucket'))->toBeInstanceOf(Bitbucket::class)
        ->and(useProvider('azure', 'https://acme.visualstudio.com')->baseUrl())->toBe('https://dev.azure.com/acme');

    expect(fn () => GitProvider::make('gitea'))->toThrow(RuntimeException::class, 'Unknown git provider');
});

test('each provider recognises its own repository addresses and nothing else', function (string $provider, ?string $url, string $address, ?string $path) {
    expect(useProvider($provider, $url)->repositoryPath($address))->toBe($path);
})->with([
    ['github', null, 'https://github.com/acme/shop.git', 'acme/shop'],
    ['github', null, 'https://github.com/acme/shop', 'acme/shop'],
    ['github', null, 'https://gitlab.com/acme/shop', null],
    ['github', null, 'git@github.com:acme/shop.git', null],
    ['gitlab', null, 'https://gitlab.com/acme/web/shop.git', 'acme/web/shop'],
    ['gitlab', 'https://git.acme.test', 'https://git.acme.test/acme/shop', 'acme/shop'],
    ['gitlab', 'https://git.acme.test', 'https://gitlab.com/acme/shop', null],
    ['gitlab', null, 'https://gitlab.com/acme', null],
    ['bitbucket', null, 'https://maria@bitbucket.org/acme/shop.git', 'acme/shop'],
    ['bitbucket', null, 'https://bitbucket.org/acme/shop', 'acme/shop'],
    ['bitbucket', null, 'https://bitbucket.org/acme/web/shop', null],
    ['azure', 'https://dev.azure.com/acme', 'https://acme@dev.azure.com/acme/Web%20Shop/_git/shop', 'acme/Web Shop/shop'],
    ['azure', 'https://dev.azure.com/acme', 'https://acme.visualstudio.com/DefaultCollection/Web/_git/shop', 'acme/Web/shop'],
    ['azure', 'https://dev.azure.com/acme', 'https://dev.azure.com/other/Web/_git/shop', null],
]);

test('GitLab: a token is checked on the configured instance and the agent gets glab variables', function () {
    useProvider('gitlab', 'https://git.acme.test');
    $user = User::factory()->create(['handle' => null]);
    Http::fake(['https://git.acme.test/api/v4/user' => Http::response(['username' => 'maria.rossi', 'name' => 'Maria Rossi'])]);

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->assertSee('glpat-')
        ->set('git_token', 'glpat-0123456789abcdefghij')
        ->call('saveGit')
        ->assertHasNoErrors();

    expect($user->fresh()->git_login)->toBe('maria.rossi')
        ->and($user->fresh()->handle)->toBe('maria-rossi');

    Http::assertSent(fn (Request $request) => $request->hasHeader('PRIVATE-TOKEN', 'glpat-0123456789abcdefghij'));

    expect(GitProvider::current()->agentEnvironment('glpat-x'))->toBe(['GITLAB_TOKEN' => 'glpat-x', 'GLAB_TOKEN' => 'glpat-x', 'GITLAB_HOST' => 'https://git.acme.test'])
        ->and(useProvider('gitlab')->agentEnvironment('glpat-x'))->not->toHaveKey('GITLAB_HOST')
        ->and(GitProvider::current()->gitUsername())->toBe('oauth2');
});

test('GitLab: a new repository goes to the chosen group, the webhook carries the secret and the branch', function () {
    Queue::fake();
    useProvider('gitlab');
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_login' => 'andrea', 'git_token' => 'glpat-admin-000000000000']);

    Http::fake([
        'https://gitlab.com/api/v4/groups*' => Http::response([['id' => 42, 'full_path' => 'acme/web']]),
        'https://gitlab.com/api/v4/projects' => Http::response(['path_with_namespace' => 'acme/web/shop-next', 'http_url_to_repo' => 'https://gitlab.com/acme/web/shop-next.git', 'web_url' => 'https://gitlab.com/acme/web/shop-next', 'default_branch' => null], 201),
    ]);

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->assertSet('owners', [['value' => '42', 'label' => 'acme/web']])
        ->set('source', 'new')
        ->set('name', 'Shop Next')
        ->set('new_owner', '42')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('slug', 'shop-next')->firstOrFail();
    expect($project->repo_url)->toBe('https://gitlab.com/acme/web/shop-next.git')
        ->and($project->repositoryPath())->toBe('acme/web/shop-next');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://gitlab.com/api/v4/projects' && $request['namespace_id'] === 42 && $request['visibility'] === 'private');

    Http::fake(['https://gitlab.com/api/v4/projects/acme%2Fweb%2Fshop-next/hooks' => Http::response(['id' => 77], 201)]);
    expect(GitProvider::current()->createPushWebhook('glpat-admin', 'acme/web/shop-next', 'https://studio.test/api/git/webhook/shop-next', 's3cret', 'develop'))->toBe('77');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/hooks') && $request['token'] === 's3cret' && $request['push_events_branch_filter'] === 'develop');
});

test('GitLab: a push hook with the secret token deploys, a wrong token is refused', function () {
    Queue::fake();
    useProvider('gitlab');
    $project = Project::factory()->create(['slug' => 'shop', 'default_branch' => 'develop', 'webhook_secret' => 'hook-secret', 'site_status' => SiteStatus::Ready]);

    deliver($project, ['ref' => 'refs/heads/develop'], ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'hook-secret'])->assertStatus(202);
    deliver($project, ['ref' => 'refs/heads/main'], ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'hook-secret'])->assertJson(['ignored' => true]);
    deliver($project, ['ref' => 'refs/heads/develop'], ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => 'nope'])->assertForbidden();
    deliver($project, ['ref' => 'refs/heads/develop'], ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256=x'])->assertForbidden();

    Queue::assertPushed(DeployProjectSite::class, 1);
});

test('Bitbucket: workspaces and their projects are the owners; the repository lands in the chosen project', function () {
    Queue::fake();
    useProvider('bitbucket');
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_login' => 'andrea', 'git_token' => 'ATATT-admin-0000000000']);

    Http::fake([
        'https://api.bitbucket.org/2.0/user/workspaces*' => Http::response(['values' => [['administrator' => true, 'workspace' => ['slug' => 'acme', 'name' => 'Acme']]]]),
        'https://api.bitbucket.org/2.0/workspaces/acme/projects*' => Http::response(['values' => [['key' => 'WEB', 'name' => 'Web']]]),
        'https://api.bitbucket.org/2.0/repositories/acme/shop-next' => Http::response(['full_name' => 'acme/shop-next', 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/shop-next']], 'mainbranch' => null]),
    ]);

    $form = Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->assertSet('owners', [['value' => 'acme', 'label' => 'Acme'], ['value' => 'acme/WEB', 'label' => 'Acme / Web']])
        ->set('source', 'new')
        ->set('name', 'Shop Next')
        ->call('save')
        ->assertHasErrors(['new_owner']);

    $form->set('new_owner', 'acme/WEB')->call('save')->assertHasNoErrors();

    expect(Project::query()->where('slug', 'shop-next')->value('repo_url'))->toBe('https://bitbucket.org/acme/shop-next.git');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/shop-next'
        && $request['project'] === ['key' => 'WEB'] && $request['is_private'] === true
        && $request->hasHeader('Authorization', 'Bearer ATATT-admin-0000000000'));

    $git = GitProvider::current();
    expect($git->gitUsername())->toBe('x-bitbucket-api-token-auth')
        ->and($git->agentEnvironment('ATATT-x'))->toBe(['BITBUCKET_ACCESS_TOKEN' => 'ATATT-x']);
});

test('Bitbucket: the webhook is signed with the secret and a push to the branch deploys', function () {
    Queue::fake();
    useProvider('bitbucket');

    Http::fake(['https://api.bitbucket.org/2.0/repositories/acme/shop/hooks' => Http::response(['uuid' => '{hook-1}'], 201)]);
    expect(GitProvider::current()->createPushWebhook('ATATT', 'acme/shop', 'https://studio.test/api/git/webhook/shop', 's3cret', 'develop'))->toBe('{hook-1}');
    Http::assertSent(fn (Request $request) => $request['secret'] === 's3cret' && $request['events'] === ['repo:push']);

    $project = Project::factory()->create(['slug' => 'shop', 'default_branch' => 'develop', 'webhook_secret' => 'hook-secret', 'site_status' => SiteStatus::Ready]);
    $push = fn (string $branch) => ['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => $branch]]]]];
    $signed = function (array $payload, string $secret = 'hook-secret') use ($project) {
        $body = json_encode($payload);

        return deliver($project, $payload, ['X-Event-Key' => 'repo:push', 'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret)], $body);
    };

    $signed($push('develop'))->assertStatus(202);
    $signed($push('feature/x'))->assertJson(['ignored' => true]);
    $signed($push('develop'), 'wrong')->assertForbidden();

    Queue::assertPushed(DeployProjectSite::class, 1);
});

test('Azure DevOps: the token is checked against the organization and repositories live in its projects', function () {
    Queue::fake();
    useProvider('azure', 'https://dev.azure.com/acme');
    $admin = User::factory()->admin()->create(['handle' => null]);

    Http::fake([
        'https://dev.azure.com/acme/_apis/connectionData' => Http::response(['authenticatedUser' => ['providerDisplayName' => 'Andrea', 'properties' => ['Account' => ['$type' => 'System.String', '$value' => 'andrea@acme.test']]]]),
        'https://dev.azure.com/acme/_apis/projects?*' => Http::response(['value' => [['name' => 'Web', 'id' => 'p-1'], ['name' => 'Apps', 'id' => 'p-2']]]),
        'https://dev.azure.com/acme/_apis/projects/Web?*' => Http::response(['name' => 'Web', 'id' => 'p-1']),
        'https://dev.azure.com/acme/_apis/git/repositories?*' => Http::response(['id' => 'r-1', 'name' => 'shop-next', 'remoteUrl' => 'https://acme@dev.azure.com/acme/Web/_git/shop-next', 'webUrl' => 'https://dev.azure.com/acme/Web/_git/shop-next'], 201),
    ]);

    Livewire::actingAs($admin)
        ->test(Connections::class)
        ->assertSee('Read, write &amp; manage', false)
        ->set('git_token', str_repeat('a', 52))
        ->call('saveGit')
        ->assertHasNoErrors();

    expect($admin->fresh()->git_login)->toBe('andrea@acme.test')
        ->and($admin->fresh()->handle)->toBe('andrea');

    Livewire::actingAs($admin->fresh())
        ->test(Projects::class)
        ->call('create')
        ->assertSet('owners', [['value' => 'Apps', 'label' => 'Apps'], ['value' => 'Web', 'label' => 'Web']])
        ->set('source', 'new')
        ->set('name', 'Shop Next')
        ->set('new_owner', 'Web')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('slug', 'shop-next')->firstOrFail();
    expect($project->repo_url)->toBe('https://dev.azure.com/acme/Web/_git/shop-next')
        ->and($project->repositoryPath())->toBe('acme/Web/shop-next');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/_apis/git/repositories?api-version=7.1')
        && $request['project'] === ['id' => 'p-1']
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(':'.str_repeat('a', 52))));
});

test('Azure DevOps: the push service hook sends the secret as a header and git.push to the branch deploys', function () {
    Queue::fake();
    useProvider('azure', 'https://dev.azure.com/acme');

    Http::fake([
        'https://dev.azure.com/acme/Web/_apis/git/repositories/shop?*' => Http::response(['id' => 'r-1', 'project' => ['id' => 'p-1']]),
        'https://dev.azure.com/acme/_apis/hooks/subscriptions?*' => Http::response(['id' => 'sub-1']),
    ]);

    expect(GitProvider::current()->createPushWebhook('pat', 'acme/Web/shop', 'https://studio.test/api/git/webhook/shop', 's3cret', 'develop'))->toBe('sub-1');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/_apis/hooks/subscriptions')
        && $request['eventType'] === 'git.push'
        && $request['publisherInputs']['repository'] === 'r-1'
        && $request['consumerInputs']['httpHeaders'] === 'X-Studio-Token:s3cret');

    $project = Project::factory()->create(['slug' => 'shop', 'default_branch' => 'develop', 'webhook_secret' => 'hook-secret', 'site_status' => SiteStatus::Ready]);
    $push = fn (string $ref) => ['eventType' => 'git.push', 'resource' => ['refUpdates' => [['name' => $ref]]]];

    deliver($project, $push('refs/heads/develop'), ['X-Studio-Token' => 'hook-secret'])->assertStatus(202);
    deliver($project, $push('refs/tags/v1'), ['X-Studio-Token' => 'hook-secret'])->assertJson(['ignored' => true]);
    deliver($project, $push('refs/heads/develop'), ['X-Studio-Token' => 'nope'])->assertForbidden();

    Queue::assertPushed(DeployProjectSite::class, 1);

    expect(GitProvider::current()->agentEnvironment('pat'))->toBe(['AZURE_DEVOPS_EXT_PAT' => 'pat']);
});

test('Azure DevOps without an organization explains how to set it', function () {
    expect(fn () => useProvider('azure')->organization())->toThrow(RuntimeException::class, 'STUDIO_GIT_URL');
});

test('the project form only accepts repositories of the installation\'s provider', function () {
    Queue::fake();
    useProvider('gitlab');
    $admin = User::factory()->admin()->create(['git_token' => 'glpat-admin-000000000000']);
    Http::fake(['https://gitlab.com/api/v4/groups*' => Http::response([])]);

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->assertSee('https://gitlab.com/group/repo')
        ->set('name', 'Shop')
        ->set('repo_url', 'https://github.com/acme/shop')
        ->call('save')
        ->assertHasErrors(['repo_url'])
        ->set('repo_url', 'https://gitlab.com/acme/shop.git')
        ->call('save')
        ->assertHasNoErrors();
});

test('site provisioning hands the helper the git user name and the provider of the installation', function () {
    config([
        'studio.driver' => 'native',
        'studio.domain' => 'dev.example.test',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
    ]);
    useProvider('bitbucket');
    $log = tempnam(sys_get_temp_dir(), 'helper');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;

    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'ATATT-admin']);
    $project = Project::factory()->create(['slug' => 'shop', 'repo_url' => 'https://bitbucket.org/acme/shop.git', 'deploy_user_id' => $admin->id]);
    Http::fake([
        'https://api.bitbucket.org/2.0/user' => Http::response(['username' => 'andrea'], 200),
        'https://api.bitbucket.org/2.0/repositories/acme/shop/hooks' => Http::response(['uuid' => '{h}'], 201),
    ]);

    app(ProjectSites::class)->provision($project);

    $line = collect(file($log))->first(fn ($l) => str_contains($l, 'project-create'));
    $sent = json_decode(substr($line, strpos($line, '{')), true);

    expect($project->fresh()->site_status)->toBe(SiteStatus::Ready)
        ->and($project->fresh()->webhook_id)->toBe('{h}')
        ->and($sent['git_token'])->toBe('ATATT-admin')
        ->and($sent['git_username'])->toBe('x-bitbucket-api-token-auth')
        ->and($sent['git_provider'])->toBe('bitbucket');

    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);
});
