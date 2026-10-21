<?php

use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeDocBridge(): void
{
    Http::fake(fn (Request $request) => match ($request->url()) {
        'http://bridge.test:4455/files?path=' => Http::response(['path' => '', 'entries' => [
            ['name' => 'docs', 'type' => 'dir', 'size' => 0, 'mtime' => null],
            ['name' => 'CHANGELOG.md', 'type' => 'file', 'size' => 20, 'mtime' => null],
            ['name' => 'composer.json', 'type' => 'file', 'size' => 20, 'mtime' => null],
            ['name' => 'README.md', 'type' => 'file', 'size' => 90, 'mtime' => null],
        ]]),
        'http://bridge.test:4455/files/read?path=README.md' => Http::response(['path' => 'README.md', 'binary' => false, 'hash' => 'a', 'content' => "# Shop Acme\n\n![logo](docs/logo.png) [guide](./docs/guide.md) [site](https://acme.test) [bad](javascript:alert(1))\n\n<script>alert(1)</script>\n\n| a | b |\n|---|---|\n| 1 | 2 |\n"]),
        'http://bridge.test:4455/files/read?path=CHANGELOG.md' => Http::response(['path' => 'CHANGELOG.md', 'binary' => false, 'hash' => 'b', 'content' => "## 1.4\n\n- Loyalty points\n"]),
        default => Http::response(['error' => 'unexpected '.$request->url()], 500),
    });
}

test('the Doc tab shows the README as a page: tables, images from the workspace, no scripts or unsafe links', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeDocBridge();

    $logo = route('studio.files.download', ['workspace' => $workspace->uuid, 'path' => 'docs/logo.png']);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertSeeInOrder(['Doc', 'Preview', 'Git', 'Terminal', 'Files'])
        ->call('setTab', 'doc')
        ->assertSet('tab', 'doc')
        ->assertSeeHtml('<h1>Shop Acme</h1>')
        ->assertSeeHtml('<table>')
        ->assertSeeHtml('src="'.e($logo).'"')
        ->assertSeeHtml('href="https://acme.test"')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('javascript:alert')
        // the other Markdown files at the top, README first
        ->assertSeeInOrder(['README.md', 'CHANGELOG.md'])
        ->call('setDoc', 'CHANGELOG.md')
        ->assertSeeHtml('<li>Loyalty points</li>')
        ->call('setDoc', '../../etc/passwd')
        ->assertSet('docPath', null)
        ->assertSeeHtml('<h1>Shop Acme</h1>');
});

test('the Doc tab waits for the workspace, and is not there for who has none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'doc')
        ->assertSee('The README appears when the workspace is running.');

    $client = User::factory()->client()->create();
    $clientWorkspace = Workspace::factory()->running()->create(['user_id' => $client->id, 'callback_token' => 'client-token', 'callback_token_hash' => hash('sha256', 'client-token')]);

    Livewire::actingAs($client)
        ->test(SidePane::class, ['workspace' => $clientWorkspace])
        ->assertDontSee('Doc')
        ->call('setTab', 'doc')
        ->assertSet('tab', 'preview');
});
