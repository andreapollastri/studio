<?php

namespace App\Enums;

enum SiteStatus: string
{
    case New = 'new';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Deploying = 'deploying';
    case Failed = 'failed';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::New => __('not created'),
            self::Provisioning => __('creating'),
            self::Ready => __('online'),
            self::Deploying => __('deploying'),
            self::Failed => __('failed'),
            self::Deleting => __('deleting'),
        };
    }

    /** The helper is working on the site: no deploy, no settings, no second delete meanwhile. */
    public function isTransitional(): bool
    {
        return $this === self::Provisioning || $this === self::Deploying || $this === self::Deleting;
    }
}
