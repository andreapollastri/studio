<?php

use App\Console\Commands\MoveDomain;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('a host moves only when it is under the old domain', function () {
    expect(MoveDomain::rehost('shop.dev.example.test', 'dev.example.test', 'studio.agency.test'))->toBe('shop.studio.agency.test')
        ->and(MoveDomain::rehost('dev.example.test', 'dev.example.test', 'agency.test'))->toBe('agency.test')
        ->and(MoveDomain::rehost('shop.mydev.example.test', 'dev.example.test', 'agency.test'))->toBeNull()
        ->and(MoveDomain::rehost('shop.dev.example.test.org', 'dev.example.test', 'agency.test'))->toBeNull()
        ->and(MoveDomain::rehostUrl('https://andrea-shop.dev.example.test/path?x=dev.example.test', 'dev.example.test', 'agency.test'))
        ->toBe('https://andrea-shop.agency.test/path?x=dev.example.test');
});

test('the previews, the sandboxes and a site address under the old domain move to the new one', function () {
    $user = User::factory()->create(['handle' => 'andrea']);
    $project = Project::factory()->create(['slug' => 'shop', 'settings' => ['site_url' => 'https://shop.dev.example.test', 'agent' => ['browser' => true]]]);
    $other = Project::factory()->create(['slug' => 'blog', 'settings' => ['site_url' => 'https://blog.client.test']]);
    $workspace = Workspace::factory()->create(['project_id' => $project->id, 'user_id' => $user->id, 'app_url' => 'https://andrea-shop.dev.example.test']);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id,
        'sandbox_host' => 'andrea-shop--c7.dev.example.test',
        'sandbox' => ['status' => 'ready', 'url' => 'https://andrea-shop--c7.dev.example.test', 'branch' => 'sandbox/andrea-7'],
    ]);

    $this->artisan('studio:move-domain', ['from' => 'dev.example.test', 'to' => 'agency.test'])
        ->expectsOutputToContain('3 host(s) moved')
        ->assertSuccessful();

    expect($workspace->fresh()->app_url)->toBe('https://andrea-shop.agency.test')
        ->and($conversation->fresh()->sandbox_host)->toBe('andrea-shop--c7.agency.test')
        ->and($conversation->fresh()->sandbox)->toMatchArray(['url' => 'https://andrea-shop--c7.agency.test', 'branch' => 'sandbox/andrea-7'])
        ->and($project->fresh()->settings)->toBe(['site_url' => 'https://shop.agency.test', 'agent' => ['browser' => true]])
        ->and($other->fresh()->settings['site_url'])->toBe('https://blog.client.test');
});

test('the push webhooks are registered again at the new address, once Studio runs on it', function () {
    $admin = User::factory()->admin()->withGit('github_pat_admin', attributes: ['login' => 'andrea'])->create(['handle' => 'andrea']);
    Project::factory()->create(['slug' => 'shop', 'repo_url' => 'https://github.com/agency/shop.git', 'deploy_user_id' => $admin->id, 'webhook_id' => '111', 'webhook_secret' => 'secret']);

    config(['studio.domain' => 'dev.example.test']);
    $this->artisan('studio:move-domain', ['from' => 'dev.example.test', 'to' => 'agency.test', '--webhooks' => true])->assertFailed();

    config(['studio.domain' => 'agency.test', 'app.url' => 'https://studio.agency.test']);
    url()->forceRootUrl('https://studio.agency.test');
    Http::fake([
        'https://api.github.com/repos/agency/shop/hooks/111' => Http::response('', 204),
        'https://api.github.com/repos/agency/shop/hooks' => Http::response(['id' => 222], 201),
    ]);

    $this->artisan('studio:move-domain', ['from' => 'dev.example.test', 'to' => 'agency.test', '--webhooks' => true])->assertSuccessful();

    expect(Project::query()->where('slug', 'shop')->value('webhook_id'))->toBe('222');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/111'));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request['config']['url'], '://studio.agency.test/api/git/webhook/shop'));
});

test('two different domains are needed', function () {
    $this->artisan('studio:move-domain', ['from' => 'dev.example.test', 'to' => 'dev.example.test'])->assertFailed();
    $this->artisan('studio:move-domain', ['from' => 'dev.example.test', 'to' => 'not a domain'])->assertFailed();
});
