{{-- Above the SSH file manager: the folder of the server the tree shows, and the way to another. Inside studioEditor: leaveFolder() asks before unsaved tabs go. --}}
<form
    x-on:submit.prevent="if (await leaveFolder()) $wire.go()"
    class="flex shrink-0 items-center gap-0.5 border-b border-zinc-200 px-2 py-1 dark:border-zinc-700"
>
    <flux:button type="button" size="xs" variant="ghost" icon="arrow-up" x-on:click="if (await leaveFolder()) $wire.up()" :disabled="$root === '/'" :title="__('Parent folder')" />
    <flux:button type="button" size="xs" variant="ghost" icon="home" x-on:click="if (await leaveFolder()) $wire.home()" :title="__('Home folder')" />
    <input
        type="text"
        wire:model="goTo"
        autocomplete="off"
        spellcheck="false"
        aria-label="{{ __('Folder on the server') }}"
        placeholder="/var/www"
        class="min-w-0 flex-1 rounded border border-transparent bg-transparent px-1.5 py-0.5 font-mono text-xs outline-none hover:border-zinc-200 focus:border-zinc-400 dark:hover:border-zinc-700 dark:focus:border-zinc-500"
    />
    <flux:button type="submit" size="xs" variant="ghost" icon="arrow-right" :title="__('Go to this folder')" />
</form>
