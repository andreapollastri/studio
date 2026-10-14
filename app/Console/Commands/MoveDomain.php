<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTextInput;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Workspace;
use App\Server\ProjectSites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The database's half of a move to another domain: the previews (`app_url`) and the
 * sandboxes' hosts kept on their rows, and a project's own site address when it was under
 * the old domain. With --webhooks, once Studio runs on the new domain, every push webhook
 * is registered again at Studio's new address (the old one would call a host that is gone).
 * Root runs it through `studio-domain change`, which moves the server's half (the .env
 * files, Caddy, the certificates) around it.
 */
class MoveDomain extends Command
{
    use ReadsTextInput;

    protected $signature = 'studio:move-domain
        {from : the domain Studio was on}
        {to : the domain it moves to}
        {--webhooks : also register every push webhook again, at the address Studio has now}';

    protected $description = 'Move the hosts kept in the database from one domain to another';

    private const DOMAIN = '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/';

    public function handle(ProjectSites $sites): int
    {
        $from = strtolower($this->textArgument('from') ?? '');
        $to = strtolower($this->textArgument('to') ?? '');

        if (! preg_match(self::DOMAIN, $from) || ! preg_match(self::DOMAIN, $to) || $from === $to) {
            $this->components->error('Two different domains, like dev.example.com and studio.example.org.');

            return self::FAILURE;
        }

        $moved = 0;

        foreach (Workspace::query()->whereNotNull('app_url')->get() as $workspace) {
            if (($url = self::rehostUrl((string) $workspace->app_url, $from, $to)) !== null) {
                $workspace->forceFill(['app_url' => $url])->save();
                $moved++;
            }
        }

        foreach (Conversation::query()->whereNotNull('sandbox_host')->get() as $conversation) {
            if (($host = self::rehost((string) $conversation->sandbox_host, $from, $to)) !== null) {
                $sandbox = (array) $conversation->sandbox;

                if (isset($sandbox['url'])) {
                    $sandbox['url'] = self::rehostUrl((string) $sandbox['url'], $from, $to) ?? $sandbox['url'];
                }

                $conversation->forceFill(['sandbox_host' => $host, 'sandbox' => $sandbox])->save();
                $moved++;
            }
        }

        foreach (Project::query()->get() as $project) {
            $settings = (array) $project->settings;

            if (isset($settings['site_url']) && ($url = self::rehostUrl((string) $settings['site_url'], $from, $to)) !== null) {
                $project->forceFill(['settings' => [...$settings, 'site_url' => $url]])->save();
                $moved++;
            }
        }

        $this->line("{$moved} host(s) moved from {$from} to {$to} in the database.");
        Log::notice("Studio's hosts moved from {$from} to {$to}: {$moved} in the database");

        if ($this->option('webhooks')) {
            return $this->webhooks($sites, $to);
        }

        return self::SUCCESS;
    }

    /** Every push webhook again, at the address of now: config('app.url') must already be on the new domain. */
    private function webhooks(ProjectSites $sites, string $to): int
    {
        if (strtolower((string) config('studio.domain')) !== $to) {
            $this->components->error('Studio still runs on '.config('studio.domain').": rebuild its configuration on {$to} first.");

            return self::FAILURE;
        }

        $failed = 0;

        foreach (Project::query()->whereNotNull('webhook_id')->orderBy('slug')->get() as $project) {
            try {
                $sites->renewWebhook($project);
                $this->line("webhook of {$project->slug}: registered at ".route('api.git.webhook', $project));
            } catch (Throwable $e) {
                $failed++;
                $this->line("webhook of {$project->slug}: FAILED, {$e->getMessage()} (Deploy the site from its settings to try again)");
                Log::warning("The push webhook of {$project->slug} could not be registered again after the domain moved: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** `<anything>.<from>` on `<anything>.<to>`; null when the host is not under `from`. */
    public static function rehost(string $host, string $from, string $to): ?string
    {
        $host = strtolower($host);

        if ($host === $from) {
            return $to;
        }

        return str_ends_with($host, '.'.$from) ? substr($host, 0, -strlen($from)).$to : null;
    }

    public static function rehostUrl(string $url, string $from, string $to): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $new = $host === '' ? null : self::rehost($host, $from, $to);

        return $new === null ? null : preg_replace('#^(\w+://)'.preg_quote($host, '#').'#i', '${1}'.$new, $url, 1);
    }
}
