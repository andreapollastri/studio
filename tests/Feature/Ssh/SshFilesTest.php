<?php

use App\Livewire\Ssh\Files;
use App\Livewire\Ssh\Terminal;
use App\Models\SshConnection;
use App\Models\User;
use App\Ssh\RemoteFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use phpseclib3\Net\SFTP;
use Tests\Support\LocalSftp;

/** A home on "the server" (a temporary folder here), with the session that serves it to SftpFiles. */
function sftpHome(): string
{
    $home = realpath(sys_get_temp_dir()).'/studio-sftp-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/app/Models');
    file_put_contents($home.'/README.md', "# Hello\n");
    file_put_contents($home.'/app/Models/Order.php', "<?php\n\nclass Order {}\n");
    file_put_contents($home.'/logo.png', "\x89PNG\x00\x01");

    $sftp = new LocalSftp($home);
    app()->instance(RemoteFiles::class, new class($sftp) extends RemoteFiles
    {
        public function __construct(private readonly SFTP $sftp) {}

        public function open(SshConnection $connection, User $user): SFTP
        {
            return $this->sftp;
        }
    });

    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($home));

    return $home;
}

function trustedConnection(User $user): SshConnection
{
    return SshConnection::factory()->create(['user_id' => $user->id, 'host_key' => 'SHA256:'.str_repeat('a', 43)]);
}

test('a session turns into the file manager only with a credential and an accepted host key', function () {
    $user = User::factory()->create();
    $unknown = SshConnection::factory()->create(['user_id' => $user->id]);
    $trusted = trustedConnection($user);
    $stranger = trustedConnection(User::factory()->create());

    Livewire::actingAs($user)->test(Terminal::class)
        ->call('browse', $unknown->uuid)
        ->assertReturned(false)
        ->assertSet('browsing', [])
        ->call('browse', $trusted->uuid)
        ->assertReturned(true)
        ->call('browse', $trusted->uuid)
        ->assertSet('browsing', [$trusted->uuid])
        ->assertSeeHtml('ssh-files-pane-'.$trusted->uuid);

    Livewire::actingAs($user)->test(Terminal::class)->call('browse', $stranger->uuid)->assertNotFound();
    Livewire::actingAs($user)->test(Files::class, ['connection' => $stranger->uuid])->assertNotFound();
});

test('the tree starts at the home on the server; text files open in the editor and save while nobody changed them', function () {
    $user = User::factory()->create();
    $connection = trustedConnection($user);
    $home = sftpHome();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['connection' => $connection->uuid])
        ->assertSet('error', null)
        ->assertSet('root', $home)
        ->assertSee(['app', 'README.md', 'logo.png'])
        ->call('toggle', 'app')
        ->call('toggle', 'app/Models')
        ->assertSee('Order.php')
        ->call('open', 'README.md')
        ->assertDispatched('files-open', path: 'README.md', content: "# Hello\n", hash: sha1("# Hello\n"))
        ->call('open', 'logo.png')
        ->assertSee('logo.png is a binary file');

    $saved = $component->call('save', 'README.md', base64_encode("# Bye\n"), sha1("# Hello\n"))->effects['returns'][0];
    expect($saved)->toBe(['ok' => true, 'hash' => sha1("# Bye\n")])
        ->and(file_get_contents($home.'/README.md'))->toBe("# Bye\n");

    // changed on the server meanwhile: nothing is written
    $stale = $component->call('save', 'README.md', base64_encode('mine'), sha1("# Hello\n"))->effects['returns'][0];
    expect($stale['ok'])->toBeFalse()
        ->and($stale['error'])->toContain('changed on the server')
        ->and(file_get_contents($home.'/README.md'))->toBe("# Bye\n");
});

test('files and folders are created, renamed, moved, uploaded, zipped, extracted and deleted on the server', function () {
    $user = User::factory()->create();
    $connection = trustedConnection($user);
    $home = sftpHome();

    $component = Livewire::actingAs($user)
        ->test(Files::class, ['connection' => $connection->uuid])
        ->call('startCreating', '', 'dir')
        ->set('name', 'docs')
        ->call('create')
        ->call('startCreating', 'docs', 'file')
        ->set('name', 'notes.txt')
        ->call('create')
        ->assertDispatched('files-open', path: 'docs/notes.txt')
        ->call('startRenaming', 'docs/notes.txt')
        ->set('name', 'todo.txt')
        ->call('rename')
        ->assertDispatched('files-renamed', oldPath: 'docs/notes.txt', newPath: 'docs/todo.txt')
        ->call('moveInto', 'README.md', 'docs')
        ->assertReturned(true)
        ->assertSet('notice', 'Moved README.md to /docs.');

    expect(is_file($home.'/docs/todo.txt'))->toBeTrue()
        ->and(is_file($home.'/docs/README.md'))->toBeTrue()
        ->and(file_exists($home.'/README.md'))->toBeFalse();

    // a folder's tree is kept; a file already there waits for Replace
    $component->call('prepareUpload', 'docs', ['site/index.html', 'todo.txt'])
        ->assertReturned(true)
        ->set('uploads', [UploadedFile::fake()->createWithContent('index.html', '<h1>hi</h1>'), UploadedFile::fake()->createWithContent('todo.txt', 'new')])
        ->assertSet('notice', '1 file uploaded.')
        ->assertSee('1 file is already there');

    expect(file_get_contents($home.'/docs/site/index.html'))->toBe('<h1>hi</h1>')
        ->and(file_get_contents($home.'/docs/todo.txt'))->toBe('');

    $component->call('replaceClashes')->assertSet('clashes', []);
    expect(file_get_contents($home.'/docs/todo.txt'))->toBe('new');

    $component->call('compress', 'docs')
        ->assertSet('error', null)
        ->assertSet('notice', 'Compressed into docs.zip.')
        ->call('extract', 'docs.zip')
        ->assertSet('error', null)
        ->assertSet('notice', 'Extracted into docs 2.');

    expect(file_get_contents($home.'/docs 2/site/index.html'))->toBe('<h1>hi</h1>')
        ->and(glob($home.'/.docs.zip.studio-tmp-*'))->toBe([]);

    $component->call('delete', 'docs 2')
        ->assertDispatched('files-deleted', path: 'docs 2');
    expect(file_exists($home.'/docs 2'))->toBeFalse();

    // nothing climbs above the folder the tree shows
    $component->call('delete', '../etc')->assertSee('Paths stay under the folder the tree shows.');
});

test('the path bar shows another folder of the server, and refuses one that is not there', function () {
    $user = User::factory()->create();
    $connection = trustedConnection($user);
    $home = sftpHome();

    Livewire::actingAs($user)
        ->test(Files::class, ['connection' => $connection->uuid])
        ->call('toggle', 'app')
        ->set('goTo', $home.'/app')
        ->call('go')
        ->assertSet('root', $home.'/app')
        ->assertSet('expanded', [])
        ->assertDispatched('files-reset')
        ->assertSee('Models')
        ->assertDontSee('README.md')
        ->call('up')
        ->assertSet('root', $home)
        ->set('goTo', $home.'/nowhere')
        ->call('go')
        ->assertSet('root', $home)
        ->assertSee('is not a folder')
        ->set('goTo', '~/app')
        ->call('go')
        ->assertSet('root', $home.'/app')
        ->call('home')
        ->assertSet('root', $home);
});

test('without an accepted host key the file manager says where to accept it', function () {
    $user = User::factory()->create();
    $connection = SshConnection::factory()->create(['user_id' => $user->id, 'name' => 'web']);

    Livewire::actingAs($user)
        ->test(Files::class, ['connection' => $connection->uuid])
        ->assertSee('Open web once in the SSH terminal to accept its host key first.');
});

test('a file downloads over SFTP for whoever sees the connection; a folder or a stranger gets a 404', function () {
    $user = User::factory()->create();
    $connection = trustedConnection($user);
    $home = sftpHome();

    $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('ssh.files.download', ['connection' => $connection->uuid, 'path' => $home.'/logo.png']));
    $response->assertOk()->assertDownload('logo.png');
    expect($response->streamedContent())->toBe("\x89PNG\x00\x01");

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('ssh.files.download', ['connection' => $connection->uuid, 'path' => $home.'/app']))->assertNotFound();
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('ssh.files.download', ['connection' => $connection->uuid, 'path' => $home.'/logo.png']))->assertNotFound();
    $this->actingAs(User::factory()->client()->create())->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('ssh.files.download', ['connection' => $connection->uuid, 'path' => $home.'/logo.png']))->assertForbidden();
});
