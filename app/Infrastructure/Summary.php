<?php

namespace App\Infrastructure;

use App\Infrastructure\Cipi\CipiApp;
use App\Models\ProjectResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Throwable;

/**
 * One attached resource at a glance, for the project's overview: its row of the connector's
 * list read with the administrators' account (the same columns the list shows), and what
 * matters most about each kind: a Cipi app's release, server load, disk and agent; a zone's
 * records; a Boogle project's open exceptions; a Hackly target's findings; a bucket's size.
 * Read once a minute at most.
 *
 * @phpstan-type Card array{title: string, subtitle: string, icon: string, connector: string, status: array{label: string, color: string}|null, facts: array<string, string>, meters: list<array{label: string, percent: float, text: string}>, alerts: list<string>, error: string|null}
 */
final class Summary
{
    private const FRESH = 60;

    /** @return Card */
    public static function of(ProjectResource $resource): array
    {
        $account = $resource->account;

        return decrypt(Cache::remember($account->cacheKey('summary', $resource->uuid), self::FRESH, fn () => encrypt(self::read($resource))));
    }

    public static function forget(ProjectResource $resource): void
    {
        $resource->account->forget();
    }

    /** @return Card */
    private static function read(ProjectResource $resource): array
    {
        $connector = $resource->connector();
        $section = $resource->definition();
        $card = [
            'title' => $resource->label,
            'subtitle' => ($connector?->label() ?? '?').' · '.($section?->label ?? $resource->section),
            'icon' => $section?->icon ?? $connector?->icon() ?? 'squares-2x2',
            'connector' => $connector?->key() ?? '',
            'status' => null,
            'facts' => [],
            'meters' => [],
            'alerts' => [],
            'error' => null,
        ];

        if ($connector === null || $section === null || $section->list === null) {
            return ['error' => __('This connector is gone.')] + $card;
        }

        try {
            $api = $resource->account->client();
            $rows = ($section->list)($api, (array) ($resource->scope ?? []));
            $row = collect(is_array($rows) ? $rows : [])->first(fn ($r) => is_array($r) && $section->rowId($r) === $resource->resource_id);

            if ($row === null) {
                return ['error' => __('It is no longer at :provider.', ['provider' => $connector->label()])] + $card;
            }

            // the list's columns, but the first, which names it
            foreach (array_slice($section->columns, 1) as $column) {
                $text = $column->text($row);

                if ($text !== '' && $column->format !== 'bool') {
                    $card['facts'][$column->label] = $text;
                }

                if ($card['status'] === null && $column->colors !== [] && $text !== '') {
                    $card['status'] = ['label' => $text, 'color' => $column->color($row)];
                }
            }

            $card = match ($connector->key().'.'.$section->key) {
                'cipi.apps' => self::cipi($resource, $row, $card),
                'cloudflare.zones' => self::zone($api, $row, $card),
                'boogle.projects' => self::boogle($api, $row, $card),
                'johnny.buckets' => self::bucket($api, $row, $card),
                default => $card,
            };
        } catch (ConnectorException $e) {
            $card['error'] = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $card['error'] = __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()]);
        }

        $card['facts'] = array_slice($card['facts'], 0, 8, true);

        return $card;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Card  $card
     * @return Card
     */
    private static function cipi(ProjectResource $resource, array $row, array $card): array
    {
        $app = CipiApp::fromResource($resource);
        $card['facts'] = array_filter([
            __('Domain') => (string) ($row['domain'] ?? ''),
            __('PHP') => (string) ($row['php'] ?? ''),
            __('Branch') => (string) ($row['branch'] ?? ''),
        ]);
        $card['status'] = ! empty($row['suspended'])
            ? ['label' => __('suspended'), 'color' => 'amber']
            : ['label' => __('online'), 'color' => 'green'];

        $last = Arr::first(self::quietly(fn () => $app->audit(30)) ?? []);

        if (is_array($last)) {
            $card['facts'][__('Last deploy')] = trim(($last['event'] ?? '').' · '.__('release :release', ['release' => $last['release'] ?? '?']).' · '.substr((string) ($last['commit'] ?? ''), 0, 7));
            $card['facts'][__('When')] = self::ago($last['ts'] ?? null);

            if (($last['event'] ?? '') === 'failed') {
                $card['alerts'][] = __('The last deploy failed.');
            }
        }

        $server = self::quietly(fn () => $app->server()) ?? [];

        foreach ([__('CPU') => 'cpu', __('Memory') => 'memory', __('Disk') => 'disk'] as $label => $key) {
            $percent = data_get($server, "resources.{$key}.usage_percent");

            if (is_numeric($percent)) {
                $card['meters'][] = ['label' => __('Server :what', ['what' => mb_strtolower($label)]), 'percent' => (float) $percent, 'text' => round((float) $percent).'%'];
            }
        }

        if (($stopped = collect((array) data_get($server, 'services', []))->filter(fn ($s) => ! in_array($s, ['running', 'active'], true))->keys()->all()) !== []) {
            $card['alerts'][] = __('Stopped on the server: :services', ['services' => implode(', ', $stopped)]);
        }

        $disk = self::quietly(fn () => $app->disk());

        if (is_array($disk['app'] ?? null)) {
            $share = $disk['app'];
            $card['facts'][__('App on disk')] = __(':files GB files · :db GB database', ['files' => $share['files_gb'] ?? '?', 'db' => $share['database_gb'] ?? '?']);

            if (is_numeric($share['limit_percent'] ?? null)) {
                $card['meters'][] = ['label' => __('Of its disk limit (:limit GB)', ['limit' => $share['limit_gb'] ?? '?']), 'percent' => (float) $share['limit_percent'], 'text' => round((float) $share['limit_percent']).'%'];
            }

            if (! empty($share['over_limit'])) {
                $card['alerts'][] = __('Over its disk limit.');
            }
        }

        $agent = self::quietly(fn () => $app->agent());

        if (is_array($agent)) {
            $card['facts'][__('Cipi agent')] = $agent['reason'] === null ? __('MCP on') : ($agent['installed'] ? __('installed, MCP off') : __('not installed'));
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Card  $card
     * @return Card
     */
    private static function zone(ApiClient $api, array $row, array $card): array
    {
        $count = self::quietly(fn () => data_get($api->get('/zones/'.$row['id'].'/dns_records', ['per_page' => 1]), 'result_info.total_count'));

        if (is_numeric($count)) {
            $card['facts'][__('DNS records')] = (string) $count;
        }

        if (($row['status'] ?? '') === 'pending') {
            $card['alerts'][] = __('Waiting for the nameservers: :ns', ['ns' => implode(', ', (array) ($row['name_servers'] ?? []))]);
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Card  $card
     * @return Card
     */
    private static function boogle(ApiClient $api, array $row, array $card): array
    {
        $open = self::quietly(fn () => $api->get('/projects/'.$row['id'].'/exceptions', ['status' => 'OPEN']));

        if (is_array($open)) {
            $total = (int) ($open['total'] ?? count((array) ($open['data'] ?? [])));
            $card['facts'][__('Open exceptions')] = (string) $total;
            $card['status'] = $total > 0 ? ['label' => trans_choice(':count open|:count open', $total), 'color' => 'red'] : ['label' => __('all clear'), 'color' => 'green'];
            $latest = Arr::first((array) ($open['data'] ?? []));

            if (is_array($latest)) {
                $card['alerts'][] = trim(($latest['exception'] ?? '').': '.($latest['error'] ?? ''), ': ');
            }
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Card  $card
     * @return Card
     */
    private static function bucket(ApiClient $api, array $row, array $card): array
    {
        $bucket = (array) (self::quietly(fn () => $api->get('/buckets/'.rawurlencode((string) $row['name']))['data'] ?? []) ?? []);

        foreach (['objects' => __('Objects'), 'object_count' => __('Objects')] as $key => $label) {
            if (is_numeric($bucket[$key] ?? null)) {
                $card['facts'][$label] = Number::format((int) $bucket[$key]);
            }
        }

        foreach (['bytes', 'size', 'size_bytes'] as $key) {
            if (is_numeric($bucket[$key] ?? null)) {
                $card['facts'][__('Size')] = Number::fileSize((int) $bucket[$key], 1);
                break;
            }
        }

        if (is_array($bucket['keys'] ?? null)) {
            $card['facts'][__('Keys')] = (string) count($bucket['keys']);
        }

        return $card;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private static function quietly(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    private static function ago(mixed $when): string
    {
        try {
            return is_string($when) && $when !== '' ? \Illuminate\Support\Carbon::parse($when)->diffForHumans() : '';
        } catch (Throwable) {
            return (string) $when;
        }
    }
}
