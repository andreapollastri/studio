<?php

use App\Livewire\Studio\SidePane;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Ssh\Gateway;
use Livewire\Livewire;

test('the Terminal tab mounts a shell and stays mounted; a shortcut is typed into it', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertSet('shellMounted', false)
        ->call('setTab', 'terminal')
        ->assertSet('shellMounted', true)
        ->assertSee('x-data="workspaceShell"', false)
        ->assertSee('Backlog')
        ->assertSee('Optimize Clear')
        ->assertDontSee('Git status')
        ->assertSee("type('php artisan larapilot:spec-list')", false)
        ->assertSee('prompts, passwords and Ctrl-C work as over SSH')
        ->call('setTab', 'files')
        ->assertSet('shellMounted', true);
});

test('the shell ticket is redeemed once by the gateway for the workspace\'s bridge, with the sandbox', function () {
    config(['app.url' => 'https://studio.example.com']);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    $info = Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace, 'sandbox' => 'c12'])
        ->call('shellTicket')
        ->effects['returns'][0];

    expect($info['url'])->toBe('wss://studio.example.com/ssh/ws');

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertExactJson(['kind' => 'workspace-shell', 'user_id' => $user->id, 'bridge_url' => 'http://bridge.test:4455', 'bridge_token' => $workspace->bridge_token, 'sandbox' => 'c12']);

    // spent
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
});

test('no shell for a stopped workspace, for someone else\'s, or once the person lost the project', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    $ticket = Gateway::issueShell($workspace, $user);
    $workspace->forceFill(['status' => 'stopped'])->save();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $workspace])->call('shellTicket')->assertReturned(null);

    $running = Workspace::factory()->running()->create(['user_id' => $user->id, 'callback_token' => 'other-token', 'callback_token_hash' => hash('sha256', 'other-token')]);
    $stranger = User::factory()->create();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => Gateway::issueShell($running, $stranger)], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
});

test('a client has no terminal', function () {
    $client = User::factory()->client()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $client->id]);

    Livewire::actingAs($client)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertDontSee('Terminal')
        ->assertDontSee('Changes')
        ->assertSee('Preview')
        ->call('setTab', 'changes')
        ->assertSet('tab', 'preview')
        ->call('shellTicket')
        ->assertForbidden();
});

test('Develop opens a shell on the project\'s own site for its developers and admins, after the password', function () {
    config(['studio.driver' => 'native', 'app.url' => 'https://studio.example.com']);
    $project = Project::factory()->online()->create(['slug' => 'shop-acme', 'php_version' => '8.4']);
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $dev->id, 'project_id' => $project->id]);

    $pane = Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'terminal')
        ->assertSee('Develop')
        ->call('setTerminalMode', 'develop')
        ->assertSet('terminalMode', 'develop')
        ->assertSet('developMounted', true)
        ->assertSee('confirm your password to open a shell on the site');

    // no ticket before the password
    $pane->call('developTicket')->assertReturned(null);

    session()->put('auth.password_confirmed_at', time());
    $info = Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTerminalMode', 'develop')
        ->assertSee('as its user prj-shop-acme')
        ->assertSee("workspaceShell('developTicket')", false)
        ->call('developTicket')
        ->effects['returns'][0];

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertExactJson(['kind' => 'site-shell', 'user_id' => $dev->id, 'key' => 'shop-acme', 'php' => '8.4']);
});

test('no Develop for a PM, for a project without a site, or outside a server Studio manages', function () {
    config(['studio.driver' => 'native']);
    $project = Project::factory()->online()->create();
    $pm = User::factory()->create();
    $project->syncMembers([$pm->id => 'pm']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $pm->id, 'project_id' => $project->id]);

    Livewire::actingAs($pm)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'terminal')
        ->assertDontSee('Develop')
        ->call('setTerminalMode', 'develop')
        ->assertSet('terminalMode', 'local');
    $this->postJson(route('api.ssh.redeem'), ['ticket' => Gateway::issueSiteShell($project, $pm)], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();

    $admin = User::factory()->admin()->create();
    expect(Gateway::canDevelop($admin, $project))->toBeTrue()
        ->and(Gateway::canDevelop($admin, Project::factory()->create()))->toBeFalse();
    config(['studio.driver' => 'local']);
    expect(Gateway::canDevelop($admin, $project))->toBeFalse();
});
