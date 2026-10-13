<?php

namespace App\Console\Commands;

use App\Databases\Transfers\Transfers;
use Illuminate\Console\Command;

/** Every night: the exports past their days and the imports' files go, and what a dead worker left running is marked failed. */
class PruneDatabaseTransfers extends Command
{
    protected $signature = 'studio:database-transfers';

    protected $description = 'Remove old database exports and imports';

    public function handle(Transfers $transfers): int
    {
        $this->line($transfers->prune().' file(s) removed');

        return self::SUCCESS;
    }
}
