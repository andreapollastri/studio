<?php

namespace App\Console\Commands;

use App\Server\DashboardVisibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Whether studio.<domain> shows itself to anyone, and the link that lets people in when it
 * does not (see DashboardVisibility). On a server, root runs it as `studio-recover door`,
 * `studio-recover public` and `studio-recover hidden`: the way back to a hidden dashboard
 * whose link nobody kept.
 */
class Visibility extends Command
{
    protected $signature = 'studio:visibility
        {state? : public or hidden; the current state when omitted}
        {--rotate : a new door link: the previous one stops working}
        {--json : print the outcome as JSON instead of text}';

    protected $description = 'Show the dashboard to anyone or hide it behind a link, and print that link';

    public function handle(DashboardVisibility $visibility): int
    {
        $state = $this->argument('state');

        if ($state !== null && ! in_array($state, ['public', 'hidden'], true)) {
            $this->components->error('The state is public or hidden.');

            return self::FAILURE;
        }

        if ($state !== null) {
            $visibility->setHidden($state === 'hidden');
            Log::notice("Studio's dashboard is now {$state}, from the server");
        }

        if ($this->option('rotate')) {
            $visibility->rotateDoor();
            Log::notice('A new door link for Studio\'s dashboard, from the server');
        }

        $hidden = $visibility->hidden();
        $door = $visibility->doorUrl();

        if ($this->option('json')) {
            $this->line((string) json_encode(['hidden' => $hidden, 'door' => $door], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($hidden
            ? 'Hidden: who is not signed in sees a page that says nothing of Studio.'
            : 'Public: '.config('app.url').' shows its sign-in page to anyone.');
        $this->line(($hidden ? 'The way in' : 'The way in, when hidden').": {$door}");

        return self::SUCCESS;
    }
}
