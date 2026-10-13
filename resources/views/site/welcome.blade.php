{{-- Served on any <name>.<domain> nobody configured, through Caddy's forward_auth: no Vite build, no
     asset of this host (every other path of it answers with this same page), so everything is inline.
     $hidden: the dashboard is hidden (DashboardVisibility), so the page says nothing of Studio. --}}
@php($hidden = $hidden ?? false)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $hidden ? __('Not found') : 'Studio' }}</title>
    @unless ($hidden)
        <link rel="icon" href="{{ rtrim((string) config('app.url'), '/') }}/favicon.svg" type="image/svg+xml">
    @endunless
    <style>
        :root {
            --bg: #fafafa;
            --card: #ffffff;
            --text: #171717;
            --muted: #737373;
            --line: #e5e5e5;
            --accent: #111a08;
            --accent-text: #a3e635;
            color-scheme: light dark;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0a0a0a;
                --card: #171717;
                --text: #fafafa;
                --muted: #a3a3a3;
                --line: #262626;
                --accent: #a3e635;
                --accent-text: #111a08;
            }
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        main {
            width: 100%;
            max-width: 420px;
            padding: 40px 32px;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
        }
        svg { width: 56px; height: 56px; display: block; margin: 0 auto 20px; }
        .brand { margin: 0 0 4px; font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.3; font-weight: 600; }
        p { margin: 0; color: var(--muted); }
        code {
            display: inline-block;
            max-width: 100%;
            margin: 16px 0 24px;
            padding: 4px 10px;
            overflow-wrap: anywhere;
            font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
            color: var(--text);
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        a {
            display: inline-block;
            padding: 10px 18px;
            font-weight: 600;
            text-decoration: none;
            color: var(--accent-text);
            background: var(--accent);
            border-radius: 10px;
        }
        a:hover { opacity: .9; }
        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        @unless ($hidden)
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">
            <rect width="32" height="32" rx="7" fill="#111a08"/>
            <g fill="#a3e635">
                <circle cx="10.5" cy="17.5" r="5.5"/>
                <circle cx="17" cy="13" r="7"/>
                <circle cx="23.5" cy="17.5" r="5"/>
                <rect x="5" y="18.5" width="23.5" height="6.5" rx="3.25"/>
            </g>
            <g fill="#111a08">
                <circle cx="17" cy="9.2" r="1.1"/>
                <rect x="16.4" y="10" width="1.2" height="2.2" rx=".6"/>
                <rect x="9.9" y="15.2" width="1.8" height="3.2" rx=".8"/>
                <rect x="22.3" y="15.2" width="1.8" height="3.2" rx=".8"/>
                <rect x="11.8" y="12" width="10.4" height="9.2" rx="2.4"/>
            </g>
            <g fill="#a3e635">
                <circle cx="14.9" cy="15.6" r="1.25"/>
                <circle cx="19.1" cy="15.6" r="1.25"/>
                <rect x="14.6" y="18.4" width="4.8" height="1.15" rx=".575"/>
            </g>
        </svg>
        <p class="brand">Studio</p>
        @endunless
        <h1>{{ __('Nothing here yet') }}</h1>
        <p>{{ __('This address is not linked to any project or preview on this server.') }}</p>
        <code>{{ $host }}</code>
        @unless ($hidden)
            <br>
            <a href="{{ config('app.url') }}">{{ __('Open Studio') }}</a>
        @endunless
    </main>
</body>
</html>
