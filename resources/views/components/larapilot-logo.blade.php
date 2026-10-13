{{-- Larapilot's robot face (larapilot.web.ap.it), in black and white: a dark tile with a light face, the other way round in dark mode. --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 32 32', 'xmlns' => 'http://www.w3.org/2000/svg', 'aria-hidden' => 'true']) }}>
    <rect width="32" height="32" rx="7" class="fill-zinc-900 dark:fill-white" />
    <g class="fill-white dark:fill-zinc-900">
        <circle cx="16" cy="5.5" r="1.6" />
        <rect x="5" y="14.2" width="2.8" height="5.2" rx="1.2" />
        <rect x="24.2" y="14.2" width="2.8" height="5.2" rx="1.2" />
        <rect x="7.5" y="9.5" width="17" height="15.5" rx="3.5" />
    </g>
    <path d="M16 7.2v2.3" stroke-width="1.6" stroke-linecap="round" class="stroke-white dark:stroke-zinc-900" />
    <g class="fill-zinc-900 dark:fill-white">
        <circle cx="12.6" cy="15.4" r="2" />
        <circle cx="19.4" cy="15.4" r="2" />
        <rect x="12.2" y="20.2" width="7.6" height="1.7" rx="0.85" />
    </g>
    <g class="fill-white dark:fill-zinc-900">
        <circle cx="13.2" cy="14.9" r="0.55" />
        <circle cx="20" cy="14.9" r="0.55" />
    </g>
    <path d="M11 25h10" stroke-width="1.4" stroke-linecap="round" opacity=".35" class="stroke-zinc-900 dark:stroke-white" />
</svg>
