<?php

namespace App\Enums;

/**
 * Where a domain stands: added and waiting for its DNS, pointing at this server, or no
 * longer pointing here since it was (Caddy gets no new certificate for it until it does).
 */
enum DomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('waiting for DNS'),
            self::Verified => __('verified'),
            self::Lost => __('DNS lost'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Verified => 'green',
            self::Lost => 'red',
        };
    }
}
