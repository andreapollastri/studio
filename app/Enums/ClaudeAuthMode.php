<?php

namespace App\Enums;

enum ClaudeAuthMode: string
{
    case None = 'none';
    case Subscription = 'subscription';
    case ApiKey = 'api_key';

    public function label(): string
    {
        return match ($this) {
            self::None => __('not connected'),
            self::Subscription => __('Claude subscription'),
            self::ApiKey => __('API key'),
        };
    }
}
