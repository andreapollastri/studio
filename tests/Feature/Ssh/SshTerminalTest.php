<?php

use App\Livewire\Ssh\Terminal;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshKey;
use App\Models\User;
use App\Ssh\Gateway;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

test('administrators and developers get the SSH page after confirming their password, the others do not', function () {
    $dev = User::factory()->create();

    $this->actingAs($dev)->get(route('ssh'))->assertRedirect(route('password.confirm'));
    $this->actingAs($dev)->withSession(['auth.password_confirmed_at' => time()])->get(route('ssh'))->assertOk()->assertSee('SSH terminal');
    $this->actingAs(User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time()])->get(route('ssh'))->assertOk();

    foreach ([User::factory()->pm()->create(), User::factory()->client()->create()] as $other) {
        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->get(route('ssh'))->assertForbidden();
        $this->actingAs($other)->get(route('projects.index'))->assertDontSee('SSH terminal');
    }
});

test('a key is generated, its public half shown, its private half stored encrypted', function () {
    $user = User::factory()->create(['handle' => 'ada']);

    Livewire::actingAs($user)
        ->test(Terminal::class)
        ->call('newKey')
        ->set('key_name', 'deploy')
        ->set('key_type', 'ed25519')
        ->call('saveKey')
        ->assertHasNoErrors()
        ->assertSee('ssh-ed25519 AAAA')
        ->assertSee('authorized_keys');

    $key = $user->sshKeys()->sole();
    $raw = DB::table('ssh_keys')->where('id', $key->id)->value('private_key');

    expect($key->type)->toBe('ed25519')
        ->and($key->public_key)->toStartWith('ssh-ed25519 ')->toEndWith(' ada@localhost')
        ->and($key->fingerprint)->toMatch('/^SHA256:[A-Za-z0-9+\/]{43}$/')
        ->and($key->private_key)->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($raw)->not->toContain('PRIVATE KEY');

    Livewire::actingAs($user)->test(Terminal::class)
        ->call('newKey')->set('key_name', 'old box')->set('key_type', 'rsa')->call('saveKey')->assertHasNoErrors();

    expect($user->sshKeys()->where('name', 'old box')->sole())->type->toBe('rsa')->bits->toBe(4096);
});

test('a key with a passphrase is imported without it; a wrong passphrase or garbage is refused', function () {
    $user = User::factory()->create();
    $protected = EC::createKey('Ed25519')->withPassword('correct horse');
    $pem = $protected->toString('OpenSSH');

    $component = Livewire::actingAs($user)->test(Terminal::class)
        ->call('newKey')
        ->set('key_name', 'mine')
        ->set('key_mode', 'import')
        ->set('key_private', 'not a key')
        ->call('saveKey')
        ->assertHasErrors('key_private')
        ->set('key_private', $pem)
        ->set('key_passphrase', 'wrong')
        ->call('saveKey')
        ->assertHasErrors('key_private')
        ->set('key_passphrase', 'correct horse')
        ->call('saveKey')
        ->assertHasNoErrors();

    $key = $user->sshKeys()->sole();
    $loaded = PublicKeyLoader::loadPrivateKey($key->private_key);

    expect($key->fingerprint)->toBe('SHA256:'.$protected->getPublicKey()->getFingerprint('sha256'))
        ->and($loaded->getPublicKey()->toString('OpenSSH'))->toBe($protected->getPublicKey()->toString('OpenSSH'))
        ->and($component->get('key_passphrase'))->toBe('');
});

test('connections are saved with a key or a password, favourites first, and stay their owner\'s', function () {
    $user = User::factory()->create();
    $key = SshKey::factory()->create(['user_id' => $user->id, 'name' => 'laptop']);
    $foreignKey = SshKey::factory()->create();

    Livewire::actingAs($user)->test(Terminal::class)
        ->call('newConnection')
        ->assertSet('connection_auth', 'key')
        ->set('connection_name', 'web')
        ->set('connection_host', 'root@web1.example.com')
        ->set('connection_username', 'deploy')
        ->set('connection_key', $foreignKey->uuid)
        ->call('saveConnection')
        ->assertHasErrors(['connection_host', 'connection_key'])
        ->set('connection_host', 'web1.example.com')
        ->set('connection_port', 2222)
        ->set('connection_key', $key->uuid)
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->call('newConnection')
        ->set('connection_name', 'db')
        ->set('connection_host', '10.0.0.5')
        ->set('connection_username', 'admin')
        ->set('connection_auth', 'password')
        ->call('saveConnection')
        ->assertHasErrors('connection_password')
        ->set('connection_password', 's3cret')
        ->set('connection_favorite', true)
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['db', 'admin@10.0.0.5', 'web', 'deploy@web1.example.com:2222'])
        ->assertDontSee('s3cret');

    $db = $user->sshConnections()->where('name', 'db')->sole();
    expect($db->password)->toBe('s3cret')
        ->and(DB::table('ssh_connections')->where('id', $db->id)->value('password'))->not->toContain('s3cret');

    // Editing without a new password keeps the saved one.
    Livewire::actingAs($user)->test(Terminal::class)
        ->call('editConnection', $db->uuid)
        ->set('connection_username', 'root')
        ->call('saveConnection')
        ->assertHasNoErrors();
    expect($db->fresh())->username->toBe('root')->password->toBe('s3cret');

    $stranger = SshConnection::factory()->create();
    Livewire::actingAs($user)->test(Terminal::class)->call('editConnection', $stranger->uuid)->assertNotFound();
    Livewire::actingAs($user)->test(Terminal::class)->call('open', $stranger->uuid)->assertNotFound();
});

test('a new address forgets the accepted host key; trusting one does not overwrite it', function () {
    $user = User::factory()->create();
    $connection = SshConnection::factory()->create(['user_id' => $user->id]);
    $fingerprint = 'SHA256:'.str_repeat('A', 43);

    $component = Livewire::actingAs($user)->test(Terminal::class)
        ->call('trustHostKey', $connection->uuid, 'not a fingerprint')
        ->call('trustHostKey', $connection->uuid, $fingerprint);
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('trustHostKey', $connection->uuid, 'SHA256:'.str_repeat('B', 43));
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('editConnection', $connection->uuid)->set('connection_username', 'other')->call('saveConnection');
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('editConnection', $connection->uuid)->set('connection_host', 'web2.example.com')->call('saveConnection');
    expect($connection->fresh()->host_key)->toBeNull();
});

test('opening a connection gives a one-time ticket the gateway redeems for the key', function () {
    config(['app.url' => 'https://studio.example.com']);
    $user = User::factory()->create();
    $key = SshKey::factory()->create(['user_id' => $user->id]);
    $connection = SshConnection::factory()->withKey($key)->create(['user_id' => $user->id, 'host_key' => 'SHA256:known']);

    $info = Livewire::actingAs($user)->test(Terminal::class)->call('open', $connection->uuid)->effects['returns'][0];

    expect($info['url'])->toBe('wss://studio.example.com/ssh/ws')
        ->and($info['address'])->toBe('deploy@web1.example.com')
        ->and($info)->not->toHaveKey('private_key');

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']])->assertUnauthorized();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer nope'])->assertUnauthorized();

    $headers = ['Authorization' => 'Bearer '.Gateway::token()];
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], $headers)
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'host' => 'web1.example.com',
            'port' => 22,
            'username' => 'deploy',
            'auth' => 'key',
            'private_key' => $key->private_key,
            'host_key' => 'SHA256:known',
        ]);

    expect($connection->fresh()->last_used_at)->not->toBeNull();

    // Spent.
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], $headers)->assertNotFound();
});

test('a ticket is worthless once its owner may no longer use SSH, or after a minute', function () {
    $user = User::factory()->create();
    $connection = SshConnection::factory()->create(['user_id' => $user->id]);
    $headers = ['Authorization' => 'Bearer '.Gateway::token()];

    $ticket = Gateway::issue($connection, $user);
    $user->forceFill(['role' => 'pm'])->save();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], $headers)->assertNotFound();

    $user->forceFill(['role' => 'dev'])->save();
    $ticket = Gateway::issue($connection, $user);
    $this->travel(61)->seconds();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], $headers)->assertNotFound();
});

test('a connection whose key was removed cannot be opened', function () {
    $user = User::factory()->create();
    $key = SshKey::factory()->create(['user_id' => $user->id]);
    $connection = SshConnection::factory()->withKey($key)->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(Terminal::class)
        ->call('deleteKey', $key->uuid)
        ->call('open', $connection->uuid)
        ->assertReturned(null);

    expect($connection->fresh()->ssh_key_id)->toBeNull();
});

test('the token is the one the gateway derives from APP_KEY', function () {
    config(['app.key' => 'base64:abc']);

    // ssh-gateway/test/gateway.test.js checks the same value.
    expect(Gateway::token())->toBe('3cbfdfe372730db4a31a4aa64b773622c18352b46de851cf894f0f5238604d32');
});

test('a connection tied to a project shows up for its developers and every administrator, without its credential', function () {
    $owner = User::factory()->create(['name' => 'Ada']);
    $project = Project::factory()->create(['name' => 'Shop']);
    $project->members()->attach($owner);
    $dev = User::factory()->create();
    $project->members()->attach($dev);
    $outsider = User::factory()->create();
    $pm = User::factory()->pm()->create();
    $project->members()->attach($pm);
    $admin = User::factory()->admin()->create();

    $key = SshKey::factory()->create(['user_id' => $owner->id]);
    $shared = SshConnection::factory()->withKey($key)->create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'web']);
    SshConnection::factory()->create(['user_id' => $owner->id, 'name' => 'private box']);

    expect(SshConnection::query()->visibleTo($dev)->pluck('name')->all())->toBe(['web'])
        ->and(SshConnection::query()->visibleTo($admin)->pluck('name')->all())->toBe(['web'])
        ->and(SshConnection::query()->visibleTo($outsider)->count())->toBe(0)
        ->and(SshConnection::query()->visibleTo($pm)->count())->toBe(0);

    // The developer sees it, tagged with its owner, and may not edit or remove it.
    $page = Livewire::actingAs($dev)->test(Terminal::class)
        ->assertSee('web')
        ->assertSee('Ada')
        ->assertSee('Shop')
        ->assertSee('your key?');
    $page->call('editConnection', $shared->uuid)->assertNotFound();
    Livewire::actingAs($dev)->test(Terminal::class)->call('deleteConnection', $shared->uuid)->assertNotFound();
    Livewire::actingAs($dev)->test(Terminal::class)->call('forgetHostKey', $shared->uuid)->assertNotFound();

    // Opening it without a credential asks for one; the owner's key never comes along.
    Livewire::actingAs($dev)->test(Terminal::class)
        ->call('open', $shared->uuid)
        ->assertReturned(null)
        ->assertSet('credentialFor', $shared->uuid);

    $ticket = Gateway::issue($shared, $dev);
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();

    // With a key of their own it works, with that key.
    $mine = SshKey::factory()->create(['user_id' => $dev->id]);
    Livewire::actingAs($dev)->test(Terminal::class)
        ->call('editCredential', $shared->uuid)
        ->set('credential_auth', 'key')
        ->set('credential_key', $key->uuid)
        ->call('saveCredential')
        ->assertHasErrors('credential_key')
        ->set('credential_key', $mine->uuid)
        ->call('saveCredential')
        ->assertHasNoErrors();

    $info = Livewire::actingAs($dev)->test(Terminal::class)->call('open', $shared->uuid)->effects['returns'][0];
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertJson(['user_id' => $dev->id, 'auth' => 'key', 'private_key' => $mine->private_key, 'host' => 'web1.example.com']);

    // Leaving the project takes the connection away, and its tickets with it.
    $ticket = Gateway::issue($shared, $dev);
    $project->members()->detach($dev);
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
});

test('stars on a shared connection are personal, and the same name may come from two people', function () {
    $project = Project::factory()->create();
    [$ada, $bob] = User::factory()->count(2)->create();
    $project->members()->attach([$ada->id, $bob->id]);
    $adaWeb = SshConnection::factory()->create(['user_id' => $ada->id, 'project_id' => $project->id, 'name' => 'web']);
    SshConnection::factory()->create(['user_id' => $bob->id, 'project_id' => $project->id, 'name' => 'web']);

    Livewire::actingAs($bob)->test(Terminal::class)
        ->call('toggleFavorite', $adaWeb->uuid)
        ->call('newConnection')
        ->set('connection_name', 'web')
        ->set('connection_host', 'web9.example.com')
        ->set('connection_username', 'deploy')
        ->set('connection_auth', 'password')
        ->set('connection_password', 'x')
        ->call('saveConnection')
        ->assertHasErrors(['connection_name' => 'unique']);

    expect($adaWeb->fresh()->favorite)->toBeFalse()
        ->and($adaWeb->credentials()->where('user_id', $bob->id)->value('favorite'))->toBeTrue();
});

test('groups are each person\'s own: a connection, shared ones too, goes in one from its menu or its form', function () {
    $project = Project::factory()->create();
    [$ada, $bob] = User::factory()->count(2)->create();
    $project->members()->attach([$ada->id, $bob->id]);
    $web = SshConnection::factory()->create(['user_id' => $ada->id, 'project_id' => $project->id, 'name' => 'web']);
    $db = SshConnection::factory()->create(['user_id' => $ada->id, 'name' => 'db']);

    Livewire::actingAs($ada)->test(Terminal::class)
        ->call('newGroup')->set('group_name', 'Live')->set('group_color', 'red')->call('saveGroup')->assertHasNoErrors()
        ->call('newGroup')->set('group_name', 'Customers')->call('saveGroup')->assertHasNoErrors()
        ->call('newGroup')->set('group_name', 'Live')->call('saveGroup')->assertHasErrors(['group_name' => 'unique']);
    [$production, $clients] = [$ada->sshGroups()->where('name', 'Live')->sole(), $ada->sshGroups()->where('name', 'Customers')->sole()];

    // from the menu, and from the form: a new connection "here" starts in that group
    Livewire::actingAs($ada)->test(Terminal::class)
        ->call('moveToGroup', $web->uuid, $production->uuid)
        ->call('newConnection', $clients->uuid)
        ->assertSet('connection_group', $clients->uuid)
        ->set('connection_name', 'shop')->set('connection_host', 'shop.example.com')->set('connection_username', 'deploy')
        ->set('connection_auth', 'password')->set('connection_password', 'x')
        ->call('saveConnection')->assertHasNoErrors()
        ->call('editConnection', $web->uuid)
        ->assertSet('connection_group', $production->uuid);

    $shop = $ada->sshConnections()->where('name', 'shop')->sole();
    $in = fn (User $user, SshConnection $connection) => $user->sshPreferences()->where('ssh_connection_id', $connection->id)->first()?->group?->name;
    expect($in($ada, $web))->toBe('Live')->and($in($ada, $shop))->toBe('Customers')->and($in($ada, $db))->toBeNull();

    // the page: groups in their order with their connections, then the ones in none, and a chip for each
    Livewire::actingAs($ada)->test(Terminal::class)
        ->assertSeeInOrder(['Live', 'web', 'Customers', 'shop', 'No group', 'db'])
        ->assertSeeHtml("group = group === '{$production->uuid}' ? '' : '{$production->uuid}'")
        ->assertSeeHtml("'{$production->uuid}')")
        ->call('moveGroup', $clients->uuid, -1)
        ->assertSeeInOrder(['Customers', 'shop', 'Live', 'web']);

    // Bob sees Ada's shared web, outside any group of his; Ada's groups are not his to use
    Livewire::actingAs($bob)->test(Terminal::class)
        ->assertDontSee('Live')
        ->call('moveToGroup', $web->uuid, $production->uuid);
    expect($in($bob, $web))->toBeNull()
        ->and($in($ada, $web))->toBe('Live');

    Livewire::actingAs($bob)->test(Terminal::class)
        ->call('newGroup')->set('group_name', 'Mine')->call('saveGroup')
        ->call('moveToGroup', $web->uuid, $bob->sshGroups()->sole()->uuid);
    expect($in($bob, $web))->toBe('Mine')->and($in($ada, $web))->toBe('Live');

    // nor to edit
    Livewire::actingAs($bob)->test(Terminal::class)->call('editGroup', $production->uuid)->assertNotFound();
});

test('a deleted group leaves its connections in no group', function () {
    $ada = User::factory()->create();
    $web = SshConnection::factory()->create(['user_id' => $ada->id, 'name' => 'web']);
    $group = $ada->sshGroups()->create(['name' => 'Live', 'color' => 'red', 'position' => 1]);

    Livewire::actingAs($ada)->test(Terminal::class)
        ->call('moveToGroup', $web->uuid, $group->uuid)
        ->call('deleteGroup', $group->uuid)
        ->assertDontSee('Live');

    expect(SshConnection::query()->whereKey($web->id)->exists())->toBeTrue()
        ->and($ada->sshPreferences()->where('ssh_connection_id', $web->id)->value('ssh_group_id'))->toBeNull();
});

test('the project picker offers the person\'s projects and the form lists who will see the connection', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Shop']);
    Project::factory()->create(['name' => 'Elsewhere']);
    $project->members()->attach($owner);
    $dev = User::factory()->create(['name' => 'Bob Dev']);
    $project->members()->attach($dev);
    User::factory()->admin()->create(['name' => 'Carla Admin']);
    $pm = User::factory()->pm()->create(['name' => 'Dora PM']);
    $project->members()->attach($pm);

    $page = Livewire::actingAs($owner)->test(Terminal::class);
    expect(collect($page->instance()->searchProjects(''))->pluck('label')->all())->toBe(['Shop'])
        ->and($page->instance()->searchProjects('else'))->toBe([]);

    $page->call('newConnection')
        ->call('pickProject', Project::query()->where('name', 'Elsewhere')->value('id'))
        ->assertSet('connection_project', null)
        ->call('pickProject', $project->uuid)
        ->assertSet('connection_project', $project->uuid)
        ->assertSee('Bob Dev')
        ->assertSee('Carla Admin')
        ->assertDontSee('Dora PM');
});
