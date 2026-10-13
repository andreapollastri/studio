<?php

namespace App\Infrastructure\Git;

use App\Infrastructure\Git\Forges\AzureDevOps;
use App\Infrastructure\Git\Forges\Bitbucket;
use App\Infrastructure\Git\Forges\GitHub;
use App\Infrastructure\Git\Forges\GitLab;
use RuntimeException;

/**
 * The git hosts: described like infrastructure connectors and run by the same
 * page (Tools → Git), but every account is a person's own (their token, their
 * commit identity) or, for deploying project sites, a service account of the
 * team. All four are there; a project's repository may live on any of them.
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

    /** Every git host is there for everyone; a host without accounts simply has nothing to show. */
    public static function isEnabled(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /**
     * The git hosts, in the order of the menu.
     *
     * @return list<Forge>
     */
    public static function enabled(): array
    {
        return array_values(self::all());
    }
}
