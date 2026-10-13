<?php

use App\Livewire\Studio\SidePane;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Ssh\Gateway;
use Livewire\Livewire;

test('the workspace terminal switches to the project\'s SSH hosts, after the password, with a gateway ticket', function () {
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $dev->id]);
    $mine = SshConnection::factory()->create(['user_id' => $dev->id, 'project_id' => $project->id, 'name' => 'Shop web']);
    $elsewhere = SshConnection::factory()->create(['user_id' => $dev->id, 'name' => 'Personal box']);

    $pane = Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'terminal')
        ->assertSee('Remote (SSH)')
        ->call('setTerminalMode', 'remote')
        ->assertSet('terminalMode', 'remote')
        ->assertSee('confirm your password');

    expect($pane->call('open', $mine->uuid)->effects['returns'][0] ?? null)->toBeNull();

    session()->put('auth.password_confirmed_at', time());

    $pane->call('setTab', 'terminal')->assertSee('Shop web')->assertDontSee('Personal box');
    $info = $pane->call('open', $mine->uuid)->effects['returns'][0];

    expect($info['address'])->toBe('deploy@web1.example.com')
        ->and(Gateway::redeem($info['ticket'])['host'] ?? null)->toBe('web1.example.com');

    $pane->call('trustHostKey', $mine->uuid, 'SHA256:'.str_repeat('A', 43));
    expect($mine->fresh()->host_key)->toBe('SHA256:'.str_repeat('A', 43));

    // a connection that is not the project's never opens from its workspace
    Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])->call('open', $elsewhere->uuid)->assertNotFound();
});

test('a client of the project has no remote terminal', function () {
    $project = Project::factory()->create();
    $client = User::factory()->create(['role' => 'client']);
    $project->syncMembers([$client->id => 'client']);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $client->id]);

    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTerminalMode', 'remote')
        ->assertSet('terminalMode', 'local');
});
