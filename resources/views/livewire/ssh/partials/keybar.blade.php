{{-- Touch screens: the keys a phone's keyboard lacks. Pressed without taking the focus, so the keyboard stays up.
     The x-data around it has key(name) and ctrl; $visible is the Alpine expression that shows it. --}}
<div class="ssh-keybar" x-show="{{ $visible }}">
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
