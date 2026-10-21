<?php

namespace App\Server;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The browser's own sign-in (HTTP basic authentication) at a site's public address: the email
 * and password of a Studio account, checked here, so that the client's domain never shows
 * Studio's login page. Who may then open the site is SiteAccess's answer (a member of the
 * project or an administrator).
 *
 * As the login form: five tries a minute on one account from one address, twenty from one
 * address, every refusal in the security log for fail2ban. A browser sends the credentials
 * with every request of the page: a good pair is remembered for a while, so that the password
 * hash is not computed for each picture.
 */
final class SiteBasicAuth
{
    /** How long a checked pair is remembered, in seconds: a new password, or a removed account, ends it at once. */
    private const REMEMBER = 600;

    /** The header Caddy is told to drop the credentials on: the app behind never sees a Studio password. */
    public const STRIP_HEADER = 'X-Studio-Strip-Auth';

    public function user(Request $request, string $host, string $ip): ?User
    {
        $email = Str::lower(trim((string) $request->getUser()));
        $password = (string) $request->getPassword();

        if ($email === '' || $password === '') {
            return null;
        }

        $perAccount = 'site-basic|'.$email.'|'.$ip;
        $perAddress = 'site-basic|'.$ip;

        if (RateLimiter::tooManyAttempts($perAccount, (int) config('studio.security.login_per_minute', 5))
            || RateLimiter::tooManyAttempts($perAddress, (int) config('studio.security.login_per_ip_per_minute', 20))) {
            SecurityLog::record('login.locked', ['email' => $email, 'host' => $host, 'via' => 'basic', 'ip' => $ip], 'warning');

            return null;
        }

        $user = User::query()->where('email', $email)->first();
        $remembered = $user ? 'site-basic-ok|'.hash('sha256', $user->id.'|'.$password.'|'.$user->password) : null;

        if ($user && $remembered && Cache::get($remembered) === $user->id) {
            return $user;
        }

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            RateLimiter::hit($perAccount);
            RateLimiter::hit($perAddress);
            SecurityLog::record('login.failed', ['email' => $email, 'host' => $host, 'via' => 'basic', 'ip' => $ip], 'warning');

            return null;
        }

        RateLimiter::clear($perAccount);
        Cache::put((string) $remembered, $user->id, self::REMEMBER);

        return $user;
    }
}
