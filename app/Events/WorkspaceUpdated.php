<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A workspace changed status (creating, starting, running, stopping…): the
 * project page of its owner updates at once instead of at the next poll.
 */
class WorkspaceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $workspaceUuid,
        public string $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('workspaces.'.$this->workspaceUuid);
    }

    public function broadcastAs(): string
    {
        return 'WorkspaceUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}
