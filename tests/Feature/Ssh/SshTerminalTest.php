<?php

use App\Livewire\Ssh\Terminal;
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
        ->set('connection_key', $foreignKey->id)
        ->call('saveConnection')
        ->assertHasErrors(['connection_host', 'connection_key'])
        ->set('connection_host', 'web1.example.com')
        ->set('connection_port', 2222)
        ->set('connection_key', $key->id)
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
        ->call('editConnection', $db->id)
        ->set('connection_username', 'root')
        ->call('saveConnection')
        ->assertHasNoErrors();
    expect($db->fresh())->username->toBe('root')->password->toBe('s3cret');

    $stranger = SshConnection::factory()->create();
    Livewire::actingAs($user)->test(Terminal::class)->call('editConnection', $stranger->id)->assertNotFound();
    Livewire::actingAs($user)->test(Terminal::class)->call('open', $stranger->id)->assertNotFound();
});

test('a new address forgets the accepted host key; trusting one does not overwrite it', function () {
    $user = User::factory()->create();
    $connection = SshConnection::factory()->create(['user_id' => $user->id]);
    $fingerprint = 'SHA256:'.str_repeat('A', 43);

    $component = Livewire::actingAs($user)->test(Terminal::class)
        ->call('trustHostKey', $connection->id, 'not a fingerprint')
        ->call('trustHostKey', $connection->id, $fingerprint);
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('trustHostKey', $connection->id, 'SHA256:'.str_repeat('B', 43));
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('editConnection', $connection->id)->set('connection_username', 'other')->call('saveConnection');
    expect($connection->fresh()->host_key)->toBe($fingerprint);

    $component->call('editConnection', $connection->id)->set('connection_host', 'web2.example.com')->call('saveConnection');
    expect($connection->fresh()->host_key)->toBeNull();
});

test('opening a connection gives a one-time ticket the gateway redeems for the key', function () {
    config(['app.url' => 'https://studio.example.com']);
    $user = User::factory()->create();
    $key = SshKey::factory()->create(['user_id' => $user->id]);
    $connection = SshConnection::factory()->withKey($key)->create(['user_id' => $user->id, 'host_key' => 'SHA256:known']);

    $info = Livewire::actingAs($user)->test(Terminal::class)->call('open', $connection->id)->effects['returns'][0];

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

    $ticket = Gateway::issue($connection);
    $user->forceFill(['role' => 'pm'])->save();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], $headers)->assertNotFound();

    $user->forceFill(['role' => 'dev'])->save();
    $ticket = Gateway::issue($connection);
    $this->travel(61)->seconds();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $ticket], $headers)->assertNotFound();
});

test('a connection whose key was removed cannot be opened', function () {
    $user = User::factory()->create();
    $key = SshKey::factory()->create(['user_id' => $user->id]);
    $connection = SshConnection::factory()->withKey($key)->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(Terminal::class)
        ->call('deleteKey', $key->id)
        ->call('open', $connection->id)
        ->assertReturned(null);

    expect($connection->fresh()->ssh_key_id)->toBeNull();
});

test('the token is the one the gateway derives from APP_KEY', function () {
    config(['app.key' => 'base64:abc']);

    // ssh-gateway/test/gateway.test.js checks the same value.
    expect(Gateway::token())->toBe('3cbfdfe372730db4a31a4aa64b773622c18352b46de851cf894f0f5238604d32');
});
