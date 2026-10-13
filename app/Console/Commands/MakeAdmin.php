<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Git\Providers\GitProvider;
use App\Infrastructure\Git\Forges;
use App\Models\ConnectorAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The first account. Studio is invite-only, so somebody has to exist before
 * the admin pages can invite anyone else. The installer calls this with every
 * option set; by hand it asks.
 */
class MakeAdmin extends Command
{
    protected $signature = 'studio:make-admin {--name=} {--email=} {--password=} {--git-token= : a token for the installation\'s git provider}';

    protected $description = 'Create (or promote) an administrator account for Studio';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = Str::lower($this->option('email') ?: text('Email', required: true, validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email'));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $password = $this->option('password') ?: (password('Password (empty = generated)') ?: Str::password(16, symbols: false));

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ]);

            $this->components->info("Administrator created: {$email}");
            $this->line("Password: <comment>{$password}</comment>");
        } else {
            $user->forceFill(['role' => UserRole::Admin])->save();
            $this->components->info("{$user->email} is now an administrator.");
        }

        if ($token = $this->option('git-token')) {
            // the host the installer asked for, with the administrator's token there
            $git = GitProvider::make((string) config('studio.git.provider', 'github'), config('studio.git.url'));

            try {
                $who = $git->whoAmI($token);
                $credentials = array_filter(['token' => $token, 'url' => in_array($git->key(), ['github', 'bitbucket'], true) ? null : $git->baseUrl()]);

                // their first git account, on the installation's host, with their identity
                ConnectorAccount::query()->updateOrCreate(
                    ['user_id' => $user->id, 'connector' => $git->key(), 'name' => $who['login']],
                    [
                        'login' => $who['login'],
                        'host' => Forges::make($git->key())->host($credentials),
                        'git_name' => $user->name,
                        'git_email' => $user->email,
                        'credentials' => $credentials,
                        'status' => 'ok · '.$who['login'],
                        'checked_at' => now(),
                    ],
                );
                $user->forceFill(['handle' => $user->handle ?: User::handleFromLogin($who['login'])])->save();
                $this->components->info("{$git->label()} connected as {$who['login']}.");
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
