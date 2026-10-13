<?php

use App\Drive\Access;
use App\Livewire\Tools\Drive;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('local'));

function driveFile(DriveFolder $folder, string $name, string $content = 'hello'): DriveFile
{
    $path = 'drive/'.Str::uuid();
    Storage::disk('local')->put($path, $content);

    return DriveFile::query()->create(['user_id' => $folder->user_id, 'drive_folder_id' => $folder->id, 'name' => $name, 'disk_path' => $path, 'size' => strlen($content), 'mime' => 'text/plain']);
}

test('everyone but clients creates folders; files upload into them and download for whoever may read', function () {
    $user = User::factory()->create();

    Livewire::actingAs(User::factory()->client()->create())->test(Drive::class)->call('newFolder')->assertForbidden();

    $page = Livewire::actingAs($user)->test(Drive::class)
        ->call('newFolder')
        ->set('folder_name', 'Brand')
        ->call('saveFolder')
        ->assertHasNoErrors()
        ->call('newFolder')
        ->set('folder_name', 'Brand')
        ->call('saveFolder')
        ->assertHasErrors('folder_name');

    $folder = $user->driveFolders()->sole();
    $page->call('openFolder', $folder->id)
        ->set('uploads', [UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertSee('logo.pdf')
        ->assertSee('logo (2).pdf');

    $file = DriveFile::query()->where('name', 'logo.pdf')->sole();
    Storage::disk('local')->assertExists($file->disk_path);

    $this->actingAs($user)->get(route('drive.file', $file))->assertOk()->assertDownload('logo.pdf');
    $this->actingAs(User::factory()->create())->get(route('drive.file', $file))->assertNotFound();
});

test('a folder tied to a project opens by role, to read or to write, down the tree; administrators see everything', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $pm = User::factory()->pm()->create();
    $client = User::factory()->client()->create();
    $outsider = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $project->members()->attach([$owner->id, $dev->id, $pm->id, $client->id]);

    $top = $owner->driveFolders()->create(['name' => 'Shop']);
    $inner = $owner->driveFolders()->create(['name' => 'Assets', 'parent_id' => $top->id]);
    $private = $owner->driveFolders()->create(['name' => 'Private']);

    Livewire::actingAs($owner)->test(Drive::class)
        ->call('share', $top->id)
        ->call('pickShareProject', $project->id)
        ->set('share_access.client', 'read')
        ->assertSee($dev->name)
        ->assertSee($client->name);

    expect($top->fresh()->project_access)->toBe(['client' => 'read', 'pm' => 'read', 'dev' => 'write']);

    $level = fn (User $u, DriveFolder $f) => (new Access($u))->level($f->id);
    expect($level($dev, $inner))->toBe('write')
        ->and($level($pm, $inner))->toBe('read')
        ->and($level($client, $inner))->toBe('read')
        ->and($level($outsider, $inner))->toBeNull()
        ->and($level($dev, $private))->toBeNull()
        ->and($level($admin, $private))->toBe('write');

    // The developer finds the shared folder at the start of their drive; the client cannot write in it.
    Livewire::actingAs($dev)->test(Drive::class)->assertSee('Shop')->assertDontSee('Private');
    Livewire::actingAs($client)->test(Drive::class, ['folder' => $inner->id])->call('newFolder')->assertForbidden();
    Livewire::actingAs($dev)->test(Drive::class)->call('share', $top->id)->assertForbidden();

    // A folder further down tied to another project closes it to members of the first.
    $other = Project::factory()->create();
    $inner->update(['project_id' => $other->id, 'project_access' => ['dev' => 'write']]);
    expect((new Access($dev))->level($inner->id))->toBeNull()
        ->and((new Access($owner))->level($inner->id))->toBe('write');
});

test('removing a folder removes what is inside, from the disk too', function () {
    $user = User::factory()->create();
    $top = $user->driveFolders()->create(['name' => 'Old']);
    $inner = $user->driveFolders()->create(['name' => 'Older', 'parent_id' => $top->id]);
    $file = driveFile($inner, 'notes.txt');

    Livewire::actingAs($user)->test(Drive::class)->set('selectedFolders', [$top->id])->call('deleteSelected');

    expect(DriveFolder::query()->count())->toBe(0)->and(DriveFile::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($file->disk_path);
});

test('from a project folder, files and folders are copied or moved into the person\'s workspace', function () {
    $dev = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($dev);
    Workspace::factory()->running()->create(['user_id' => $dev->id, 'project_id' => $project->id]);

    $top = $dev->driveFolders()->create(['name' => 'Shop', 'project_id' => $project->id, 'project_access' => ['dev' => 'write']]);
    $assets = $dev->driveFolders()->create(['name' => 'assets', 'parent_id' => $top->id]);
    $logo = driveFile($assets, 'logo.svg', '<svg/>');
    $readme = driveFile($top, 'README.md', '# hi');

    $uploads = [];
    Http::fake(function (Request $request) use (&$uploads) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match (true) {
            $path === '/files' && $request->method() === 'GET' => Http::response(['path' => $query['path'] ?? '', 'entries' => [['name' => 'public', 'type' => 'dir', 'size' => 0]]]),
            $path === '/files' && $request->method() === 'POST' => Http::response(['path' => $request['path'], 'type' => 'dir'], 201),
            $path === '/files/upload' => (function () use (&$uploads, $query, $request) {
                $uploads[$query['path']] = [$request->body(), $query['overwrite']];

                return Http::response(['path' => $query['path'], 'size' => strlen($request->body())], 201);
            })(),
            default => Http::response(['error' => 'unexpected'], 500),
        };
    });

    Livewire::actingAs($dev)->test(Drive::class, ['folder' => $top->id])
        ->assertSee('To the project')
        ->call('startTransfer', 'copy')
        ->assertSee('public')
        ->call('browse', 'public')
        ->assertSet('transfer_path', 'public')
        ->set('selectedFolders', [$assets->id])
        ->set('selectedFiles', [$readme->id])
        ->set('transfer_overwrite', true)
        ->call('transfer')
        ->assertSet('transfer_error', null);

    expect($uploads)->toBe(['public/README.md' => ['# hi', '1'], 'public/assets/logo.svg' => ['<svg/>', '1']])
        ->and(DriveFile::query()->count())->toBe(2);

    // A move removes from the drive what arrived.
    $uploads = [];
    Livewire::actingAs($dev)->test(Drive::class, ['folder' => $top->id])
        ->call('startTransfer', 'move')
        ->set('selectedFolders', [$assets->id])
        ->call('transfer');

    expect(array_keys($uploads))->toBe(['assets/logo.svg'])
        ->and(DriveFolder::query()->whereKey($assets->id)->exists())->toBeFalse()
        ->and(DriveFile::query()->pluck('name')->all())->toBe(['README.md']);
    Storage::disk('local')->assertMissing($logo->disk_path);
});

test('no copy to the project without access to its file manager', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    $pm = User::factory()->pm()->create();
    $client = User::factory()->client()->create();
    $project->members()->attach([$owner->id, $pm->id, $client->id]);
    $top = $owner->driveFolders()->create(['name' => 'Shop', 'project_id' => $project->id, 'project_access' => ['client' => 'read', 'pm' => 'write']]);
    Workspace::factory()->running()->create(['user_id' => $client->id, 'project_id' => $project->id]);

    // A client never has the project's file manager; a PM without a workspace has nowhere to copy to.
    Livewire::actingAs($client)->test(Drive::class, ['folder' => $top->id])->assertDontSee('To the project')->call('startTransfer', 'copy')->assertForbidden();
    Livewire::actingAs($pm)->test(Drive::class, ['folder' => $top->id])->assertDontSee('To the project');
    // Not tied to a project: nothing to copy to.
    $loose = $owner->driveFolders()->create(['name' => 'Loose']);
    Livewire::actingAs($owner)->test(Drive::class, ['folder' => $loose->id])->assertDontSee('To the project');
});

test('the tools pages render in Italian', function () {
    app()->setLocale('it');
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('ssh'))->assertOk()->assertSee('Terminale SSH')->assertSee('Chiavi');
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('vault'))->assertOk()->assertSee('Le mie cartelle');
    $this->actingAs($user)->get(route('drive'))->assertOk()->assertSee('Il tuo drive è vuoto', false);
});
