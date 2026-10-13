<?php

namespace App\Infrastructure\Git;

use App\Infrastructure\Git\Forges\AzureDevOps;
use App\Infrastructure\Git\Forges\Bitbucket;
use App\Infrastructure\Git\Forges\GitHub;
use App\Infrastructure\Git\Forges\GitLab;
use App\Models\ConnectorSetting;
use RuntimeException;

/**
 * The git hosts, under Tools in the menu: described like infrastructure
 * connectors and run by the same page, but every account is a person's own
 * (their token, their commit identity), and everyone may add theirs to the
 * hosts an administrator turned on. The installation's own provider is on
 * until an administrator turns it off.
 */
final class Forges
{
    /** @var array<string, class-string<Forge>> by key, the same as GitProvider's */
    public const ALL = [
        'github' => GitHub::class,
        'gitlab' => GitLab::class,
        'bitbucket' => Bitbucket::class,
        'azure' => AzureDevOps::class,
    ];

    /** @return array<string, Forge> */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ALL);
    }

    public static function make(string $key): Forge
    {
        return isset(self::ALL[$key]) ? app(self::ALL[$key]) : throw new RuntimeException("Unknown git host [{$key}].");
    }

    public static function isEnabled(string $key): bool
    {
        if (! isset(self::ALL[$key])) {
            return false;
        }

        $setting = ConnectorSetting::query()->where('connector', $key)->first();

        return $setting !== null ? $setting->enabled : $key === config('studio.git.provider');
    }

    /**
     * The git hosts that are on, in the order of the menu.
     *
     * @return list<Forge>
     */
    public static function enabled(): array
    {
        $settings = ConnectorSetting::query()->whereIn('connector', array_keys(self::ALL))->pluck('enabled', 'connector');

        return array_values(array_filter(
            self::all(),
            fn (Forge $forge) => (bool) ($settings[$forge->key()] ?? $forge->key() === config('studio.git.provider')),
        ));
    }
}
