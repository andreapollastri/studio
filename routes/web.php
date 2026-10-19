<?php

use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\ConversationImageController;
use App\Http\Controllers\DatabaseTransferController;
use App\Http\Controllers\DoorController;
use App\Http\Controllers\DriveFileController;
use App\Http\Controllers\SiteLoginController;
use App\Http\Controllers\WorkspaceFileController;
use App\Infrastructure\Git\Forges;
use App\Livewire\Admin\Connectors as AdminConnectors;
use App\Livewire\Admin\Domains as AdminDomains;
use App\Livewire\Admin\Projects as AdminProjects;
use App\Livewire\Admin\System as AdminSystem;
use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Home\Inbox;
use App\Livewire\Infrastructure\Console as InfrastructureConsole;
use App\Livewire\Projects\Access as ProjectAccess;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Infrastructure as ProjectInfrastructure;
use App\Livewire\Projects\Repository as ProjectRepository;
use App\Livewire\Projects\Settings as ProjectSettings;
use App\Livewire\Ssh\Terminal as SshTerminal;
use App\Livewire\Studio\Shell;
use App\Livewire\Tools\Backups;
use App\Livewire\Tools\DatabaseClient;
use App\Livewire\Tools\Databases;
use App\Livewire\Tools\Drive;
use App\Livewire\Tools\Git;
use App\Livewire\Tools\RedisClient;
use App\Livewire\Tools\Vault;
use Illuminate\Support\Facades\Route;

// The way in when the dashboard is hidden (Administration → System): leaves a cookie, opens the sign-in page.
Route::get('door/{key}', DoorController::class)->middleware('throttle:20,1')->name('door');

Route::middleware(['auth', 'verified'])->group(function () {
    // What waits for the person now, across every project and git account.
    Route::get('/', Inbox::class)->name('home');
    Route::redirect('dashboard', '/')->name('dashboard');

    Route::get('projects', ProjectsIndex::class)->name('projects.index');
    // A protected project host sends visitors here; members go back with a one-time token.
    Route::get('site-login', SiteLoginController::class)->name('site.login');
    Route::get('p/{project:slug}', Shell::class)->name('studio.show');
    Route::get('p/{project:slug}/c/{conversation}', Shell::class)->name('studio.conversation');
    Route::get('p/{project:slug}/c/{conversation}/images/{file}', ConversationImageController::class)->name('studio.conversation.image');
    // A file of a workspace, from its Files tab
    Route::get('workspaces/{workspace:uuid}/download', WorkspaceFileController::class)->name('studio.files.download');
    // The rest of a project: its repository with the person's own account, the infrastructure and
    // the access attached to it for their role, its settings for the administrators.
    Route::get('p/{project:slug}/repository', ProjectRepository::class)->name('projects.repository');
    Route::get('p/{project:slug}/infrastructure', ProjectInfrastructure::class)->name('projects.infrastructure');
    Route::get('p/{project:slug}/access', ProjectAccess::class)->name('projects.access');
    Route::get('p/{project:slug}/backups', Backups::class)->middleware('can:admin')->name('projects.backups');
    Route::get('p/{project:slug}/settings', ProjectSettings::class)->name('projects.settings');

    // Sessions from this server to any host: asks for the password again, as Settings → Security does.
    Route::get('ssh', SshTerminal::class)->middleware(['can:ssh', 'password.confirm'])->name('ssh');
    Route::get('passwords', Vault::class)->middleware('password.confirm')->name('vault');
    Route::get('drive', Drive::class)->name('drive');
    // MySQL, MariaDB, PostgreSQL, SQLite, Redis and Valkey, direct or through SSH: the password again, as for the SSH terminal.
    Route::middleware('password.confirm')->group(function () {
        Route::get('databases', Databases::class)->name('databases');
        Route::get('databases/{connection}', DatabaseClient::class)->name('databases.client');
        Route::get('databases/{connection}/redis', RedisClient::class)->name('databases.redis');
    });
    Route::get('databases/exports/{transfer}', DatabaseTransferController::class)->name('databases.download');
    // Every backup: SSH and S3, any direction, on schedules, with their snapshots; for the administrators.
    Route::get('backups', Backups::class)->middleware('can:admin')->name('backups');
    Route::get('drive/files/{file}', DriveFileController::class)->name('drive.file');
    // Git hosts, each person with their own accounts.
    Route::get('git/{connector?}', Git::class)->whereIn('connector', array_keys(Forges::ALL))->name('git');

    // Clouds and services driven through their APIs: the password again, as for the SSH terminal.
    Route::get('infrastructure/{connector}', InfrastructureConsole::class)
        ->middleware(['can:infrastructure,connector', 'password.confirm'])
        ->name('infrastructure');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('projects', AdminProjects::class)->name('projects');
        Route::get('users', AdminUsers::class)->name('users');
        Route::get('connectors', AdminConnectors::class)->name('connectors');
        Route::get('domains', AdminDomains::class)->name('domains');
        Route::get('system', AdminSystem::class)->name('system');
        Route::get('system/status', SystemStatusController::class)->name('system.status');
    });
});

require __DIR__.'/settings.php';
