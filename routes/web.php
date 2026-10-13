<?php

use App\Http\Controllers\DriveFileController;
use App\Http\Controllers\SiteLoginController;
use App\Livewire\Admin\Projects as AdminProjects;
use App\Livewire\Admin\System as AdminSystem;
use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Ssh\Terminal as SshTerminal;
use App\Livewire\Studio\Shell;
use App\Livewire\Tools\Drive;
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

    // Sessions from this server to any host: asks for the password again, as Settings → Security does.
    Route::get('ssh', SshTerminal::class)->middleware(['can:ssh', 'password.confirm'])->name('ssh');
    Route::get('passwords', Vault::class)->middleware('password.confirm')->name('vault');
    Route::get('drive', Drive::class)->name('drive');
    Route::get('drive/files/{file}', DriveFileController::class)->name('drive.file');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('projects', AdminProjects::class)->name('projects');
        Route::get('users', AdminUsers::class)->name('users');
        Route::get('system', AdminSystem::class)->name('system');
    });
});

require __DIR__.'/settings.php';
