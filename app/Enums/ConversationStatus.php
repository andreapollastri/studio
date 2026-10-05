<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case WaitingPermission = 'waiting';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => __('idle'),
            self::Running => __('working'),
            self::WaitingPermission => __('waiting for permission'),
            self::Failed => __('failed'),
        };
    }
}
