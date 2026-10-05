<?php

use App\Livewire\Studio\Files;
use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeFileBridge(array $extra = []): void
{
    Http::fake(function (Request $request) use ($extra) {
        $url = $request->url();

        foreach ($extra as $pattern => $response) {
            if (str_starts_with($url, $pattern) && ($response['method'] ?? $request->method()) === $request->method()) {
                return Http::response($response['body'], $response['status'] ?? 200);
            }
        }

        return match (true) {
            $url === 'http://bridge.test:4455/files?path=' => Http::response(['path' => '', 'entries' => [
                ['name' => 'app', 'type' => 'dir', 'size' => 0, 'mtime' => null],
                ['name' => 'README.md', 'type' => 'file', 'size' => 7, 'mtime' => '2026-10-05T10:00:00.000Z'],
                ['name' => 'logo.png', 'type' => 'file', 'size' => 2048, 'mtime' => '2026-10-05T10:00:00.000Z'],
            ]]),
            $url === 'http://bridge.test:4455/files?path=app' => Http::response(['path' => 'app', 'entries' => [
                ['name' => 'Models', 'type' => 'dir', 'size' => 0, 'mtime' => null],
            ]]),
            $url === 'http://bridge.test:4455/files?path=app%2FModels' => Http::response(['path' => 'app/Models', 'entries' => [
                ['name' => 'Order.php', 'type' => 'file', 'size' => 120, 'mtime' => '2026-10-05T10:00:00.000Z'],
            ]]),
            $url === 'http://bridge.test:4455/files/read?path=app%2FModels%2FOrder.php' => Http::response(['path' => 'app/Models/Order.php', 'size' => 120, 'binary' => false, 'content' => "<?php\n\nclass Order {}\n", 'hash' => 'abc123']),
            $url === 'http://bridge.test:4455/files/read?path=logo.png' => Http::response(['path' => 'logo.png', 'size' => 2048, 'binary' => true]),
            default => Http::response(['error' => 'unexpected '.$request->method().' '.$url], 500),
        };
    });
}

test('the tree starts at the workspace root and expands folders through the bridge', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge();

    Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->assertSet('error', null)
        ->assertSee('app')
        ->assertSee('README.md')
        ->call('toggle', 'app')
        ->assertSee('Models')
        ->call('toggle', 'app/Models')
        ->assertSee('Order.php')
        ->assertSet('expanded', ['app', 'app/Models'])
        ->call('toggle', 'app')
        ->assertSet('expanded', ['app/Models'])
        ->assertDontSee('Order.php');
});

test('opening a text file hands it to the editor with its hash; a binary is described instead', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->call('open', 'app/Models/Order.php')
        ->assertDispatched('files-open', path: 'app/Models/Order.php', content: "<?php\n\nclass Order {}\n", hash: 'abc123')
        ->call('open', 'logo.png')
        ->assertNotDispatched('files-open', path: 'logo.png')
        ->assertSee('logo.png is a binary file');

    expect($component->get('unopenable')['binary'])->toBeTrue();
});

test('saving sends the decoded text with the hash and reports the new hash, or the conflict', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge(['http://bridge.test:4455/files' => ['method' => 'PUT', 'body' => ['path' => 'README.md', 'size' => 9, 'hash' => 'def456']]]);

    Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->call('save', 'README.md', base64_encode("# Demo \n"), 'abc123')
        ->assertReturned(['ok' => true, 'hash' => 'def456']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'http://bridge.test:4455/files'
        && $request['content'] === "# Demo \n"
        && $request['hash'] === 'abc123');
});

test('a conflict on disk and undecodable content both come back as errors, not exceptions', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge(['http://bridge.test:4455/files' => ['method' => 'PUT', 'status' => 409, 'body' => ['error' => 'the file changed on disk since it was opened']]]);

    Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->call('save', 'README.md', base64_encode('stale'), 'abc123')
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['error'], 'changed on disk'));

    Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->call('save', 'README.md', 'not base64!!', 'abc123')
        ->assertReturned(fn (array $result) => $result['ok'] === false && str_contains($result['error'], 'could not decode'));
});

test('a new file is created in the chosen folder and opened; rename and delete go through the bridge', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge([
        'http://bridge.test:4455/files/rename' => ['method' => 'POST', 'body' => ['from' => 'README.md', 'to' => 'NOTES.md']],
        'http://bridge.test:4455/files/read?path=app%2FService.php' => ['method' => 'GET', 'body' => ['path' => 'app/Service.php', 'size' => 0, 'binary' => false, 'content' => '', 'hash' => 'empty']],
        'http://bridge.test:4455/files?path=README.md' => ['method' => 'DELETE', 'body' => ['path' => 'README.md']],
        'http://bridge.test:4455/files' => ['method' => 'POST', 'status' => 201, 'body' => ['path' => 'app/Service.php', 'type' => 'file']],
    ]);

    Livewire::actingAs($user)
        ->test(Files::class, ['workspace' => $workspace])
        ->call('startCreating', 'app', 'file')
        ->assertSet('expanded', ['app'])
        ->set('name', 'bad/name')
        ->call('create')
        ->assertSee('Use a plain name, without slashes.')
        ->set('name', 'Service.php')
        ->call('create')
        ->assertSet('creating', null)
        ->assertDispatched('files-open', path: 'app/Service.php')
        ->call('startRenaming', 'README.md')
        ->assertSet('name', 'README.md')
        ->set('name', 'NOTES.md')
        ->call('rename')
        ->assertDispatched('files-renamed', oldPath: 'README.md', newPath: 'NOTES.md')
        ->call('delete', 'README.md')
        ->assertDispatched('files-deleted', path: 'README.md');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://bridge.test:4455/files' && $request['path'] === 'app/Service.php' && $request['type'] === 'file');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://bridge.test:4455/files/rename' && $request['from'] === 'README.md' && $request['to'] === 'NOTES.md');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://bridge.test:4455/files?path=README.md');
});

test('the Files tab is there for a developer, mounts the file manager, and is absent for a client', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeFileBridge();

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertSee('Files')
        ->call('setTab', 'files')
        ->assertSet('filesMounted', true)
        ->assertSee('Pick a file on the left')
        ->assertSeeLivewire(Files::class);

    $client = User::factory()->client()->create();
    $clientWorkspace = Workspace::factory()->running()->create(['user_id' => $client->id, 'callback_token' => 'client-token', 'callback_token_hash' => hash('sha256', 'client-token')]);

    Livewire::actingAs($client)
        ->test(SidePane::class, ['workspace' => $clientWorkspace])
        ->assertDontSee('Files');

    Livewire::actingAs($client)
        ->test(Files::class, ['workspace' => $clientWorkspace])
        ->assertForbidden();
});
