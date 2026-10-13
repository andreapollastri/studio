<?php

namespace App\Livewire\Concerns;

use App\Models\SshKey;
use App\Models\User;
use App\Ssh\Keys;
use Flux\Flux;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The "New SSH key" form (resources/views/livewire/ssh/partials/key-modals),
 * shared by the SSH page, for a person's own keys, and System, for the
 * installation's: generate an Ed25519 or RSA key, or import one.
 */
trait CreatesSshKeys
{
    public string $key_name = '';

    public string $key_mode = 'generate';

    public string $key_type = 'ed25519';

    public string $key_private = '';

    public string $key_passphrase = '';

    /** The key whose public half the modal shows, to copy into authorized_keys. */
    public ?string $shownKey = null;

    public function newKey(): void
    {
        $this->reset(['key_name', 'key_mode', 'key_type', 'key_private', 'key_passphrase']);
        $this->resetValidation();

        Flux::modal('ssh-key')->show();
    }

    /** The key from the form, for this person or, with null, for the installation; null when it is refused. */
    protected function createKey(?User $owner): ?SshKey
    {
        $this->validate([
            'key_name' => ['required', 'string', 'max:60', Rule::unique('ssh_keys', 'name')->where('user_id', $owner?->id)],
            'key_mode' => ['required', Rule::in(['generate', 'import'])],
            'key_type' => ['required_if:key_mode,generate', Rule::in(Keys::TYPES)],
            'key_private' => ['required_if:key_mode,import', 'nullable', 'string', 'max:20000'],
            'key_passphrase' => ['nullable', 'string', 'max:500'],
        ], [
            'key_private.required_if' => __('Paste the private key.'),
        ]);

        $comment = ($owner?->handle ?: 'studio').'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        try {
            $material = $this->key_mode === 'generate'
                ? Keys::generate($this->key_type, $comment)
                : Keys::import($this->key_private, $this->key_passphrase, $comment);
        } catch (InvalidArgumentException $e) {
            $this->addError('key_private', $e->getMessage());

            return null;
        }

        $key = SshKey::query()->create(['user_id' => $owner?->id, 'name' => $this->key_name, ...$material]);

        $this->reset(['key_private', 'key_passphrase']);
        Flux::modal('ssh-key')->close();

        return $key;
    }
}
