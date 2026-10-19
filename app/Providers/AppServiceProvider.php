<?php

namespace App\Providers;

use App\Infrastructure\Connectors;
use App\Listeners\RecordSecurityEvents;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        $this->configureAccounts();
    }

    /**
     * What every account's password must be, and the security log of everything about
     * signing in. How long "remember me" lasts is the web guard's `remember` (config/auth.php):
     * resolving the guard here would build the encrypted session store before the installer
     * has generated APP_KEY, and every artisan command would fail.
     */
    private function configureAccounts(): void
    {
        // Long, and not in the lists of leaked passwords (Have I Been Pwned's range API: five
        // characters of the hash leave the server, never the password). The check needs the
        // network: the tests do without it.
        Password::defaults(function () {
            $password = Password::min((int) config('studio.security.password_min', 12));

            return app()->runningUnitTests() ? $password : $password->uncompromised();
        });

        Event::subscribe(RecordSecurityEvents::class);
    }
}
