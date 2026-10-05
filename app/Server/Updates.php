<?php

namespace App\Server;

/**
 * Studio's own updates. The root updater (studio-update) keeps its state in a
 * JSON file Studio may read; checking, starting an update and switching the
 * nightly updates on or off go through the helper, as root.
 */
final class Updates
{
    public function __construct(private readonly StudioAdmin $admin) {}

    /** Only a server installation updates itself; a local one is a git checkout. */
    public function managed(): bool
    {
        return config('studio.driver') === 'native';
    }

    public function version(): string
    {
        return (string) config('studio.version');
    }

    /**
     * What the updater last wrote: current, previous, latest, checked_at, status,
     * message, started_at, finished_at, target, auto, log…
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $path = (string) config('studio.updates.state');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    public function running(): bool
    {
        return ($this->state()['status'] ?? null) === 'running';
    }

    /** stable follows the release tags, beta the head of the main branch. */
    public function channel(): string
    {
        return ($this->state()['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    }

    /** The build the channel would install, when the last check found one newer than the installed build. */
    public function available(): ?string
    {
        $state = $this->state();
        $target = $state['target'] ?? null;

        return ($state['update_available'] ?? false) === true && is_string($target) && $target !== '' ? $target : null;
    }

    /** Why nothing moves, when the last check said so, in the language of the page. */
    public function waiting(): ?string
    {
        $state = $this->state();
        $current = (string) ($state['current'] ?? '');
        $target = (string) ($state['target'] ?? '');

        $message = match ($state['waiting_reason'] ?? '') {
            'no-release' => __('No release of Studio is published yet.'),
            'behind' => __('Studio runs :current, newer than :target: it stays there until a newer build of this channel contains it.', ['current' => $current, 'target' => $target]),
            'diverged' => __(':target does not contain the installed :current (the history differs): Force update installs it anyway.', ['current' => $current, 'target' => $target]),
            'unreachable' => __('The Studio repository is not reachable.'),
            default => null,
        };

        // a translation key may resolve to a group of lines; only a sentence is a reason
        return is_string($message) ? $message : null;
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        return $this->admin->run('update-check');
    }

    /** Start the update as its own unit; forced, it builds and installs the channel's newest build even when it is installed. */
    public function start(bool $force = false): void
    {
        $this->admin->run('update-start', $force ? ['force'] : []);
    }

    public function setChannel(string $channel): void
    {
        $this->admin->run('update-channel', [$channel === 'beta' ? 'beta' : 'stable']);
    }

    public function setAutomatic(bool $on): void
    {
        $this->admin->run('update-auto', [$on ? 'on' : 'off']);
    }

    /** Where a build can be read: the release page of a tag, the commit of a main build. */
    public function releaseUrl(string $build): string
    {
        $repository = rtrim((string) config('studio.updates.repository'), '/');

        return str_starts_with($build, 'main-')
            ? $repository.'/commit/'.substr($build, 5)
            : $repository.'/releases/tag/'.$build;
    }
}
