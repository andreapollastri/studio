<?php

use App\Livewire\Tools\Vault;
use App\Models\Project;
use App\Models\User;
use App\Models\VaultItem;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function confirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('everyone reaches the password manager after confirming their password', function () {
    $this->actingAs(User::factory()->client()->create())->get(route('vault'))->assertRedirect(route('password.confirm'));

    foreach ([User::factory()->create(), User::factory()->client()->create(), User::factory()->pm()->create()] as $user) {
        $this->actingAs($user)->withSession(confirmed())->get(route('vault'))->assertOk()->assertSee('Password manager');
    }
});

test('an item is stored encrypted and its secrets only leave on request', function () {
    $user = User::factory()->create();

    $page = Livewire::actingAs($user)->test(Vault::class)
        ->call('newFolder')
        ->set('folder_name', 'Hosting')
        ->call('saveFolder')
        ->call('newItem')
        ->set('item_title', 'Forge')
        ->set('item_url', 'https://forge.example.com')
        ->set('item_username', 'ada@example.com')
        ->set('item_password', 'Sup3r-secret!')
        ->set('item_totp', 'otpauth://totp/Forge:ada?secret=JBSWY3DPEHPK3PXP&issuer=Forge')
        ->call('saveItem')
        ->assertHasNoErrors()
        ->assertSee('Forge')
        ->assertDontSee('Sup3r-secret!');

    $item = $user->vaultItems()->sole();
    $raw = DB::table('vault_items')->where('id', $item->id)->first();

    expect($item->vault_folder_id)->toBe($user->vaultFolders()->sole()->id)
        ->and($item->totp_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($raw->password)->not->toContain('Sup3r')
        ->and($raw->username)->not->toContain('ada@')
        ->and($page->instance()->reveal($item->uuid, 'password'))->toBe('Sup3r-secret!')
        ->and($page->instance()->totp($item->uuid))->toHaveKeys(['code', 'remaining'])
        ->and($page->instance()->totp($item->uuid)['code'])->toMatch('/^\d{6}$/');

    Livewire::actingAs($user)->test(Vault::class)
        ->call('newItem')->set('item_title', 'x')->set('item_totp', 'not base32 !!')->call('saveItem')
        ->assertHasErrors('item_totp');
});

test('a project item reaches the members whose role is ticked, and every administrator', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $client = User::factory()->client()->create();
    $pm = User::factory()->pm()->create();
    $outsider = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $project->members()->attach([$owner->id, $dev->id, $client->id, $pm->id]);

    $item = VaultItem::factory()->create(['user_id' => $owner->id]);
    $page = Livewire::actingAs($owner)->test(Vault::class)
        ->call('sharing', $item->uuid)
        ->call('pickShareProject', $project->uuid);

    expect($item->fresh()->project_roles)->toBe(['dev']);

    $page->set('share_roles', ['client', 'dev']);

    $visible = fn (User $u) => VaultItem::query()->visibleTo($u)->whereKey($item->id)->exists();
    expect($visible($dev))->toBeTrue()
        ->and($visible($client))->toBeTrue()
        ->and($visible($pm))->toBeFalse()
        ->and($visible($outsider))->toBeFalse()
        ->and($visible($admin))->toBeTrue()
        ->and($item->fresh()->canEdit($dev))->toBeFalse()
        ->and($item->fresh()->canEdit($admin))->toBeTrue();

    // A client sees it but may not change it, nor create anything.
    Livewire::actingAs($client)->test(Vault::class)->assertSee($item->title)->call('editItem', $item->uuid)->assertForbidden();
    Livewire::actingAs($client)->test(Vault::class)->call('newItem')->assertForbidden();
    Livewire::actingAs($client)->test(Vault::class)->call('newFolder')->assertForbidden();
    Livewire::actingAs($outsider)->test(Vault::class)->call('reveal', $item->uuid, 'password')->assertNotFound();

    // Personal items stay personal, even for administrators.
    $private = VaultItem::factory()->create(['user_id' => $owner->id]);
    expect($private->isVisibleTo($admin))->toBeFalse();
});

test('an item is shared with single people, to view or to edit, and the table shows who sees it and why', function () {
    $owner = User::factory()->create();
    $bob = User::factory()->create(['name' => 'Bob Builder']);
    $item = VaultItem::factory()->create(['user_id' => $owner->id]);

    $page = Livewire::actingAs($owner)->test(Vault::class)->call('sharing', $item->uuid);
    expect(collect($page->instance()->searchUsers('bob'))->pluck('label')->all())->toBe(['Bob Builder']);

    $page->call('addShare', $bob->uuid)->assertSee('Bob Builder')->assertSee('shared directly');
    expect($item->isVisibleTo($bob))->toBeTrue()->and($item->canEdit($bob))->toBeFalse();

    Livewire::actingAs($bob)->test(Vault::class)->call('editItem', $item->uuid)->assertForbidden();
    Livewire::actingAs($owner)->test(Vault::class)->call('sharing', $item->uuid)->call('setShareEdit', $bob->uuid, true);
    expect($item->fresh()->canEdit($bob))->toBeTrue()->and($item->fresh()->canManage($bob))->toBeFalse();

    Livewire::actingAs($bob)->test(Vault::class)
        ->call('editItem', $item->uuid)
        ->set('item_password', 'changed-by-bob')
        ->call('saveItem')
        ->assertHasNoErrors();
    expect($item->fresh())->password->toBe('changed-by-bob')->updated_by->toBe($bob->id);
    Livewire::actingAs($bob)->test(Vault::class)->call('sharing', $item->uuid)->assertForbidden();
    Livewire::actingAs($bob)->test(Vault::class)->call('deleteItem', $item->uuid)->assertForbidden();

    Livewire::actingAs($owner)->test(Vault::class)->call('sharing', $item->uuid)->call('removeShare', $bob->uuid);
    expect($item->fresh()->isVisibleTo($bob))->toBeFalse();
});

test('removing a folder moves its items, and those of the folders inside, to the top level', function () {
    $user = User::factory()->create();
    $outer = $user->vaultFolders()->create(['name' => 'Clients']);
    $inner = $user->vaultFolders()->create(['name' => 'Acme', 'parent_id' => $outer->id]);
    $item = VaultItem::factory()->create(['user_id' => $user->id, 'vault_folder_id' => $inner->id]);

    Livewire::actingAs($user)->test(Vault::class)->call('deleteFolder', $outer->uuid);

    expect($user->vaultFolders()->count())->toBe(0)->and($item->fresh()->vault_folder_id)->toBeNull();
    Livewire::actingAs(User::factory()->create())->test(Vault::class)->call('renameFolder', $outer->uuid)->assertNotFound();
});
