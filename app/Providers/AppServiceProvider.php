<?php

namespace App\Providers;

use App\Infrastructure\Connectors;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('ssh', fn (User $user) => $user->canUseSsh());
        Gate::define('infrastructure', fn (User $user, string $connector) => Connectors::allows($user, $connector));
    }
}
