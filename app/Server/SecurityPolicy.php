<?php

namespace App\Server;

use App\Models\Setting;
use App\Models\User;

/**
 * What the installation demands of its accounts (Administration → System → Security):
 * who must have two-factor authentication set up before they use Studio. Off by
 * default; "admins" for the administrators, who see and run everything; "all" for
 * every account. A person who must and has not yet is sent to Settings → Security
 * and nowhere else (RequireTwoFactor).
 */
final class SecurityPolicy
{
    public const OFF = 'off';

    public const ADMINS = 'admins';

    public const ALL = 'all';

    private const TWO_FACTOR = 'security.two_factor';

    /** @var self::OFF|self::ADMINS|self::ALL|null */
    private ?string $twoFactor = null;

    /** @return self::OFF|self::ADMINS|self::ALL */
    public function twoFactor(): string
    {
        return $this->twoFactor ??= self::normalize(Setting::get(self::TWO_FACTOR, self::OFF));
    }

    /** @return self::OFF|self::ADMINS|self::ALL the policy as kept */
    public function setTwoFactor(string $policy): string
    {
        $policy = self::normalize($policy);
        Setting::put(self::TWO_FACTOR, $policy);

        return $this->twoFactor = $policy;
    }

    public function requiresTwoFactor(User $user): bool
    {
        return match ($this->twoFactor()) {
            self::ALL => true,
            self::ADMINS => $user->isAdmin(),
            default => false,
        };
    }

    /** Required of this person, and not set up (confirmed) yet. */
    public function mustSetUpTwoFactor(User $user): bool
    {
        return $this->requiresTwoFactor($user) && $user->two_factor_confirmed_at === null;
    }

    /** @return array<string, string> policy => label */
    public static function options(): array
    {
        return [
            self::OFF => __('Optional'),
            self::ADMINS => __('Administrators'),
            self::ALL => __('Everyone'),
        ];
    }

    /** @return self::OFF|self::ADMINS|self::ALL */
    private static function normalize(mixed $policy): string
    {
        return in_array($policy, [self::ADMINS, self::ALL], true) ? $policy : self::OFF;
    }
}
