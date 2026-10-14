<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

/** Everything about signing in and the second factor goes to the security log (SecurityLog). */
class RecordSecurityEvents
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            Logout::class => 'logout',
            OtherDeviceLogout::class => 'otherDevicesLogout',
            PasswordReset::class => 'passwordReset',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            ValidTwoFactorAuthenticationCodeProvided::class => 'twoFactorPassed',
            RecoveryCodeReplaced::class => 'recoveryCodeUsed',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
            PasskeyRegistered::class => 'passkeyRegistered',
            PasskeyVerified::class => 'passkeyVerified',
            PasskeyDeleted::class => 'passkeyDeleted',
        ];
    }

    public function login(Login $event): void
    {
        SecurityLog::record('login.ok', ['user' => self::who($event->user), 'remember' => (bool) $event->remember]);
    }

    public function failed(Failed $event): void
    {
        $email = Str::limit(Str::lower(trim((string) ($event->credentials['email'] ?? ''))), 120, '');

        SecurityLog::record('login.failed', ['email' => $email, 'known' => $event->user !== null], 'warning');
    }

    public function lockout(Lockout $event): void
    {
        SecurityLog::record('login.locked', ['email' => Str::limit((string) $event->request->input('email'), 120, '')], 'warning');
    }

    public function logout(Logout $event): void
    {
        SecurityLog::record('logout', ['user' => self::who($event->user)]);
    }

    public function otherDevicesLogout(OtherDeviceLogout $event): void
    {
        SecurityLog::record('sessions.others-closed', ['user' => self::who($event->user)], 'notice');
    }

    public function passwordReset(PasswordReset $event): void
    {
        SecurityLog::record('password.reset', ['user' => self::who($event->user)], 'notice');
    }

    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        SecurityLog::record('2fa.enabled', ['user' => self::who($event->user)], 'notice');
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        SecurityLog::record('2fa.confirmed', ['user' => self::who($event->user)], 'notice');
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        SecurityLog::record('2fa.disabled', ['user' => self::who($event->user)], 'warning');
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        SecurityLog::record('2fa.failed', ['user' => self::who($event->user)], 'warning');
    }

    public function twoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        SecurityLog::record('2fa.ok', ['user' => self::who($event->user)]);
    }

    public function recoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        SecurityLog::record('2fa.recovery-code-used', ['user' => self::who($event->user)], 'notice');
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        SecurityLog::record('2fa.recovery-codes-generated', ['user' => self::who($event->user)], 'notice');
    }

    public function passkeyRegistered(PasskeyRegistered $event): void
    {
        SecurityLog::record('passkey.registered', ['user' => self::who($event->user), 'passkey' => $event->passkey->name], 'notice');
    }

    public function passkeyVerified(PasskeyVerified $event): void
    {
        SecurityLog::record('passkey.ok', ['user' => self::who($event->user), 'passkey' => $event->passkey->name]);
    }

    public function passkeyDeleted(PasskeyDeleted $event): void
    {
        SecurityLog::record('passkey.deleted', ['user' => self::who($event->user), 'passkey' => $event->passkey->name], 'notice');
    }

    private static function who(mixed $user): ?string
    {
        return match (true) {
            $user instanceof User => $user->email,
            $user instanceof Authenticatable => (string) $user->getAuthIdentifier(),
            default => null,
        };
    }
}
