<?php

use App\Accounts\Accounts;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Projects\Index;
use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectPreference;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** A developer with a personal and a work GitHub account, a client's GitLab, two Claude accounts, and three projects. */
function groupsSetup(): array
{
    $user = User::factory()
        ->withGit('ghp_personal', attributes: ['name' => 'Personal', 'git_email' => 'ada@personal.test'])
        ->withClaude('subscription')
        ->create(['handle' => 'ada']);
    $work = ConnectorAccount::query()->create(['user_id' => $user->id, 'connector' => 'github', 'name' => 'Work', 'login' => 'ada-acme', 'host' => 'github.com', 'git_email' => 'ada@acme.test', 'credentials' => ['token' => 'ghp_work'], 'status' => 'ok']);
    $client = ConnectorAccount::query()->create(['user_id' => $user->id, 'connector' => 'gitlab', 'name' => 'Client', 'login' => 'ada', 'host' => 'gitlab.client.test', 'git_email' => 'ada@client.test', 'credentials' => ['token' => 'glpat-c', 'url' => 'https://gitlab.client.test'], 'status' => 'ok']);
    $team = ClaudeAccount::factory()->for($user)->subscription('.claude-team')->create(['name' => 'Acme Team']);

    $shop = Project::factory()->create(['name' => 'Acme Shop', 'repo_url' => 'https://github.com/acme/shop.git']);
    $portal = Project::factory()->create(['name' => 'Client Portal', 'repo_url' => 'https://gitlab.client.test/client/portal.git']);
    $blog = Project::factory()->create(['name' => 'My Blog', 'repo_url' => 'https://github.com/ada/blog.git']);
    foreach ([$shop, $portal, $blog] as $project) {
        $project->members()->attach($user);
    }

    return compact('user', 'work', 'client', 'team', 'shop', 'portal', 'blog');
}

test('a person makes groups of their own, moves projects in, and each card says what it runs on and why', function () {
    ['user' => $user, 'work' => $work, 'team' => $team, 'shop' => $shop, 'portal' => $portal] = groupsSetup();

    Livewire::actingAs($user)->test(Index::class)
        ->call('newGroup')
        ->set('group_name', 'Work')
        ->set('group_claude', $team->uuid)
        ->set('group_git', $work->uuid)
        ->call('saveGroup')
        ->assertHasNoErrors();

    $group = ProjectGroup::query()->sole();

    Livewire::actingAs($user)->test(Index::class)
        ->call('moveProject', $shop->uuid, $group->uuid)
        ->call('moveProject', $portal->uuid, $group->uuid)
        ->assertSee(['Work', 'Acme Team', 'Other projects', 'My Blog'])
        // the shop pushes as the work account; the portal stays on the client's GitLab, the only account there
        ->assertSee(['ada@acme.test', 'ada@client.test', 'ada@personal.test']);

    expect(Accounts::git($user, $shop)->account->id)->toBe($work->id)
        ->and(Accounts::git($user, $portal)->account->host)->toBe('gitlab.client.test')
        ->and(Accounts::claude($user, $portal)->account->id)->toBe($team->id)
        ->and(Accounts::claude($user, $portal)->reason())->toBe('from the group Work');

    // groups are the person's own: someone else sees none of them, and cannot touch them
    $other = User::factory()->create();
    $shop->members()->attach($other);
    Livewire::actingAs($other)->test(Index::class)->assertDontSee('Acme Team')->call('editGroup', $group->uuid)->assertNotFound();
});

test('a project picks its own accounts: only the git accounts on its host are offered, and a running workspace restarts only when its account moves', function () {
    Queue::fake();
    ['user' => $user, 'work' => $work, 'client' => $client, 'team' => $team, 'shop' => $shop] = groupsSetup();
    $personal = $user->gitAccounts()->where('name', 'Personal')->sole();
    Workspace::factory()->running()->create(['project_id' => $shop->id, 'user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/x', 'git_account_id' => $personal->id]);

    $page = Livewire::actingAs($user)->test(Index::class)
        ->call('editAccounts', $shop->uuid)
        ->assertSee('Automatic: Personal')
        ->set('project_git', $client->uuid)
        ->call('saveAccounts')
        ->assertHasErrors('project_git');

    // the Claude account alone: no restart
    $page->set('project_git', '')->set('project_claude', $team->uuid)->call('saveAccounts')->assertHasNoErrors();
    Queue::assertNotPushed(SyncWorkspaceCredentials::class);

    // the git account: the workspace of that project, and only it
    $page->call('editAccounts', $shop->uuid)->set('project_git', $work->uuid)->call('saveAccounts')->assertHasNoErrors();
    Queue::assertPushed(SyncWorkspaceCredentials::class, fn (SyncWorkspaceCredentials $job) => $job->projectIds === [$shop->id] && ! $job->force);

    expect(ProjectPreference::query()->sole())->claude_account_id->toBe($team->id)->git_account_id->toBe($work->id);
});

test('deleting a group, or an account, sends its projects back to the defaults', function () {
    ['user' => $user, 'work' => $work, 'team' => $team, 'shop' => $shop] = groupsSetup();
    $group = ProjectGroup::query()->create(['user_id' => $user->id, 'name' => 'Work', 'claude_account_id' => $team->id, 'git_account_id' => $work->id]);
    Accounts::choose($user, $shop, ['project_group_id' => $group->id]);

    $team->delete();
    expect(Accounts::claude($user, $shop)->account->name)->toBe('Claude subscription')
        ->and(Accounts::claude($user, $shop)->reason())->toBe('your default');

    Livewire::actingAs($user)->test(Index::class)->call('deleteGroup', $group->uuid)->assertSee('All projects');

    expect(ProjectGroup::query()->count())->toBe(0)
        ->and(ProjectPreference::query()->sole()->project_group_id)->toBeNull()
        ->and(Accounts::git($user, $shop)->account->name)->toBe('Personal');
});

test('groups keep the order the person gives them', function () {
    ['user' => $user] = groupsSetup();
    foreach (['Personal', 'Open source', 'Clients'] as $position => $name) {
        ProjectGroup::query()->create(['user_id' => $user->id, 'name' => $name, 'position' => $position + 1]);
    }

    Livewire::actingAs($user)->test(Index::class)->call('moveGroup', ProjectGroup::query()->where('name', 'Clients')->value('uuid'), -1);

    expect($user->projectGroups()->pluck('name')->all())->toBe(['Personal', 'Clients', 'Open source']);
});
