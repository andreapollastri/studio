<?php

use App\Enums\UserRole;
use App\Livewire\Projects\Repository;
use App\Models\ConnectorAccount;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\SshConnection;
use App\Models\User;
use App\Models\VaultItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a role is the project\'s: a developer here may be the PM there and a client elsewhere', function () {
    $marco = User::factory()->create();
    [$api, $crm, $site] = Project::factory()->count(3)->create()->all();
    $api->members()->attach($marco->id, ['role' => 'dev']);
    $crm->members()->attach($marco->id, ['role' => 'pm']);
    $site->members()->attach($marco->id, ['role' => 'client']);

    expect($marco->roleIn($api))->toBe(UserRole::Dev)
        ->and($marco->roleIn($crm))->toBe(UserRole::Pm)
        ->and($marco->roleIn($site))->toBe(UserRole::Client)
        ->and($marco->can('chat', $api))->toBeTrue()
        ->and($marco->can('chat', $crm))->toBeTrue()
        ->and($marco->can('chat', $site))->toBeFalse()
        ->and($marco->projectIdsAs(UserRole::Dev))->toBe([$api->id]);

    // a project's MCP server for developers reaches Marco's chats on the API only
    $server = ProjectMcpServer::factory()->create(['project_id' => $api->id, 'roles' => ['dev']]);
    $other = ProjectMcpServer::factory()->create(['project_id' => $crm->id, 'roles' => ['dev']]);
    expect($server->allows($marco))->toBeTrue()->and($other->allows($marco))->toBeFalse();

    // a password shared with the developers of the CRM is not Marco's, the PM there
    $owner = User::factory()->admin()->create();
    VaultItem::factory()->create(['user_id' => $owner->id, 'project_id' => $crm->id, 'project_roles' => ['dev'], 'title' => 'CRM database']);
    VaultItem::factory()->create(['user_id' => $owner->id, 'project_id' => $crm->id, 'project_roles' => ['pm'], 'title' => 'CRM admin panel']);
    expect(VaultItem::query()->visibleTo($marco)->pluck('title')->all())->toBe(['CRM admin panel']);
});

test('the project tabs show to who has something there: settings for administrators, the repository for who chats', function () {
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $client = User::factory()->client()->create();
    $project->members()->attach([$dev->id => ['role' => 'dev'], $client->id => ['role' => 'client']]);

    $this->actingAs($dev)->get(route('projects.access', $project))->assertOk()
        ->assertSee(route('projects.repository', $project))
        ->assertDontSee(route('projects.settings', $project))
        ->assertDontSee(route('projects.infrastructure', $project));

    $this->actingAs($client)->get(route('projects.access', $project))->assertOk()
        ->assertDontSee(route('projects.repository', $project));
    $this->actingAs($client)->get(route('projects.repository', $project))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get(route('projects.access', $project))->assertOk()
        ->assertSee(route('projects.settings', $project))
        ->assertSee(route('projects.infrastructure', $project));

    $this->actingAs(User::factory()->create())->get(route('projects.access', $project))->assertForbidden();
});

test('the Access tab gathers the passwords, SSH connections and folders the role opens', function () {
    $owner = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $pm = User::factory()->pm()->create();
    $project->members()->attach([$dev->id => ['role' => 'dev'], $pm->id => ['role' => 'pm']]);

    VaultItem::factory()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'project_roles' => ['dev', 'pm'], 'title' => 'Staging admin']);
    VaultItem::factory()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'project_roles' => ['dev'], 'title' => 'Production database']);
    SshConnection::factory()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Production server']);
    DriveFolder::query()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'project_access' => ['dev' => 'write', 'pm' => 'read'], 'name' => 'Brand assets']);

    $this->actingAs($dev)->get(route('projects.access', $project))->assertOk()
        ->assertSee(['Staging admin', 'Production database', 'Production server', 'Brand assets', 'view and edit']);

    $this->actingAs($pm)->get(route('projects.access', $project))->assertOk()
        ->assertSee(['Staging admin', 'Brand assets'])
        ->assertDontSee(['Production database', 'Production server', 'view and edit']);
});

test('the Repository tab opens the project\'s repository with the person\'s own account on its host', function () {
    $project = Project::factory()->create(['repo_url' => 'https://github.com/acme/shop.git']);
    $dev = User::factory()->create();
    $project->members()->attach($dev->id, ['role' => 'dev']);

    // without an account on github.com: where to add one
    $this->actingAs($dev)->get(route('projects.repository', $project))->assertOk()->assertSee('Add a git account');

    ConnectorAccount::factory()->of('gitlab', ['token' => 'glpat-x', 'url' => 'https://gitlab.com'])->create(['user_id' => $dev->id, 'name' => 'Client GitLab', 'host' => 'gitlab.com']);
    $mine = ConnectorAccount::factory()->of('github', ['token' => 'ghp_mine'])->create(['user_id' => $dev->id, 'name' => 'Work', 'host' => 'github.com', 'login' => 'luca', 'git_name' => 'Luca', 'git_email' => 'luca@agency.test']);

    Http::fake([
        'api.github.com/repos/acme/shop/pulls*' => Http::response([[
            'number' => 7, 'title' => 'Date filter on orders', 'state' => 'open', 'draft' => false, 'html_url' => 'https://github.com/acme/shop/pull/7',
            'user' => ['login' => 'luca'], 'head' => ['ref' => 'feature/date-filter'], 'base' => ['ref' => 'develop'], 'merged_at' => null, 'updated_at' => '2026-10-12T10:00:00Z',
        ]]),
        'api.github.com/repos/acme/shop' => Http::response(['full_name' => 'acme/shop', 'default_branch' => 'develop', 'html_url' => 'https://github.com/acme/shop']),
        'api.github.com/*' => Http::response([]),
    ]);

    Livewire::actingAs($dev)->test(Repository::class, ['project' => $project])
        ->assertSet('account', $mine->uuid)
        ->assertSet('tab', 'repo-pulls')
        ->assertSet('repoScope.repo', 'acme/shop')
        ->assertSee('acme/shop')
        ->assertSee('as Luca <luca@agency.test>')
        ->assertSee('Date filter on orders')
        ->assertSee(['Issues', 'Branches', 'Actions', 'Releases'])
        ->call('select', 'branches')
        ->assertSet('tab', 'branches')
        ->assertSet('scope.repo', 'acme/shop')
        // the dashboard's own tabs are not the project's
        ->call('select', 'notifications')
        ->assertSet('tab', 'branches');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.github.com/repos/acme/shop/pulls') && $r->hasHeader('Authorization', 'Bearer ghp_mine'));
});
