<?php

use App\Databases\Driver;
use App\Databases\Environment;
use App\Livewire\Studio\PaneDatabase;
use App\Livewire\Studio\SidePane;
use App\Livewire\Tools\Databases;
use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** The workspace's .env as the bridge reads it; called again, the next reads get the new one. */
function fakeEnvBridge(string $env): void
{
    $first = config('testing.workspace_env') === null;
    config(['testing.workspace_env' => $env]);

    if ($first) {
        Http::fake(fn (Request $request) => $request->url() === 'http://bridge.test:4455/files/read?path=.env'
            ? Http::response(['path' => '.env', 'binary' => false, 'hash' => 'e', 'content' => config('testing.workspace_env')])
            : Http::response(['error' => 'unexpected '.$request->url()], 500));
    }
}

test('Local opens the workspace\'s database as its .env says, through a connection Databases does not list', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Shop Acme', 'db_engine' => 'mariadb']);
    $project->syncMembers([$user->id => 'dev']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id, 'project_id' => $project->id]);
    fakeEnvBridge("APP_NAME=Shop\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=ws_maria_shop\nDB_USERNAME=ws_maria_shop\nDB_PASSWORD=\"s3cret\"\n");

    Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $workspace])
        ->assertSeeInOrder(['Doc', 'Preview', 'Git', 'Terminal', 'Files', 'Db'])
        ->call('setTab', 'db')
        ->assertSet('dbMode', 'local')
        ->assertSet('dbError', null)
        ->assertSeeLivewire(PaneDatabase::class)
        // nothing else to choose from: no mode switcher
        ->assertDontSee('Remote');

    $connection = DatabaseConnection::query()->where('managed', 'workspace:'.$workspace->id)->sole();
    expect($connection->user_id)->toBe($user->id)
        ->and($connection->driver)->toBe(Driver::Mariadb)
        ->and($connection->environment)->toBe(Environment::Local)
        ->and($connection->database)->toBe('ws_maria_shop')
        ->and($connection->password)->toBe('s3cret')
        ->and($connection->name)->toBe('Shop Acme · workspace');

    // opened again, the same connection with fresh credentials; never in Tools → Databases
    fakeEnvBridge("DB_CONNECTION=mysql\nDB_DATABASE=ws_maria_shop\nDB_USERNAME=ws_maria_shop\nDB_PASSWORD=rotated\n");
    Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $workspace])->call('setTab', 'db');
    expect(DatabaseConnection::query()->where('managed', 'workspace:'.$workspace->id)->sole()->password)->toBe('rotated');

    Livewire::actingAs($user)->withQueryParams([])->test(Databases::class)->assertDontSee('Shop Acme · workspace');
});

test('Local says why when the workspace is stopped or its database is not one the tab opens', function () {
    $user = User::factory()->create();
    $stopped = Workspace::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $stopped])
        ->call('setTab', 'db')
        ->assertSee('opens when the workspace is running');

    $running = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeEnvBridge("DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");

    Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $running])
        ->call('setTab', 'db')
        ->assertSee('which the Db tab does not open')
        ->assertSet('dbConnection', null);

    $client = User::factory()->client()->create();
    $clientWorkspace = Workspace::factory()->running()->create(['user_id' => $client->id, 'callback_token' => 'client-token', 'callback_token_hash' => hash('sha256', 'client-token')]);
    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $clientWorkspace])
        ->call('setTab', 'db')
        ->assertSet('tab', 'preview');
});

test('Develop opens the project site\'s database after the password, with what the server helper knows', function () {
    config(['studio.driver' => 'native', 'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'), 'studio.native.sudo' => false]);
    $project = Project::factory()->online()->create(['slug' => 'shop-acme', 'name' => 'Shop Acme', 'db_engine' => 'pgsql']);
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $dev->id, 'project_id' => $project->id]);
    fakeEnvBridge("DB_CONNECTION=pgsql\nDB_DATABASE=ws\nDB_USERNAME=ws\nDB_PASSWORD=x\n");

    Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'db')
        ->assertSee('Develop')
        ->call('setDbMode', 'develop')
        ->assertSet('dbMode', 'develop')
        ->assertSee('confirm your password to open this database');

    expect(DatabaseConnection::query()->where('managed', 'site:'.$project->id)->exists())->toBeFalse();

    session()->put('auth.password_confirmed_at', time());
    Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'db')
        ->call('setDbMode', 'develop')
        ->assertSet('dbError', null)
        ->assertSeeLivewire(PaneDatabase::class);

    $site = DatabaseConnection::query()->where('managed', 'site:'.$project->id)->sole();
    expect($site->driver)->toBe(Driver::Pgsql)
        ->and($site->environment)->toBe(Environment::Staging)
        ->and($site->database)->toBe('prj_shop_acme')
        ->and($site->username)->toBe('prj_shop_acme')
        ->and($site->password)->toBe('site-secret');

    // a project manager has no Develop
    $pm = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev', $pm->id => 'pm']);
    $pmWorkspace = Workspace::factory()->running()->create(['user_id' => $pm->id, 'project_id' => $project->id, 'callback_token' => 'pm-token', 'callback_token_hash' => hash('sha256', 'pm-token')]);
    Livewire::actingAs($pm)->test(SidePane::class, ['workspace' => $pmWorkspace])
        ->call('setTab', 'db')
        ->call('setDbMode', 'develop')
        ->assertSet('dbMode', 'local');
});

test('Remote opens the project\'s Databases connections its role may use, inside the pane', function () {
    $path = tempnam(sys_get_temp_dir(), 'studio-pane-db-').'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE loyalty_points (id INTEGER PRIMARY KEY, points INTEGER)');
    $pdo->exec('INSERT INTO loyalty_points (points) VALUES (120)');

    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $dev->id, 'project_id' => $project->id]);

    $shared = DatabaseConnection::factory()->for($admin)->sqlite($path)->create(['name' => 'Loyalty copy', 'project_id' => $project->id, 'project_access' => ['dev' => 'read']]);
    DatabaseConnection::factory()->for($admin)->sqlite($path)->create(['name' => 'PM only', 'project_id' => $project->id, 'project_access' => ['pm' => 'read']]);
    fakeEnvBridge("DB_CONNECTION=mysql\nDB_DATABASE=ws\nDB_USERNAME=ws\nDB_PASSWORD=x\n");
    session()->put('auth.password_confirmed_at', time());

    $pane = Livewire::actingAs($dev)->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'db')
        ->assertSee('Remote')
        ->call('setDbMode', 'remote')
        ->assertSet('dbMode', 'remote')
        ->assertSet('dbConnection', $shared->uuid)
        ->assertSeeLivewire(PaneDatabase::class);

    expect($pane->instance()->remoteDatabases->pluck('name')->all())->toBe(['Loyalty copy']);

    Livewire::actingAs($dev)->test(PaneDatabase::class, ['connection' => $shared])
        ->assertSee('loyalty_points')
        ->assertDontSee('All connections')
        ->call('openTable', 'loyalty_points')
        ->assertSee('120')
        ->assertSee('Read only');

    // the conversation's URL is not the client's: no ?table=… read from it
    Livewire::actingAs($dev)->withQueryParams(['table' => 'other', 'tab' => 'sql'])->test(PaneDatabase::class, ['connection' => $shared])
        ->assertSet('table', null)
        ->assertSet('tab', 'data');

    @unlink($path);
});
