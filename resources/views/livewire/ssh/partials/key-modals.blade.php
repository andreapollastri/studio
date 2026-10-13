{{-- The New SSH key form (CreatesSshKeys) and the public half of a key ($shown), for the SSH page and System. --}}
{{-- New key --}}
<flux:modal name="ssh-key" class="md:w-[34rem]">
    <form wire:submit="saveKey" class="space-y-5">
        <flux:heading size="lg">{{ __('New SSH key') }}</flux:heading>
        <flux:input wire:model="key_name" :label="__('Name')" :placeholder="__('studio deploy key')" required />

        <flux:radio.group wire:model.live="key_mode" variant="segmented">
            <flux:radio value="generate" :label="__('Generate')" icon="sparkles" />
            <flux:radio value="import" :label="__('Import')" icon="arrow-down-tray" />
        </flux:radio.group>

        @if ($key_mode === 'generate')
            <flux:radio.group wire:model="key_type" :label="__('Type')">
                <flux:radio value="ed25519" label="Ed25519" :description="__('Short, fast and modern: the default of OpenSSH. Recommended.')" />
                <flux:radio value="rsa" label="RSA 4096" :description="__('For older servers and appliances that do not accept Ed25519.')" />
            </flux:radio.group>
        @else
            <flux:textarea wire:model="key_private" :label="__('Private key')" rows="8" class="font-mono text-xs" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" />
            <flux:input wire:model="key_passphrase" type="password" :label="__('Passphrase')" :description="__('Only if the key has one: it is used to read the key and not kept.')" autocomplete="off" viewable />
            <flux:text class="text-xs">{{ __('OpenSSH, PEM (PKCS#1, PKCS#8) and PuTTY keys; RSA, Ed25519 and ECDSA.') }}</flux:text>
        @endif
        <flux:error name="key_private" />

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ $key_mode === 'generate' ? __('Generate') : __('Import') }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Public key, to copy into authorized_keys --}}
<flux:modal name="ssh-public-key" class="md:w-[40rem]">
    @if ($shown)
        <div class="space-y-4" x-data="{ copied: null, copy(text, what) { navigator.clipboard.writeText(text); this.copied = what; setTimeout(() => this.copied = null, 1500) } }">
            <div>
                <flux:heading size="lg">{{ $shown->name }}</flux:heading>
                <flux:text class="mt-1">{{ $shown->label() }} · <span class="font-mono text-xs">{{ $shown->fingerprint }}</span></flux:text>
            </div>
            <flux:text>{{ __('Add the public key to ~/.ssh/authorized_keys of the user on the server, or to the deploy keys of your hosting panel.') }}</flux:text>
            <div class="relative">
                <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-3 pe-24 font-mono text-xs dark:bg-zinc-900">{{ $shown->public_key }}</pre>
                <flux:button size="xs" class="absolute end-2 top-2" icon="clipboard-document" x-on:click="copy(@js($shown->public_key), 'key')">
                    <span x-text="copied === 'key' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                </flux:button>
            </div>
            <div class="relative">
                <flux:text class="mb-1 text-xs">{{ __('Or run this on the server, as that user:') }}</flux:text>
                <pre class="overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-3 pe-24 font-mono text-xs dark:bg-zinc-900">{{ $authorize = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '.escapeshellarg($shown->public_key).' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys' }}</pre>
                <flux:button size="xs" class="absolute end-2 top-7" icon="clipboard-document" x-on:click="copy(@js($authorize), 'cmd')">
                    <span x-text="copied === 'cmd' ? @js(__('Copied')) : @js(__('Copy'))"></span>
                </flux:button>
            </div>
            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
            </div>
        </div>
    @endif
</flux:modal>
