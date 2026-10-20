<?php

use App\Models\User;
use App\Models\Workspace;

test('every page with confirmations carries Studio\'s own dialog, in place of the browser\'s', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.domains'))
        ->assertOk()
        ->assertSee('x-show="$store.dialog.shown"', false);

    $workspace = Workspace::factory()->running()->create(['user_id' => $admin->id]);
    $this->actingAs($admin)->get(route('studio.show', $workspace->project))
        ->assertOk()
        ->assertSee('x-show="$store.dialog.shown"', false);
});
