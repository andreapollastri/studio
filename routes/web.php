<?php

use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\ConversationImageController;
use App\Http\Controllers\DriveFileController;
use App\Http\Controllers\SiteLoginController;
use App\Infrastructure\Git\Forges;
use App\Livewire\Admin\Connectors as AdminConnectors;
use App\Livewire\Admin\Projects as AdminProjects;
use App\Livewire\Admin\System as AdminSystem;
use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Infrastructure\Console as InfrastructureConsole;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Ssh\Terminal as SshTerminal;
use App\Livewire\Studio\Shell;
use App\Livewire\Tools\Drive;
use App\Livewire\Tools\Git;
use App\Livewire\Tools\Vault;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projects')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/projects')->name('dashboard');

    Route::get('projects', ProjectsIndex::class)->name('projects.index');
    // A protected project host sends visitors here; members go back with a one-time token.
    Route::get('site-login', SiteLoginController::class)->name('site.login');
    Route::get('p/{project:slug}', Shell::class)->name('studio.show');
    Route::get('p/{project:slug}/c/{conversation}', Shell::class)->name('studio.conversation');
    Route::get('p/{project:slug}/c/{conversation}/images/{file}', ConversationImageController::class)->name('studio.conversation.image');

    // Sessions from this server to any host: asks for the password again, as Settings → Security does.
    Route::get('ssh', SshTerminal::class)->middleware(['can:ssh', 'password.confirm'])->name('ssh');
    Route::get('passwords', Vault::class)->middleware('password.confirm')->name('vault');
    Route::get('drive', Drive::class)->name('drive');
    Route::get('drive/files/{file}', DriveFileController::class)->name('drive.file');
    // Git hosts, each person with their own accounts.
    Route::get('git/{connector}', Git::class)->whereIn('connector', array_keys(Forges::ALL))->name('git');

    // Clouds and services driven through their APIs: the password again, as for the SSH terminal.
    Route::get('infrastructure/{connector}', InfrastructureConsole::class)
        ->middleware(['can:infrastructure,connector', 'password.confirm'])
        ->name('infrastructure');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('projects', AdminProjects::class)->name('projects');
        Route::get('users', AdminUsers::class)->name('users');
        Route::get('connectors', AdminConnectors::class)->name('connectors');
        Route::get('system', AdminSystem::class)->name('system');
        Route::get('system/status', SystemStatusController::class)->name('system.status');
    });
});

require __DIR__.'/settings.php';
