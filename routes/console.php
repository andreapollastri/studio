<?php

use App\Jobs\RefreshWorkspaces;
use Illuminate\Support\Facades\Schedule;

if (config('studio.driver') === 'native') {
    Schedule::job(new RefreshWorkspaces)->everyMinute()->withoutOverlapping();
}
