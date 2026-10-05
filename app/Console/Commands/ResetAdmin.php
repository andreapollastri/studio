<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The way back in for whoever holds root on the server. Studio has no "forgot
 * my password" for the last administrator, no way to switch off a lost 2FA
 * device, and an administrator can lock themselves out by demoting the only
 * other one. Root can always reset the main administrator from a shell:
 * `studio-recover admin` on the server runs this.
 *
 * The main administrator is the oldest account with the admin role (the one
 * the installer created), unless --email names another account; an unknown
 * email becomes a new administrator, so a server whose every account is gone
 * is recoverable too. The account gets a new password, its 2FA removed, every
 * session closed, the admin role and a verified email. Git and Claude
 * credentials stay: they are the person's, not the lock.
 */
class ResetAdmin extends Command
{
    protected $signature = 'studio:reset-admin
        {--email= : the account to reset; the oldest administrator when omitted, created when unknown}
        {--name= : the name of the account when it has to be created}
        {--password= : the new password; generated when omitted}
        {--json : print the outcome as JSON instead of text}';

    protected $description = 'Reset the main administrator: new password, 2FA off, sessions closed, admin role';

    public function handle(): int
    {
        $email = Str::lower((string) $this->option('email'));

        $user = $email === ''
            ? User::query()->where('role', UserRole::Admin)->orderBy('id')->first()
            : User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null && $email === '') {
            $this->components->error('There is no administrator to reset: name the account with --email, and it is created.');

            return self::FAILURE;
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("\"{$email}\" is not an email address.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(20, symbols: false));
        $created = $user === null;

        if ($created) {
            $user = User::query()->create([
                'name' => $this->option('name') ?: Str::headline(Str::before($email, '@')),
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->forceFill(['role' => UserRole::Admin])->save();
            $user->resetAccess($password);
        }

        Log::warning('Administrator reset from the server shell.', ['user_id' => $user->id, 'email' => $user->email, 'created' => $created]);

        if ($this->option('json')) {
            $this->line((string) json_encode(['email' => $user->email, 'password' => $password, 'created' => $created], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info($created ? "Administrator created: {$user->email}" : "Administrator reset: {$user->email} (2FA off, every session closed)");
        $this->line("Password: <comment>{$password}</comment>");

        return self::SUCCESS;
    }
}
