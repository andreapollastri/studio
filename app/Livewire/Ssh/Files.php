<?php

namespace App\Livewire\Ssh;

use App\Files\FileStore;
use App\Files\FileStoreException;
use App\Livewire\Concerns\ManagesFiles;
use App\Models\SshConnection;
use App\Ssh\SftpFiles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The file manager of a connection on the SSH page, in place of its terminal:
 * the same tree, editor, uploads, moves and zips as a project's Files tab,
 * over SFTP with the person's own key or password (SftpFiles). The tree starts
 * at their home on the server; the path bar above it goes anywhere else.
 */
class Files extends Component
{
    use ManagesFiles;
    use WithFileUploads;

    /** The uuid of the connection. */
    #[Locked]
    public string $connection;

    /** The folder of the server the tree shows; '' until the server said where home is. */
    #[Locked]
    public string $root = '';

    /** What the path bar holds. */
    public string $goTo = '';

    private ?SftpFiles $store = null;

    private ?SshConnection $model = null;

    public function mount(string $connection): void
    {
        $this->connection = $connection;
        $this->sshConnection();
        $this->load('');
    }

    /** The folder typed in the path bar (absolute, ~ or ~/…) becomes the top of the tree. */
    public function go(): void
    {
        try {
            $dir = $this->sftp()->resolveDir($this->goTo);
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($dir === null) {
            $this->error = __(':path is not a folder on :host.', ['path' => $this->goTo, 'host' => $this->sshConnection()->host]);

            return;
        }

        $this->root = $dir;
        $this->goTo = $dir;
        $this->store = null;
        $this->reset(['dirs', 'expanded', 'creating', 'renaming', 'name', 'unopenable', 'notice', 'moving']);
        $this->dispatch('files-reset')->self();
        $this->load('');
    }

    public function up(): void
    {
        $this->goTo = $this->root === '' ? '~' : dirname($this->root);
        $this->go();
    }

    public function home(): void
    {
        $this->goTo = '~';
        $this->go();
    }

    public function downloadUrl(string $path): string
    {
        return route('ssh.files.download', ['connection' => $this->connection, 'path' => rtrim($this->root, '/').'/'.$path]);
    }

    public function rootLabel(): string
    {
        return $this->root === '' ? '/' : $this->root;
    }

    public function editorScope(): string
    {
        return $this->connection;
    }

    protected function files(): FileStore
    {
        return $this->sftp();
    }

    /** The first time the server is reached, the tree starts at the person's home there. */
    protected function unavailable(): ?string
    {
        if ($this->root === '') {
            try {
                $this->root = $this->goTo = $this->sftp()->root();
            } catch (FileStoreException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    private function sftp(): SftpFiles
    {
        return $this->store ??= app(SftpFiles::class, [
            'connection' => $this->sshConnection(),
            'user' => auth()->user(),
            'root' => $this->root,
        ]);
    }

    /** The connection, while this person may still use SSH and see it. */
    private function sshConnection(): SshConnection
    {
        abort_unless(auth()->user()?->canUseSsh(), 403);

        return $this->model ??= SshConnection::query()->visibleTo(auth()->user())->with('key')->where('uuid', $this->connection)->firstOrFail();
    }

    public function placeholder(): string
    {
        return '<div class="flex h-full items-center justify-center gap-2 text-sm text-zinc-500"><span class="size-1.5 animate-pulse rounded-full bg-amber-400"></span>'.e(__('Opening SFTP…')).'</div>';
    }

    public function render(): View
    {
        return view('livewire.files.manager', ['bar' => 'livewire.ssh.partials.files-bar', 'treeWidth' => 'w-64']);
    }
}
