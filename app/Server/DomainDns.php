<?php

namespace App\Server;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Does a domain point at this server? This server is what studio.<domain> resolves to (the
 * wildcard the installer asked for), or STUDIO_SERVER_IPS when that is not the whole story.
 * A domain pointing here is its own proof: Caddy issues a certificate only for a host whose
 * DNS sends the CA here, so nobody gets one for a name they do not control.
 */
class DomainDns
{
    /** @return list<string> */
    public function serverAddresses(): array
    {
        $configured = (array) config('studio.server_ips', []);

        if ($configured !== []) {
            return array_values($configured);
        }

        return Cache::remember('studio:server-addresses', 600, fn () => $this->resolve('studio.'.config('studio.domain')));
    }

    /** @return list<string> the IPv4 addresses of a host, following CNAMEs, sorted */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];
        $ips = array_values(array_unique(array_filter(array_map(fn ($r) => (string) ($r['ip'] ?? ''), $records))));
        sort($ips);

        return $ips;
    }

    /** Ask the DNS again and say where the domain stands. */
    public function check(Domain $domain): Domain
    {
        $expected = $this->serverAddresses();
        $records = [
            'expected' => $expected,
            'name' => $this->resolve($domain->name),
            // any name under the wildcard: a random one, so that a record of its own does not answer for it
            'wildcard' => $domain->wildcard ? $this->resolve('studio-probe-'.Str::lower(Str::random(6)).'.'.$domain->name) : [],
        ];
        $domain->records = $records;

        $ok = $domain->namePointsHere() || $domain->wildcardPointsHere();
        $status = match (true) {
            $ok => DomainStatus::Verified,
            $domain->status === DomainStatus::Verified, $domain->status === DomainStatus::Lost => DomainStatus::Lost,
            default => DomainStatus::Pending,
        };

        $domain->forceFill([
            'status' => $status,
            'checked_at' => now(),
            'verified_at' => $ok ? ($domain->verified_at ?? now()) : $domain->verified_at,
            'last_error' => $ok ? $this->partly($domain) : $this->why($domain, $expected),
        ])->save();

        return $domain;
    }

    /** Verified, and yet half of it does not point here: say which half. */
    private function partly(Domain $domain): ?string
    {
        if (! $domain->namePointsHere()) {
            return __(':name itself does not point here: sites go at <name>.:name only.', ['name' => $domain->name]);
        }

        if ($domain->wildcard && ! $domain->wildcardPointsHere()) {
            return __('*.:name does not point here: only :name itself can be used, no previews.', ['name' => $domain->name]);
        }

        return null;
    }

    /** @param  list<string>  $expected */
    private function why(Domain $domain, array $expected): string
    {
        if ($expected === []) {
            return __('Studio could not tell this server\'s address: studio.:domain does not resolve. Set STUDIO_SERVER_IPS.', ['domain' => config('studio.domain')]);
        }

        $seen = (array) ($domain->records['name'] ?? []);

        return $seen === []
            ? __(':name does not resolve yet. DNS can take a few minutes.', ['name' => $domain->name])
            : __(':name points at :seen, not at this server (:expected).', ['name' => $domain->name, 'seen' => implode(', ', $seen), 'expected' => implode(', ', $expected)]);
    }
}
