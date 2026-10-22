<?php

use App\Livewire\Tools\DatabaseClient;
use App\Livewire\Tools\Databases;
use App\Models\DatabaseConnection;
use App\Models\DatabaseQuery;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** A SQLite file with two tables, on Studio's disk, as an administrator connects to it. */
function sqliteFixture(): array
{
    $path = tempnam(sys_get_temp_dir(), 'studio-db-').'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE, notes TEXT)');
    $pdo->exec('CREATE TABLE log (line TEXT)');
    $pdo->exec("INSERT INTO customers (name, email) VALUES ('Ada', 'ada@example.com'), ('Grace', 'grace@example.com'), ('Linus', NULL)");
    $pdo->exec("INSERT INTO log VALUES ('first'), ('second')");

    $admin = User::factory()->admin()->create();
    $connection = DatabaseConnection::factory()->for($admin)->sqlite($path)->create(['name' => 'Fixture']);

    return [$admin, $connection, $path];
}

function rowKeyOf(int $id): string
{
    return DatabaseClient::rowKey(['id' => $id]);
}

afterEach(function () {
    Cache::flush();
});

test('the client lists tables, pages rows, filters and sorts them', function () {
    [$admin, $connection] = sqliteFixture();

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertSee('customers')
        ->assertSee('log')
        ->call('openTable', 'customers')
        ->assertSee('ada@example.com')
        ->assertSee('Linus')
        ->assertSee('3 rows');

    $page->call('addFilter', 'name')->set('filters.0.op', 'starts')->set('filters.0.value', 'Gr')
        ->assertSee('grace@example.com')->assertDontSee('ada@example.com')->assertSee('1 row');

    $page->set('filters.0.column', 'email')->set('filters.0.op', 'null')
        ->assertSee('Linus')->assertDontSee('Grace');

    $page->call('removeFilter', 0)->call('sortBy', 'name')->call('sortBy', 'name')
        ->assertSet('direction', 'desc')
        ->assertSeeInOrder(['Linus', 'Grace', 'Ada']);

    $page->call('openTable', 'customers', 'structure')->assertSee('autoincrement')->assertSee('email')->assertSee('Add column')->assertSee('Constraints and foreign keys')->assertSee('sqlite_autoindex_customers_1')
        ->call('setTab', 'ddl')->assertSee('CREATE TABLE customers');
});

test('cells and rows are changed in place, added and deleted', function () {
    [$admin, $connection, $path] = sqliteFixture();
    $pdo = new PDO('sqlite:'.$path);

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])->call('openTable', 'customers');

    expect($page->instance()->cellValue(rowKeyOf(1), 'email'))->toBe(['value' => 'ada@example.com', 'binary' => false]);

    $page->call('saveCell', rowKeyOf(1), 'email', 'ada@lovelace.dev');
    $page->call('saveCell', rowKeyOf(2), 'notes', null);
    expect($pdo->query('SELECT email FROM customers WHERE id = 1')->fetchColumn())->toBe('ada@lovelace.dev');

    $page->call('newRow')
        ->assertSet('rowDefaults.id', true)
        ->set('rowValues.name', 'Margaret')
        ->set('rowValues.email', 'margaret@example.com')
        ->set('rowDefaults.email', false)
        ->set('rowDefaults.notes', true)
        ->call('saveRow');
    expect($pdo->query("SELECT COUNT(*) FROM customers WHERE name = 'Margaret' AND email = 'margaret@example.com'")->fetchColumn())->toBe(1);

    $page->call('editRow', rowKeyOf(3))->assertSet('rowNulls.notes', true)->set('rowNulls.notes', false)->set('rowValues.notes', 'kernel')->call('saveRow');
    expect($pdo->query('SELECT notes FROM customers WHERE id = 3')->fetchColumn())->toBe('kernel');

    $page->call('deleteRows', [rowKeyOf(2), rowKeyOf(3)]);
    expect($pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn())->toBe(2);

    // a table without a primary key: its rows are found by rowid
    $page->call('openTable', 'log')->assertSee('first');
    $page->call('deleteRows', [DatabaseClient::rowKey(['__studio_row' => 1])]);
    expect($pdo->query('SELECT line FROM log')->fetchAll(PDO::FETCH_COLUMN))->toBe(['second']);
});

test('the SQL editor runs every statement, each with its result, and keeps the history', function () {
    [$admin, $connection] = sqliteFixture();

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'sql')
        ->set('sql', "UPDATE customers SET notes = 'vip' WHERE id < 3;\nSELECT name, notes FROM customers ORDER BY id;\nSELECT * FROM nowhere;\nSELECT 1;")
        ->call('runSql')
        ->assertSee('2 rows changed')
        ->assertSee('vip')
        ->assertSee('no such table: nowhere');

    $results = Cache::get($page->get('resultsKey'));
    expect($results)->toHaveCount(3)
        ->and($results[1]['rows'])->toBe([['Ada', 'vip'], ['Grace', 'vip'], ['Linus', null]])
        ->and($results[2]['error'])->toContain('no such table');

    $history = DatabaseQuery::query()->where('user_id', $admin->id)->sole();
    expect($history->status)->toBe('error')->and($history->sql)->toContain('UPDATE customers');

    $page->set('saveTitle', 'VIPs')->call('saveQuery')->assertHasNoErrors();
    expect(DatabaseQuery::query()->where('saved', true)->sole()->title)->toBe('VIPs');

    // the rows a statement shows are capped
    config(['studio.databases.row_limit' => 2]);
    $page->call('runSql', 'SELECT * FROM customers');
    $results = Cache::get($page->get('resultsKey'));
    expect($results[0]['rows'])->toHaveCount(2)->and($results[0]['truncated'])->toBeTrue();
});

test('a person who may only read reads, and nothing they run writes', function () {
    [$admin, $connection, $path] = sqliteFixture();
    $project = Project::factory()->create();
    $pm = User::factory()->pm()->create();
    $project->members()->attach([$pm->id => ['role' => 'pm']]);
    $connection->update(['project_id' => $project->id, 'project_access' => ['pm' => 'read']]);

    $page = Livewire::actingAs($pm)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertSee('Read only')
        ->call('openTable', 'customers')
        ->assertSee('Ada')
        ->call('saveCell', rowKeyOf(1), 'name', 'Hacked')
        ->call('deleteRows', [rowKeyOf(1)])
        ->call('runSql', 'DELETE FROM customers')
        ->assertSee('You may only read this connection')
        ->call('runSql', "SELECT name FROM customers WHERE name = 'Ada'")
        ->assertSee('1 row');

    $count = (new PDO('sqlite:'.$path))->query("SELECT COUNT(*) FROM customers WHERE name = 'Ada'")->fetchColumn();
    expect($count)->toBe(1);

    // a statement that pretends to read is stopped by the read-only connection
    $page->call('runSql', 'SELECT 1; PRAGMA user_version = 7');
    expect((new PDO('sqlite:'.$path))->query('PRAGMA user_version')->fetchColumn())->toBe(0);

    // once the share is taken back, the next request fails
    $connection->update(['project_access' => ['pm' => 'none']]);
    $page->call('openTable', 'log')->assertNotFound();
});

test('production opens locked, unlocks for the session, and asks before statements that write', function () {
    [$admin, $connection, $path] = sqliteFixture();
    $connection->update(['environment' => 'production']);
    $pdo = new PDO('sqlite:'.$path);

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertSee('Locked')
        ->call('openTable', 'customers')
        ->call('saveCell', rowKeyOf(1), 'name', 'Changed')
        ->call('runSql', "UPDATE customers SET name = 'Changed'")
        ->assertSee('unlock changes first');
    expect($pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn())->toBe('Ada');

    $page->call('toggleLock')->assertSee('Unlocked');
    expect($page->instance()->runSql("UPDATE customers SET name = 'Changed' WHERE id = 1"))->toHaveKey('confirm');
    expect($pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn())->toBe('Ada');

    $page->call('runSql', "UPDATE customers SET name = 'Changed' WHERE id = 1", true);
    expect($pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn())->toBe('Changed');
});

test('structure changes are SQL to review first: a new table, a column, an index, a drop', function () {
    [$admin, $connection, $path] = sqliteFixture();
    $pdo = new PDO('sqlite:'.$path);

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('startNewTable')
        ->set('newTableName', 'invoices')
        ->call('reviewNewTable')
        ->assertHasNoErrors()
        ->assertSet('reviewSql', fn (string $sql) => str_contains($sql, 'CREATE TABLE "invoices"') && str_contains($sql, 'AUTOINCREMENT'))
        ->call('applyReview')
        ->assertSet('table', 'invoices');

    expect($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'invoices'")->fetchColumn())->toBe(1);

    $page->call('startAddColumn')->set('column.name', 'total')->set('column.type', 'REAL')->set('column.default', '0')
        ->call('reviewAddColumn')->call('applyReview')
        ->call('startAddIndex')->set('indexColumns', ['total'])->call('reviewAddIndex')->call('applyReview')
        ->call('openTable', 'invoices', 'structure')
        ->assertSee('total')
        ->assertSee('invoices_total_index');

    $page->call('reviewDrop', 'invoices')->call('applyReview')->assertSet('table', null);
    expect($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'invoices'")->fetchColumn())->toBe(0);
});

test('a connection that does not answer says why, without breaking the page', function () {
    $admin = User::factory()->admin()->create();
    $connection = DatabaseConnection::factory()->for($admin)->sqlite('/nowhere/at/all.sqlite')->create();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertOk()
        ->assertSee('There is no file /nowhere/at/all.sqlite on this server.');
});

test('coming back to a connection opens where the person was in it, unless the address says otherwise', function () {
    [$admin, $connection] = sqliteFixture();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('openTable', 'customers')
        ->call('addFilter', 'name')->set('filters.0.op', 'starts')->set('filters.0.value', 'Gr')
        ->call('sortBy', 'name')->call('sortBy', 'name');

    // another page of Studio, then the connection again from the list: same table, filter and order
    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertSet('table', 'customers')
        ->assertSet('direction', 'desc')
        ->assertSet('filters.0.value', 'Gr')
        ->assertSee('grace@example.com')
        ->assertDontSee('ada@example.com');

    Livewire::withQueryParams(['table' => 'log'])->actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->assertSet('table', 'log')
        ->assertSet('filters', [])
        ->assertSee('second');

    Livewire::actingAs($admin)->test(Databases::class)
        ->assertSee('Continue in')
        ->assertSeeHtml(route('databases.client', $connection));
});
