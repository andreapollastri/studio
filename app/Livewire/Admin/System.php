<?php

namespace App\Livewire\Admin;

use App\Git\Providers\GitProvider;
use App\Models\Project;
use App\Server\ServerException;
use App\Server\Updates;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The installation itself: its release and updates, and its git provider. */
#[Layout('layouts::app')]
#[Title('System · Administration')]
class System extends Component
{
    public ?string $error = null;

    /** Nightly updates to the newest build of the channel; the switch on the page. */
    public bool $automatic = true;

    /** stable: release tags; beta: the head of main, for beta testers. */
    public string $channel = 'stable';

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $updates = app(Updates::class);
        $this->automatic = (bool) ($updates->state()['auto'] ?? true);
        $this->channel = $updates->channel();
    }

    public function updatedAutomatic(bool $on): void
    {
        $this->setAutomatic($on);
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
            Flux::toast(variant: 'success', text: __('The update started. Studio goes into maintenance for a moment while it switches.'));
        });
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

        return view('livewire.admin.system', [
            'managed' => $updates->managed(),
            'version' => $updates->version(),
            'state' => $state,
            'running' => ($state['status'] ?? null) === 'running',
            'available' => $updates->available(),
            'waiting' => $updates->waiting(),
            'releaseUrl' => fn (string $tag) => $updates->releaseUrl($tag),
            'git' => GitProvider::current(),
            'projectCount' => Project::query()->count(),
        ]);
    }
}
