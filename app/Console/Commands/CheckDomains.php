<?php

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Server\DomainDns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Every domain of Administration → Domains asked again, every day: one that stopped pointing
 * here is marked lost (Caddy gets no new certificate for it), one that came back is verified,
 * and one still waiting for its DNS is accepted the day it arrives.
 */
class CheckDomains extends Command
{
    protected $signature = 'studio:check-domains';

    protected $description = 'Check that every domain still points at this server';

    public function handle(DomainDns $dns): int
    {
        foreach (Domain::query()->orderBy('name')->get() as $domain) {
            $before = $domain->status;
            $dns->check($domain);
            $this->line("{$domain->name}: {$domain->status->value}".($domain->last_error ? " ({$domain->last_error})" : ''));

            if ($before === DomainStatus::Verified && $domain->status === DomainStatus::Lost) {
                Log::warning("The domain {$domain->name} no longer points at this server: {$domain->last_error}");
            }
        }

        return self::SUCCESS;
    }
}
