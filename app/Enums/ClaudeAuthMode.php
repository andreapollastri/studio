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

    /** The environment variable Claude Code reads for this kind of credential. */
    public function envName(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Subscription => 'CLAUDE_CODE_OAUTH_TOKEN',
            self::ApiKey => 'ANTHROPIC_API_KEY',
        };
    }
}
