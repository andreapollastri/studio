<?php

use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('the pane has no Board: the tabs are the preview, the changes, the terminal and the files', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertDontSee('Board')
        ->call('setTab', 'board')
        ->assertSet('tab', 'preview');
});

test('the changes tab shows the git state read through the bridge', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    Http::fake(['http://bridge.test:4455/git' => Http::response([
        'branch' => 'feature/US-014-filtro-data',
        'status' => [' M app/Models/Order.php', '?? tests/Feature/OrderFilterTest.php'],
        'stat' => ' 2 files changed, 42 insertions(+), 7 deletions(-)',
        'diff' => "diff --git a/app/Models/Order.php b/app/Models/Order.php\n+    public function scopeBetween()",
    ])]);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'changes')
        ->assertSet('gitError', null)
        ->assertSee('feature/US-014-filtro-data')
        ->assertSee('OrderFilterTest')
        ->assertSee('scopeBetween');
});

test('the preview tab embeds the workspace app', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id, 'app_url' => 'https://maria-shop.dev.example.test']);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertSeeHtml('src="https://maria-shop.dev.example.test"');
});

test('the pane has a full screen switch, which the project page listens to', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id, 'app_url' => 'https://maria-shop.dev.example.test']);

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertSeeHtml("\$dispatch('studio-pane-toggle')")
        ->assertSeeHtml('aria-label="Full screen"');

    expect(file_get_contents(resource_path('views/livewire/studio/shell.blade.php')))
        ->toContain('x-on:studio-pane-toggle.window="wide = ! wide"');
});
