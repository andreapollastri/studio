<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\CreatesSshKeys;
use App\Models\Project;
use App\Models\SshKey;
use App\Server\DashboardVisibility;
use App\Server\SecurityPolicy;
use App\Server\ServerException;
use App\Server\Updates;
use App\Support\SecurityLog;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The installation itself: its release and updates, its SSH keys and the
 * projects they are shared with, and the git hosts.
 */
#[Layout('layouts::app')]
#[Title('System · Administration')]
class System extends Component
{
    use CreatesSshKeys;

    public ?string $error = null;

    /** Nightly updates to the newest build of the channel; the switch on the page. */
    public bool $automatic = true;

    /** stable: release tags; beta: the head of main, for beta testers. */
    public string $channel = 'stable';

    /** Every package on the server to its newest version, every night. */
    public bool $serverAutomatic = true;

    /** Whether studio.<domain> shows its sign-in page to anyone; the switch on the page. */
    public bool $visible = true;

    /** Who must have two-factor authentication set up: off, admins or all (SecurityPolicy). */
    public string $twoFactor = SecurityPolicy::OFF;

    /** The uuid of the system key whose projects the modal edits. */
    public ?string $sharing = null;

    /** @var array<string, string> project uuid => off, admins or developers */
    public array $share = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $updates = app(Updates::class);
        $this->automatic = (bool) ($updates->state()['auto'] ?? true);
        $this->channel = $updates->channel();
        $this->serverAutomatic = (bool) ($updates->systemState()['auto'] ?? true);
        $this->visible = ! app(DashboardVisibility::class)->hidden();
        $this->twoFactor = app(SecurityPolicy::class)->twoFactor();
    }

    public function updatedTwoFactor(string $policy): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->twoFactor = app(SecurityPolicy::class)->setTwoFactor($policy);
        SecurityLog::record('policy.two-factor', ['policy' => $this->twoFactor, 'by' => auth()->user()->email], 'notice');

        Flux::toast(variant: 'success', text: match ($this->twoFactor) {
            SecurityPolicy::ALL => __('Everyone sets two-factor up at their next page, before anything else.'),
            SecurityPolicy::ADMINS => __('Administrators set two-factor up at their next page, before anything else.'),
            default => __('Two-factor authentication is optional again.'),
        });
    }

    public function updatedVisible(bool $on): void
    {
        app(DashboardVisibility::class)->setHidden(! $on);
        Log::notice('Studio\'s dashboard is now '.($on ? 'public' : 'hidden').', by '.auth()->user()->email);

        Flux::toast(variant: 'success', text: $on
            ? __('Studio shows its sign-in page to anyone again.')
            : __('Studio is hidden. Keep the link to get in: you are signed in, this browser already has it.'));
    }

    public function rotateDoor(): void
    {
        app(DashboardVisibility::class)->rotateDoor();
        Log::notice('A new door link for Studio\'s dashboard, by '.auth()->user()->email);

        Flux::toast(variant: 'success', text: __('New link made: the previous one no longer opens Studio.'));
    }

    public function updatedAutomatic(bool $on): void
    {
        $this->setAutomatic($on);
    }

    public function updatedServerAutomatic(bool $on): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates, $on) {
            $updates->setSystemAutomatic($on);
            Flux::toast(text: $on ? __('The server updates its packages every night.') : __('Nightly server updates are off.'));
        });
    }

    /** Every package and tool on the server to its newest version, now. */
    public function updateServer(): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates) {
            $updates->startSystem();
            $this->dispatch('system-update-started');
            Flux::toast(variant: 'success', text: __('The server update started. Studio\'s services restart for a moment when it ends.'));
        });
    }

    public function updatedChannel(string $channel): void
    {
        $this->channel = $channel === 'beta' ? 'beta' : 'stable';
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates) {
            $updates->setChannel($this->channel);
            Flux::toast(text: $this->channel === 'beta'
                ? __('Beta tester mode: Studio follows the main branch.')
                : __('Studio follows the release tags again.'));
        });
    }

    /** Build and install the newest build of the channel now, even when it is the installed one. */
    public function forceUpdate(): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates) {
            $updates->start(force: true);
            $this->dispatch('system-update-started');
            Flux::toast(variant: 'success', text: __('Forced update started: the newest build of the channel is built and installed again.'));
        });
    }

    public function check(): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates) {
            $result = $updates->check();

            Flux::toast(text: ($result['update_available'] ?? false) === true
                ? __('Studio :version is available.', ['version' => (string) ($result['target'] ?? '')])
                : ($updates->waiting() ?? __('Studio is up to date.')));
        });
    }

    public function update(): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates) {
            $updates->start();
            $this->dispatch('system-update-started');
            Flux::toast(variant: 'success', text: __('The update started. Studio goes into maintenance for a moment while it switches.'));
        });
    }

    /**
     * The installation's SSH keys: for the connections of the SSH terminal
     * and, later, for the servers Studio creates.
     *
     * @return Collection<int, SshKey>
     */
    #[Computed]
    public function keys(): Collection
    {
        return SshKey::query()->system()->withCount('connections')->with(['projects' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get();
    }

    public function saveKey(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $key = $this->createKey(null);

        if ($key) {
            unset($this->keys);
            $this->showKey($key->uuid);
        }
    }

    public function showKey(string $id): void
    {
        $this->shownKey = $this->systemKey($id)->uuid;

        Flux::modal('ssh-public-key')->show();
    }

    public function deleteKey(string $id): void
    {
        $this->systemKey($id)->delete();
        unset($this->keys);

        Flux::toast(text: __('Key removed. The connections that used it need another one.'));
    }

    /** Which projects a key is shared with, and with whom on each: administrators only, or their developers too. */
    public function editSharing(string $id): void
    {
        $key = $this->systemKey($id);
        $shared = $key->projects()->pluck('project_ssh_key.developers', 'projects.uuid');

        $this->sharing = $key->uuid;
        $this->share = Project::query()->orderBy('name')->pluck('uuid')
            ->mapWithKeys(fn (string $uuid) => [$uuid => match (true) {
                ! $shared->has($uuid) => 'off',
                (bool) $shared[$uuid] => 'developers',
                default => 'admins',
            }])
            ->all();

        Flux::modal('ssh-key-sharing')->show();
    }

    public function saveSharing(): void
    {
        $key = $this->systemKey((string) $this->sharing);

        $ids = Project::query()->whereIn('uuid', array_keys($this->share))->pluck('id', 'uuid');
        $key->projects()->sync(collect($this->share)
            ->filter(fn (mixed $who, string $uuid) => in_array($who, ['admins', 'developers'], true) && $ids->has($uuid))
            ->mapWithKeys(fn (string $who, string $uuid) => [$ids[$uuid] => ['developers' => $who === 'developers']])
            ->all());

        unset($this->keys);
        Flux::modal('ssh-key-sharing')->close();
        Flux::toast(variant: 'success', text: __('Sharing of :name saved.', ['name' => $key->name]));
    }

    private function systemKey(string $id): SshKey
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return SshKey::query()->system()->where('uuid', $id)->firstOrFail();
    }

    private function setAutomatic(bool $on): void
    {
        $updates = app(Updates::class);

        $this->guard($updates, function () use ($updates, $on) {
            $updates->setAutomatic($on);
            Flux::toast(text: $on ? __('Studio updates itself every night.') : __('Automatic updates are off.'));
        });
    }

    private function guard(Updates $updates, callable $action): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if (! $updates->managed()) {
            $this->error = __('Updates run on the server. This Studio is a local installation.');

            return;
        }

        try {
            $action();
            $this->error = null;
        } catch (ServerException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $updates = app(Updates::class);
        $state = $updates->state();
        $visibility = app(DashboardVisibility::class);

        return view('livewire.admin.system', [
            'managed' => $updates->managed(),
            'version' => $updates->version(),
            'state' => $state,
            'running' => ($state['status'] ?? null) === 'running',
            'server' => $updates->systemState(),
            'serverRunning' => $updates->systemRunning(),
            'available' => $updates->available(),
            'waiting' => $updates->waiting(),
            'releaseUrl' => fn (string $tag) => $updates->releaseUrl($tag),
            'projectCount' => Project::query()->count(),
            'domain' => (string) config('studio.domain'),
            'hidden' => $visibility->hidden(),
            'doorUrl' => $visibility->hidden() ? $visibility->doorUrl() : null,
            'twoFactorOptions' => SecurityPolicy::options(),
            'securityEvents' => SecurityLog::recent(30),
            'shown' => $this->shownKey ? $this->keys()->firstWhere('uuid', $this->shownKey) : null,
            'sharingKey' => $this->sharing ? $this->keys()->firstWhere('uuid', $this->sharing) : null,
            'projects' => $this->sharing ? Project::query()->orderBy('name')->get(['id', 'uuid', 'name']) : collect(),
        ]);
    }
}
