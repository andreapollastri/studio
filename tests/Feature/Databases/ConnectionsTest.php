<?php

use App\Livewire\Tools\Databases;
use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function dbConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('developers and administrators reach Databases after their password; others only with something shared', function () {
    $dev = User::factory()->create();
    $this->actingAs($dev)->get(route('databases'))->assertRedirect(route('password.confirm'));
    $this->actingAs($dev)->withSession(dbConfirmed())->get(route('databases'))->assertOk()->assertSee('New connection');
    $this->actingAs(User::factory()->admin()->create())->withSession(dbConfirmed())->get(route('databases'))->assertOk();

    $client = User::factory()->client()->create();
    $this->actingAs($client)->withSession(dbConfirmed())->get(route('databases'))->assertForbidden();
    $this->actingAs($client)->get(route('projects.index'))->assertDontSee(route('databases'));

    $project = Project::factory()->create();
    $project->members()->attach([$client->id => ['role' => 'client']]);
    DatabaseConnection::factory()->create(['name' => 'Shop reports', 'project_id' => $project->id, 'project_access' => ['client' => 'read']]);

    $this->actingAs($client)->withSession(dbConfirmed())->get(route('databases'))->assertOk()->assertSee('Shop reports')->assertDontSee('New connection');
});

test('a connection is saved with its password encrypted, never shown again', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Databases::class)
        ->call('newConnection')
        ->set('name', 'Shop')
        ->set('driver', 'pgsql')
        ->set('host', 'db.example.com')
        ->set('port', 5433)
        ->set('username', 'shop')
        ->set('password', 'S3cret-pw!')
        ->set('database', 'shop')
        ->set('environment', 'production')
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertSee('Shop')
        ->assertDontSee('S3cret-pw!');

    $connection = $user->databaseConnections()->sole();
    expect($connection->password)->toBe('S3cret-pw!')
        ->and(DB::table('database_connections')->value('password'))->not->toContain('S3cret')
        ->and($connection->address())->toBe('shop@db.example.com:5433/shop')
        ->and($connection->environment->isProduction())->toBeTrue();

    // editing without a new password keeps the old one
    Livewire::actingAs($user)->test(Databases::class)
        ->call('editConnection', $connection->uuid)
        ->assertSet('password', '')
        ->set('database', 'shop_v2')
        ->call('saveConnection')
        ->assertHasNoErrors();

    expect($connection->fresh()->password)->toBe('S3cret-pw!')->and($connection->fresh()->database)->toBe('shop_v2');
});

test('a TablePlus link with an SSH part fills the form and brings a new SSH connection', function () {
    $user = User::factory()->create();

    $page = Livewire::actingAs($user)->test(Databases::class)
        ->call('newConnection')
        ->set('link', 'mariadb+ssh://deploy:Ssh-Pass1@203.0.113.7/app:Db-Pass2@127.0.0.1/appdb?env=staging&name=App%20staging')
        ->call('applyLink')
        ->assertHasNoErrors()
        ->assertSet('driver', 'mariadb')
        ->assertSet('via', 'new')
        ->assertSet('ssh_host', '203.0.113.7')
        ->assertSet('ssh_username', 'deploy')
        ->assertSet('ssh_password', 'Ssh-Pass1')
        ->assertSet('host', '127.0.0.1')
        ->assertSet('username', 'app')
        ->assertSet('password', 'Db-Pass2')
        ->assertSet('database', 'appdb')
        ->assertSet('environment', 'staging')
        ->assertSet('name', 'App staging');

    $page->call('saveConnection')->assertHasNoErrors();

    $connection = $user->databaseConnections()->with('sshConnection')->sole();
    expect($connection->sshConnection)->not->toBeNull()
        ->and($connection->sshConnection->address())->toBe('deploy@203.0.113.7')
        ->and($connection->sshConnection->password)->toBe('Ssh-Pass1')
        ->and($connection->sshConnection->user_id)->toBe($user->id)
        ->and($connection->password)->toBe('Db-Pass2');

    // the same SSH host again: the SSH connection is reused, not doubled
    Livewire::actingAs($user)->test(Databases::class)
        ->call('newConnection')
        ->set('link', 'mysql+ssh://deploy@203.0.113.7/root:pw@127.0.0.1/other')
        ->call('applyLink')
        ->assertSet('via', 'ssh')
        ->assertSet('ssh', $connection->sshConnection->uuid)
        ->call('saveConnection')
        ->assertHasNoErrors();

    expect($user->sshConnections()->count())->toBe(1);
});

test('a .env fills the form too, and nonsense says what is expected', function () {
    Livewire::actingAs(User::factory()->create())->test(Databases::class)
        ->call('newConnection')
        ->set('link', "APP_NAME=Shop\nAPP_ENV=production\nDB_CONNECTION=pgsql\nDB_HOST=10.0.0.5\nDB_PORT=5432\nDB_DATABASE=shop\nDB_USERNAME=shop\nDB_PASSWORD=\"p#ss\"")
        ->call('applyLink')
        ->assertSet('driver', 'pgsql')
        ->assertSet('host', '10.0.0.5')
        ->assertSet('password', 'p#ss')
        ->assertSet('environment', 'production')
        ->set('link', 'not a link at all')
        ->call('applyLink')
        ->assertHasErrors('link');
});

test('a project connection reaches each role as shared, and every administrator', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    [$dev, $pm, $client, $outsider] = [User::factory()->create(), User::factory()->pm()->create(), User::factory()->client()->create(), User::factory()->create()];
    $project->members()->attach([$dev->id => ['role' => 'dev'], $pm->id => ['role' => 'pm'], $client->id => ['role' => 'client']]);

    $connection = DatabaseConnection::factory()->for($owner)->create(['project_id' => $project->id, 'project_access' => ['dev' => 'write', 'pm' => 'read']]);

    expect($connection->accessFor($owner))->toBe('write')
        ->and($connection->accessFor($dev))->toBe('write')
        ->and($connection->accessFor($pm))->toBe('read')
        ->and($connection->accessFor($client))->toBeNull()
        ->and($connection->accessFor($outsider))->toBeNull()
        ->and($connection->accessFor(User::factory()->admin()->create()))->toBe('write')
        ->and(DatabaseConnection::query()->visibleTo($pm)->pluck('id')->all())->toBe([$connection->id])
        ->and(DatabaseConnection::query()->visibleTo($client)->exists())->toBeFalse();

    // a personal connection is its owner's alone, even for an administrator
    $personal = DatabaseConnection::factory()->for($owner)->create();
    expect($personal->accessFor(User::factory()->admin()->create()))->toBeNull();

    // only the owner edits; the others may not
    Livewire::actingAs($dev)->test(Databases::class)->call('editConnection', $connection->uuid)->assertNotFound();
    Livewire::actingAs($dev)->test(Databases::class)->call('deleteConnection', $connection->uuid)->assertNotFound();

    // the link a member copies carries no password; the owner's may
    $memberLink = Livewire::actingAs($dev)->test(Databases::class)->instance()->copyLink($connection->uuid, true);
    $ownerLink = Livewire::actingAs($owner)->test(Databases::class)->instance()->copyLink($connection->uuid, true);
    expect($memberLink)->not->toContain('secret')->and($ownerLink)->toContain(':secret@');

    $this->actingAs($client)->withSession(dbConfirmed())->get(route('databases.client', $connection))->assertNotFound();
});

test('groups and stars are each person’s own, shared connections included', function () {
    $owner = User::factory()->create();
    $dev = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach([$dev->id => ['role' => 'dev']]);
    $connection = DatabaseConnection::factory()->for($owner)->create(['name' => 'Billing', 'project_id' => $project->id, 'project_access' => ['dev' => 'read']]);

    $page = Livewire::actingAs($dev)->test(Databases::class)
        ->call('newGroup')
        ->set('group_name', 'Clients')
        ->set('group_color', 'violet')
        ->call('saveGroup')
        ->assertHasNoErrors();

    $group = $dev->databaseGroups()->sole();
    $page->call('moveToGroup', $connection->uuid, $group->uuid)->call('toggleFavorite', $connection->uuid);

    expect($dev->databasePreferences()->sole())
        ->database_group_id->toBe($group->id)
        ->favorite->toBeTrue()
        ->and($owner->databasePreferences()->exists())->toBeFalse();

    $page->call('deleteGroup', $group->uuid);
    expect($dev->databasePreferences()->sole()->database_group_id)->toBeNull();
});

test('a SQLite file on this server is for administrators only', function () {
    Livewire::actingAs(User::factory()->create())->test(Databases::class)
        ->call('newConnection')
        ->set('name', 'local')
        ->set('driver', 'sqlite')
        ->set('via', 'local')
        ->set('database', '/var/lib/app.sqlite')
        ->call('saveConnection')
        ->assertHasErrors('via');

    Livewire::actingAs($admin = User::factory()->admin()->create())->test(Databases::class)
        ->call('newConnection')
        ->set('name', 'local')
        ->set('driver', 'sqlite')
        ->set('via', 'local')
        ->set('database', '/var/lib/app.sqlite')
        ->call('saveConnection')
        ->assertHasNoErrors();

    expect($admin->databaseConnections()->sole()->isLocalFile())->toBeTrue();
});

test('a tunnel goes only through an SSH connection the person may use', function () {
    $user = User::factory()->create();
    $foreign = SshConnection::factory()->create();

    Livewire::actingAs($user)->test(Databases::class)
        ->call('newConnection')
        ->set('name', 'via someone else')
        ->set('via', 'ssh')
        ->set('ssh', $foreign->uuid)
        ->call('saveConnection')
        ->assertHasErrors('ssh');

    $mine = SshConnection::factory()->for($user)->create(['host_key' => 'SHA256:'.str_repeat('a', 43)]);

    Livewire::actingAs($user)->test(Databases::class)
        ->call('newConnection')
        ->set('name', 'via mine')
        ->set('via', 'ssh')
        ->set('ssh', $mine->uuid)
        ->call('saveConnection')
        ->assertHasNoErrors();

    expect($user->databaseConnections()->sole()->ssh_connection_id)->toBe($mine->id);
});
