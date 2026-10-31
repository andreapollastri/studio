{{-- Touch screens: the keys a phone's keyboard lacks, and Paste. A key is pressed
     without taking the focus, so the keyboard stays up. Paste is a click, so the
     browser will hand over the clipboard: when it will not, the field opens and
     a long-press paste goes there, then into the terminal. The Alpine parent has
     key(name), paste(), ctrl and pasteOpen. $visible shows the bar. --}}
<div class="ssh-keybar" x-show="{{ $visible }}">
    <div class="ssh-paste" x-show="pasteOpen" x-cloak x-ref="pasteSheet">
        <p class="text-[11px] leading-snug text-zinc-400">{{ __('Paste into the terminal. Long-press the field if the phone did not hand the text over.') }}</p>
        <textarea
            x-ref="pasteBox"
            x-model="pasteText"
            x-on:paste="takePaste($event)"
            x-on:keydown.escape.prevent="cancelPaste()"
            x-on:keydown.ctrl.enter.prevent="commitPaste()"
            x-on:keydown.meta.enter.prevent="commitPaste()"
            rows="3"
            inputmode="text"
            enterkeyhint="send"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="none"
            spellcheck="false"
            placeholder="{{ __('Long-press and paste') }}"
            class="max-h-36 min-h-16 w-full resize-none rounded-md bg-zinc-900 px-2.5 py-2 font-mono text-xs leading-relaxed text-zinc-100 ring-1 ring-zinc-700 ring-inset outline-none placeholder:text-zinc-600 focus:ring-zinc-500"
        ></textarea>
        <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-3 py-2 text-xs text-zinc-300 ring-1 ring-zinc-700 ring-inset" x-on:click="cancelPaste()">{{ __('Cancel') }}</button>
            <button type="button" class="rounded-md bg-zinc-100 px-3 py-2 text-xs font-medium text-zinc-900 disabled:opacity-40" x-on:click="commitPaste()" x-bind:disabled="pasteText === ''">{{ __('Insert') }}</button>
        </div>
    </div>
    <div class="ssh-keybar-keys">
        <button
            type="button"
            class="inline-flex shrink-0 touch-manipulation items-center gap-1 rounded-md bg-zinc-800 px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-zinc-100 ring-1 ring-zinc-600 ring-inset active:bg-zinc-700"
            x-on:click="paste()"
            x-bind:aria-expanded="pasteOpen"
            title="{{ __('Paste into the terminal') }}"
        >
            <flux:icon.clipboard-document variant="micro" class="size-3.5" />
            {{ __('Paste') }}
        </button>
        @foreach (['esc' => 'Esc', 'tab' => 'Tab', 'ctrl' => 'Ctrl', 'ctrl-c' => '^C', 'ctrl-d' => '^D', 'up' => '↑', 'down' => '↓', 'left' => '←', 'right' => '→', 'home' => 'Home', 'end' => 'End', 'pipe' => '|', 'tilde' => '~', 'slash' => '/', 'dash' => '-'] as $name => $label)
            <button
                type="button"
                class="shrink-0 rounded-md px-2.5 py-1.5 font-mono text-xs text-zinc-300 ring-1 ring-zinc-700 ring-inset active:bg-zinc-700"
                @if ($name === 'ctrl') x-bind:class="ctrl && 'bg-zinc-100 text-zinc-900! ring-zinc-100'" x-bind:aria-pressed="ctrl" @endif
                x-on:touchstart.prevent="key(@js($name))"
                x-on:mousedown.prevent="key(@js($name))"
            >{{ $label }}</button>
        @endforeach
    </div>
</div>
