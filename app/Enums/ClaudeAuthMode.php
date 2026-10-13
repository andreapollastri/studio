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

    /**
     * The environment variable a workspace gets for this kind of credential. Only an
     * API key travels: a subscription is the person's own `claude auth login` in their home.
     */
    public function envName(): ?string
    {
        return match ($this) {
            self::ApiKey => 'ANTHROPIC_API_KEY',
            default => null,
        };
    }
}
