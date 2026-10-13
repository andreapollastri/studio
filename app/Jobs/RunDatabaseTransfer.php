<?php

namespace App\Jobs;

use App\Databases\Transfers\Transfers;
use App\Models\DatabaseTransfer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One import or export of Tools → Databases, on the databases queue (its own worker,
 * studio-databases): hours allowed, never retried, its log on the transfer as it goes.
 */
class RunDatabaseTransfer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public int $transferId)
    {
        $this->onConnection('databases');
        $this->onQueue('databases');
    }

    public function handle(Transfers $transfers): void
    {
        $transfer = DatabaseTransfer::query()->with(['connection', 'user'])->find($this->transferId);

        if ($transfer === null || $transfer->status !== 'queued') {
            return;
        }

        $transfer->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $transfers->run($transfer);
            $transfer->forceFill(['status' => 'success', 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            $transfer->appendOutput('['.now()->format('H:i:s').'] '.$e->getMessage()."\n");
            $transfer->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
            Log::warning('database transfer failed', ['transfer' => $transfer->uuid, 'kind' => $transfer->kind, 'connection' => $transfer->connection->name, 'error' => $e->getMessage()]);
        }

        Log::info('database transfer', ['transfer' => $transfer->uuid, 'kind' => $transfer->kind, 'format' => $transfer->format, 'status' => $transfer->status, 'user' => $transfer->user?->email, 'connection' => $transfer->connection->name]);
    }
}
