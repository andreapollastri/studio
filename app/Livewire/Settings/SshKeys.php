<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\CreatesSshKeys;
use App\Models\SshKey;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A person's SSH keys, next to their git and Claude accounts: generated here
 * (Ed25519 or RSA) or imported, stored encrypted, used by the SSH terminal to
 * open their connections and those of the projects they develop.
 */
class SshKeys extends Component
{
    use CreatesSshKeys;

    /** @return Collection<int, SshKey> */
    #[Computed]
    public function keys(): Collection
    {
        return auth()->user()->sshKeys()->withCount('connections')->orderBy('name')->get();
    }

    public function saveKey(): void
    {
        $key = $this->createKey(auth()->user());

        if ($key) {
            unset($this->keys);
            $this->showKey($key->uuid);
        }
    }

    public function showKey(string $id): void
    {
        $this->shownKey = ($this->keys()->firstWhere('uuid', $id) ?? abort(404))->uuid;

        Flux::modal('ssh-public-key')->show();
    }

    public function deleteKey(string $id): void
    {
        ($this->keys()->firstWhere('uuid', $id) ?? abort(404))->delete();
        unset($this->keys);

        Flux::toast(text: __('Key removed. The connections that used it need another one.'));
    }

    public function render(): View
    {
        return view('livewire.settings.ssh-keys', [
            'shown' => $this->shownKey ? $this->keys()->firstWhere('uuid', $this->shownKey) : null,
        ]);
    }
}
