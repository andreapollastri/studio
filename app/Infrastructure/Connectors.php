<?php

namespace App\Infrastructure;

use App\Infrastructure\Connectors\Aws;
use App\Infrastructure\Connectors\Boogle;
use App\Infrastructure\Connectors\Cipi;
use App\Infrastructure\Connectors\Cloudflare;
use App\Infrastructure\Connectors\DigitalOcean;
use App\Infrastructure\Connectors\Hackly;
use App\Infrastructure\Connectors\Hetzner;
use App\Models\ConnectorSetting;
use App\Models\User;
use RuntimeException;

/** Every infrastructure connector Studio knows, in the order of the menu. */
final class Connectors
{
    /** @var array<string, class-string<Connector>> */
    public const ALL = [
        'aws' => Aws::class,
        'digitalocean' => DigitalOcean::class,
        'hetzner' => Hetzner::class,
        'cloudflare' => Cloudflare::class,
        'cipi' => Cipi::class,
        'boogle' => Boogle::class,
        'hackly' => Hackly::class,
    ];

    /** @return array<string, Connector> */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ALL);
    }

    public static function find(string $key): ?Connector
    {
        return isset(self::ALL[$key]) ? app(self::ALL[$key]) : null;
    }

    public static function make(string $key): Connector
    {
        return self::find($key) ?? throw new RuntimeException("Unknown connector [{$key}].");
    }

    public static function allows(User $user, string $key): bool
    {
        return isset(self::ALL[$key]) && ConnectorSetting::for($key)->allows($user);
    }

    /**
     * The enabled connectors this person may use: the Infrastructure menu.
     *
     * @return list<Connector>
     */
    public static function visibleTo(User $user): array
    {
        $settings = ConnectorSetting::query()->where('enabled', true)->get()->keyBy('connector');

        return array_values(array_filter(
            self::all(),
            fn (Connector $connector) => (bool) $settings->get($connector->key())?->allows($user),
        ));
    }
}
