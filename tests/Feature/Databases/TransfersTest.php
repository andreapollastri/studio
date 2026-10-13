<?php

use App\Databases\Transfers\Transfers;
use App\Livewire\Tools\DatabaseClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseTransfer;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    // Studio's own writer, wherever sqlite3 happens to be installed
    config(['studio.databases.binaries.sqlite3' => '/nonexistent/sqlite3']);
});

afterEach(fn () => Cache::flush());

function transferFixture(): array
{
    $path = tempnam(sys_get_temp_dir(), 'studio-db-').'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price REAL, data BLOB)');
    $pdo->exec("INSERT INTO products VALUES (1, 'Chair', 49.5, NULL), (2, 'Desk; with ''quotes''', 199, X'00FF10'), (3, 'Lamp', NULL, NULL)");
    $admin = User::factory()->admin()->create();

    return [$admin, DatabaseConnection::factory()->for($admin)->sqlite($path)->create(), $path];
}

test('a SQL dump made by Studio puts the database back as it was', function () {
    [$admin, $connection] = transferFixture();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'transfers')
        ->set('exportFormat', 'sql')
        ->call('startExport')
        ->assertHasNoErrors()
        ->assertSee('Download');

    $export = DatabaseTransfer::query()->sole();
    $dump = Storage::disk('local')->get($export->file_path);

    expect($export->status)->toBe('success')
        ->and($export->rows)->toBe(3)
        ->and($dump)->toContain('CREATE TABLE products')
        ->and($dump)->toContain("'Desk; with ''quotes'''")
        ->and($dump)->toContain("X'00ff10'");

    // into an empty file, through the import of the client
    $copy = tempnam(sys_get_temp_dir(), 'studio-db-').'.sqlite';
    touch($copy);
    $target = DatabaseConnection::factory()->for($admin)->sqlite($copy)->create();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $target])
        ->call('setTab', 'transfers')
        ->set('importFile', UploadedFile::fake()->createWithContent('dump.sql', $dump))
        ->assertSet('importFormat', 'sql')
        ->call('startImport')
        ->assertHasNoErrors();

    $import = DatabaseTransfer::query()->where('kind', 'import')->sole();
    expect($import->status)->toBe('success', (string) $import->output);

    $rows = (new PDO('sqlite:'.$copy))->query('SELECT id, name, price, hex(data) FROM products ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    expect($rows)->toBe([[1, 'Chair', 49.5, ''], [2, "Desk; with 'quotes'", 199.0, '00FF10'], [3, 'Lamp', null, '']]);
});

test('tables go out as CSV and JSON, a zip when there are several, and a query result too', function () {
    [$admin, $connection, $path] = transferFixture();
    (new PDO('sqlite:'.$path))->exec("CREATE TABLE tags (name TEXT); INSERT INTO tags VALUES ('a'), ('b');");

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'transfers')
        ->set('exportFormat', 'csv')
        ->set('exportTables', ['products'])
        ->call('startExport');

    $csv = Storage::disk('local')->get(DatabaseTransfer::query()->latest('id')->first()->file_path);
    expect($csv)->toStartWith("id,name,price,data\n1,Chair,49.5,\n");

    $page->set('exportFormat', 'json')->set('exportTables', ['products', 'tags'])->call('startExport');
    $zip = DatabaseTransfer::query()->latest('id')->first();
    expect($zip->file_name)->toEndWith('.zip');

    $archive = new ZipArchive;
    $archive->open(Storage::disk('local')->path($zip->file_path));
    expect(json_decode($archive->getFromName('tags.json'), true))->toBe([['name' => 'a'], ['name' => 'b']]);

    $page->call('runSql', 'SELECT name FROM products WHERE price > 100')->call('exportResult', 'csv');
    expect(Storage::disk('local')->get(DatabaseTransfer::query()->latest('id')->first()->file_path))->toBe("name\n\"Desk; with 'quotes'\"\n");
});

test('CSV rows go into a table by column name, and an export reaches the drive and the download', function () {
    [$admin, $connection, $path] = transferFixture();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('openTable', 'products')
        ->call('setTab', 'transfers')
        ->set('importFile', UploadedFile::fake()->createWithContent('more.csv', "name;price;colour\nStool;20;red\nShelf;;blue\n"))
        ->assertSet('importFormat', 'csv')
        ->assertSet('importTable', 'products')
        ->call('startImport')
        ->assertHasNoErrors();

    $import = DatabaseTransfer::query()->sole();
    expect($import->status)->toBe('success', (string) $import->output)
        ->and($import->rows)->toBe(2)
        ->and($import->output)->toContain('colour');

    $rows = (new PDO('sqlite:'.$path))->query('SELECT name, price FROM products WHERE id > 3 ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    expect($rows)->toBe([['Stool', 20.0], ['Shelf', null]]);

    $page = Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'transfers')->set('exportFormat', 'csv')->set('exportTables', ['products'])->call('startExport');
    $export = DatabaseTransfer::query()->where('kind', 'export')->sole();

    $page->call('saveToDrive', $export->uuid);
    expect(DriveFile::query()->where('user_id', $admin->id)->sole()->name)->toBe($export->file_name);

    $this->actingAs($admin)->get(route('databases.download', $export))->assertOk()->assertDownload($export->file_name);
    $this->actingAs(User::factory()->create())->get(route('databases.download', $export))->assertNotFound();
});

test('a failed import says which statement, and old exports are pruned', function () {
    [$admin, $connection] = transferFixture();

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'transfers')
        ->set('importFile', UploadedFile::fake()->createWithContent('bad.sql', "INSERT INTO products (id, name) VALUES (10, 'ok');\nINSERT INTO nowhere VALUES (1);\nINSERT INTO products (id, name) VALUES (11, 'never');"))
        ->call('startImport');

    $import = DatabaseTransfer::query()->sole();
    expect($import->status)->toBe('failed')
        ->and($import->error)->toContain('statement 2')
        ->and($import->error)->toContain('no such table');

    $export = DatabaseTransfer::query()->create(['database_connection_id' => $connection->id, 'user_id' => $admin->id, 'kind' => 'export', 'format' => 'sql', 'scope' => [], 'status' => 'success', 'file_path' => 'database-transfers/x/old.sql', 'file_name' => 'old.sql', 'expires_at' => now()->subDay()]);
    Storage::disk('local')->put('database-transfers/'.$export->uuid.'/old.sql', 'x');

    app(Transfers::class)->prune();
    expect($export->fresh()->file_path)->toBeNull()->and(Storage::disk('local')->exists('database-transfers/'.$export->uuid.'/old.sql'))->toBeFalse();
});

test('a person who may only read cannot import', function () {
    [$admin, $connection] = transferFixture();
    $connection->update(['environment' => 'production']);

    Livewire::actingAs($admin)->test(DatabaseClient::class, ['connection' => $connection])
        ->call('setTab', 'transfers')
        ->set('importFile', UploadedFile::fake()->createWithContent('x.sql', 'DELETE FROM products;'))
        ->call('startImport');

    expect(DatabaseTransfer::query()->exists())->toBeFalse();
});
