<?php

use App\Livewire\Studio\SidePane;
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
        ->assertSee('Doctor')
        ->assertSee("type('php artisan larapilot:doctor --human')", false)
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
