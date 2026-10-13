<?php

use App\Livewire\Admin\System;
use App\Livewire\Ssh\Terminal;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshKey;
use App\Models\User;
use App\Ssh\Gateway;
use Livewire\Livewire;

function redeemTicket(string $ticket)
{
    return test()->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], ['Authorization' => 'Bearer '.Gateway::token()]);
}

test('administrators create the installation\'s keys in System, the others may not', function () {
    $admin = User::factory()->admin()->create(['handle' => 'ada']);

    Livewire::actingAs($admin)
        ->test(System::class)
        ->assertSee('Keys')
        ->call('newKey')
        ->set('key_name', 'deploy')
        ->set('key_type', 'ed25519')
        ->call('saveKey')
        ->assertHasNoErrors()
        ->assertSee('ssh-ed25519 AAAA')
        ->call('newKey')
        ->set('key_name', 'deploy')
        ->call('saveKey')
        ->assertHasErrors(['key_name' => 'unique']);

    $key = SshKey::query()->system()->sole();
    expect($key->public_key)->toEndWith(' studio@localhost')
        ->and($admin->sshKeys()->count())->toBe(0);

    // A personal key may have the same name as a system key.
    SshKey::factory()->create(['user_id' => $admin->id, 'name' => 'deploy']);

    $dev = User::factory()->create();
    Livewire::actingAs($dev)->test(System::class)->assertForbidden();
});

test('a system key is shared with projects, with their developers or with administrators only', function () {
    $admin = User::factory()->admin()->create();
    [$shop, $blog, $wiki] = [Project::factory()->create(['name' => 'Shop']), Project::factory()->create(['name' => 'Blog']), Project::factory()->create(['name' => 'Wiki'])];
    $key = SshKey::factory()->system()->create(['name' => 'deploy']);
    $shop->sshKeys()->attach($key, ['developers' => false]);

    Livewire::actingAs($admin)->test(System::class)
        ->call('editSharing', $key->uuid)
        ->assertSet('share', [$blog->uuid => 'off', $shop->uuid => 'admins', $wiki->uuid => 'off'])
        ->set("share.{$blog->uuid}", 'developers')
        ->set("share.{$shop->uuid}", 'off')
        ->set("share.{$wiki->uuid}", 'admins')
        ->call('saveSharing')
        ->assertSee('Blog')
        ->assertSee('Wiki');

    expect($key->projects()->orderBy('name')->get()->map(fn (Project $p) => [$p->name, (bool) $p->pivot->developers])->all())
        ->toBe([['Blog', true], ['Wiki', false]]);
});

test('developers use a system key only on the hosts of a project it is shared with, with them', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    $other = Project::factory()->create();
    $dev = User::factory()->create();
    $project->members()->attach($dev);
    $other->members()->attach($dev);
    $owner = User::factory()->create();
    $project->members()->attach($owner);

    $forDevs = SshKey::factory()->system()->create(['name' => 'shop deploy']);
    $forAdmins = SshKey::factory()->system()->create(['name' => 'root key']);
    $project->sshKeys()->attach($forDevs, ['developers' => true]);
    $project->sshKeys()->attach($forAdmins, ['developers' => false]);

    $shared = SshConnection::factory()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'web']);

    // The SSH page shows the developer the key shared with them, and only that one.
    Livewire::actingAs($dev)->test(Terminal::class)
        ->assertSee('shop deploy')
        ->assertDontSee('root key');

    // Not for a key shared with the administrators only, nor on a personal or another project's connection.
    Livewire::actingAs($dev)->test(Terminal::class)
        ->call('editCredential', $shared->uuid)
        ->set('credential_auth', 'key')
        ->set('credential_key', $forAdmins->uuid)
        ->call('saveCredential')
        ->assertHasErrors('credential_key')
        ->set('credential_key', $forDevs->uuid)
        ->call('saveCredential')
        ->assertHasNoErrors();

    Livewire::actingAs($dev)->test(Terminal::class)
        ->call('newConnection')
        ->set('connection_name', 'box')
        ->set('connection_host', 'box.example.com')
        ->set('connection_username', 'deploy')
        ->set('connection_auth', 'key')
        ->set('connection_key', $forDevs->uuid)
        ->call('saveConnection')
        ->assertHasErrors('connection_key')
        ->set('connection_project', $other->uuid)
        ->call('saveConnection')
        ->assertHasErrors('connection_key')
        ->set('connection_project', $project->uuid)
        ->call('saveConnection')
        ->assertHasNoErrors();

    // The gateway gets the system key; the private half never reaches the page.
    $info = Livewire::actingAs($dev)->test(Terminal::class)->call('open', $shared->uuid)->effects['returns'][0];
    redeemTicket($info['ticket'])->assertOk()->assertJson(['user_id' => $dev->id, 'auth' => 'key', 'private_key' => $forDevs->private_key]);

    // Taking the sharing back takes the key away, from saved credentials and connections too.
    $own = $dev->sshConnections()->where('name', 'box')->sole();
    $ticket = Gateway::issue($shared, $dev);
    $project->sshKeys()->updateExistingPivot($forDevs->id, ['developers' => false]);

    redeemTicket($ticket)->assertNotFound();
    expect($shared->canConnect($dev))->toBeFalse()
        ->and($own->canConnect($dev))->toBeFalse();
    Livewire::actingAs($dev)->test(Terminal::class)->assertDontSee('shop deploy');
});

test('administrators use every system key, also on personal connections', function () {
    $admin = User::factory()->admin()->create();
    $key = SshKey::factory()->system()->create(['name' => 'root key']);

    Livewire::actingAs($admin)->test(Terminal::class)
        ->assertSee('root key')
        ->call('newConnection')
        ->set('connection_name', 'box')
        ->set('connection_host', 'box.example.com')
        ->set('connection_username', 'root')
        ->set('connection_key', $key->uuid)
        ->call('saveConnection')
        ->assertHasNoErrors();

    $connection = $admin->sshConnections()->sole();
    expect($connection->ssh_key_id)->toBe($key->id)
        ->and($connection->canConnect($admin))->toBeTrue();

    // The SSH page shows a system key, it does not remove it.
    Livewire::actingAs($admin)->test(Terminal::class)->call('deleteKey', $key->uuid)->assertNotFound();
    expect($key->fresh())->not->toBeNull();

    Livewire::actingAs($admin)->test(System::class)->call('deleteKey', $key->uuid);
    expect($key->fresh())->toBeNull()
        ->and($connection->fresh()->canConnect($admin))->toBeFalse();
});
