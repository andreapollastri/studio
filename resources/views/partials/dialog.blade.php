{{-- Studio's confirm and prompt (resources/js/dialog.js): every wire:confirm and studioDialog.confirm/prompt opens here
     instead of the browser's own box. Esc or a click outside cancels, Enter confirms. --}}
<div
    x-data
    x-show="$store.dialog.shown"
    x-cloak
    x-on:keydown.escape.window="$store.dialog.shown && $store.dialog.dismiss()"
    class="fixed inset-0 z-[60] flex items-end justify-center p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    :aria-label="$store.dialog.title"
>
    <div x-show="$store.dialog.shown" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 backdrop-blur-[2px] dark:bg-black/60" x-on:click="$store.dialog.dismiss()"></div>

    <form
        x-show="$store.dialog.shown"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-trap.noscroll="$store.dialog.shown"
        x-on:submit.prevent="$store.dialog.accept()"
        class="relative w-full max-w-md space-y-5 rounded-xl bg-white p-6 shadow-xl ring-1 ring-black/5 dark:bg-zinc-800 dark:ring-white/10"
    >
        <div class="flex items-start gap-4">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-full"
                :class="$store.dialog.danger ? 'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' : 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300'">
                <flux:icon.trash x-show="$store.dialog.danger" class="size-5" />
                <flux:icon.pencil-square x-show="! $store.dialog.danger && $store.dialog.kind === 'prompt'" class="size-5" />
                <flux:icon.question-mark-circle x-show="! $store.dialog.danger && $store.dialog.kind === 'confirm'" class="size-5" />
            </div>
            <div class="min-w-0 flex-1 pt-1">
                <flux:heading size="lg" class="break-words" x-text="$store.dialog.title" />
                <flux:text class="mt-1 whitespace-pre-line break-words" x-show="$store.dialog.body" x-text="$store.dialog.body" />
            </div>
        </div>

        <template x-if="$store.dialog.kind === 'prompt'">
            <div class="space-y-1.5">
                <label x-show="$store.dialog.label" class="text-sm font-medium" x-text="$store.dialog.label" for="studio-dialog-value"></label>
                <input
                    id="studio-dialog-value"
                    type="text"
                    x-model="$store.dialog.value"
                    x-init="$nextTick(() => { $el.focus(); $el.select() })"
                    :placeholder="$store.dialog.placeholder"
                    autocomplete="off"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-zinc-400 focus:ring-2 focus:ring-zinc-900/10 dark:border-white/10 dark:bg-white/10 dark:focus:border-white/30"
                />
            </div>
        </template>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" x-on:click="$store.dialog.dismiss()"><span x-text="$store.dialog.cancel"></span></flux:button>
            <flux:button type="submit" variant="primary" x-show="! $store.dialog.danger" x-init="$watch('$store.dialog.shown', (shown) => shown && $store.dialog.kind === 'confirm' && ! $store.dialog.danger && $nextTick(() => $el.focus()))"><span x-text="$store.dialog.confirm"></span></flux:button>
            <flux:button type="submit" variant="danger" x-show="$store.dialog.danger"><span x-text="$store.dialog.confirm"></span></flux:button>
        </div>
    </form>
</div>
