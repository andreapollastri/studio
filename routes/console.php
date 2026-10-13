<?php

use App\Jobs\RefreshWorkspaces;
use Illuminate\Support\Facades\Schedule;

if (config('studio.driver') === 'native') {
    Schedule::job(new RefreshWorkspaces)->everyMinute()->withoutOverlapping();
    // the domains of Administration → Domains: still pointing here?
    Schedule::command('studio:check-domains')->dailyAt('05:30');
}
