<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case New = 'new';
    case Creating = 'creating';
    case Starting = 'starting';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::New => __('to create'),
            self::Creating => __('creating'),
            self::Starting => __('starting'),
            self::Running => __('running'),
            self::Stopping => __('stopping'),
            self::Stopped => __('stopped'),
            self::Failed => __('failed'),
            self::Deleted => __('deleted'),
        };
    }

    public function isTransitional(): bool
    {
        return in_array($this, [self::Creating, self::Starting, self::Stopping], true);
    }
}
