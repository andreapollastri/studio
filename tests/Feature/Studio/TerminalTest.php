<?php

use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a shortcut runs in the workspace through the bridge and the pane follows it to the end', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    Http::fake([
        'http://bridge.test:4455/run' => Http::response(['id' => 'run-1', 'command' => 'php artisan larapilot:doctor --human', 'status' => 'running', 'output' => ''], 202),
        'http://bridge.test:4455/run/run-1' => Http::response(['id' => 'run-1', 'command' => 'php artisan larapilot:doctor --human', 'status' => 'done', 'exit_code' => 0, 'output' => "Larapilot doctor\nhealthy: yes\n"]),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'terminal')
        ->assertSee('Doctor')
        ->call('runCommand', 'php artisan larapilot:doctor --human')
        ->assertSet('terminalError', null)
        ->assertSee('$ php artisan larapilot:doctor --human');

    expect($component->get('run')['status'])->toBe('running');

    $component->call('refreshRun')->assertSee('healthy: yes')->assertSee('exit 0');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/run' && $request['command'] === 'php artisan larapilot:doctor --human');
});

test('a command the bridge refuses is shown as an error', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    Http::fake(['http://bridge.test:4455/run' => Http::response(['error' => 'rm is not in the list.'], 422)]);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'terminal')
        ->set('command', 'rm -rf /')
        ->call('runCommand')
        ->assertSee('rm is not in the list.');
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
        ->assertSet('tab', 'preview');
});
