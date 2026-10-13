@props(['path' => ''])

{{-- A file's icon in the colour of its kind: code by language, config, docs, images, data, scripts --}}
@php
    $name = strtolower(basename((string) $path));
    $extension = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : '';

    [$icon, $color] = match (true) {
        in_array($name, ['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb', 'gemfile.lock', 'cargo.lock', 'poetry.lock'], true) => ['lock-closed', 'text-zinc-400'],
        $name === '.env' || str_starts_with($name, '.env.') => ['key', 'text-yellow-500'],
        in_array($name, ['dockerfile', 'makefile', 'procfile', 'caddyfile', 'artisan'], true) || str_starts_with($name, 'dockerfile.') => ['command-line', 'text-sky-500'],
        in_array($name, ['.gitignore', '.gitattributes', '.gitmodules', '.editorconfig', '.npmrc', '.nvmrc', '.prettierrc', '.eslintrc', 'license'], true) => ['cog-6-tooth', 'text-zinc-400'],
        str_ends_with($name, '.blade.php') => ['code-bracket', 'text-orange-500'],
        default => match ($extension) {
            'php' => ['code-bracket', 'text-indigo-500 dark:text-indigo-400'],
            'js', 'mjs', 'cjs', 'jsx' => ['code-bracket', 'text-yellow-500'],
            'ts', 'tsx', 'mts', 'cts' => ['code-bracket', 'text-blue-500'],
            'vue' => ['code-bracket', 'text-emerald-500'],
            'svelte', 'astro' => ['code-bracket', 'text-orange-500'],
            'py' => ['code-bracket', 'text-sky-500'],
            'rb', 'erb' => ['code-bracket', 'text-red-500'],
            'go' => ['code-bracket', 'text-cyan-500'],
            'rs' => ['code-bracket', 'text-orange-600'],
            'java', 'kt', 'kts', 'scala', 'swift', 'dart' => ['code-bracket', 'text-orange-500'],
            'c', 'h', 'cc', 'cpp', 'hpp', 'm' => ['code-bracket', 'text-blue-600 dark:text-blue-400'],
            'cs', 'fs', 'ex', 'exs', 'elm', 'hs' => ['code-bracket', 'text-violet-500'],
            'lua', 'pl', 'r' => ['code-bracket', 'text-blue-400'],
            'html', 'htm', 'xml', 'xhtml', 'twig', 'hbs', 'njk', 'liquid' => ['globe-alt', 'text-orange-500'],
            'css', 'scss', 'sass', 'less', 'styl', 'pcss' => ['swatch', 'text-pink-500'],
            'json', 'jsonc', 'json5', 'yaml', 'yml', 'toml', 'ini', 'neon', 'conf', 'cfg', 'properties', 'plist' => ['cog-6-tooth', 'text-amber-500'],
            'md', 'mdx', 'markdown', 'txt', 'rst', 'adoc', 'org' => ['document-text', 'text-sky-500'],
            'pdf' => ['document-text', 'text-red-500'],
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tiff', 'heic' => ['photo', 'text-purple-500'],
            'svg' => ['photo', 'text-amber-500'],
            'mp4', 'mov', 'webm', 'mkv', 'avi' => ['film', 'text-purple-500'],
            'mp3', 'wav', 'ogg', 'flac', 'm4a' => ['musical-note', 'text-purple-500'],
            'sql', 'sqlite', 'db', 'prisma', 'graphql', 'gql' => ['circle-stack', 'text-teal-500'],
            'csv', 'tsv', 'xls', 'xlsx', 'ods' => ['table-cells', 'text-emerald-600 dark:text-emerald-400'],
            'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd' => ['command-line', 'text-emerald-500'],
            'zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'phar', 'jar' => ['archive-box', 'text-zinc-500'],
            'woff', 'woff2', 'ttf', 'otf', 'eot' => ['variable', 'text-zinc-500'],
            'pem', 'key', 'crt', 'pub' => ['key', 'text-yellow-500'],
            'lock' => ['lock-closed', 'text-zinc-400'],
            default => ['document', 'text-zinc-400'],
        },
    };
@endphp

<flux:icon :icon="$icon" {{ $attributes->class(['size-3.5 shrink-0', $color]) }} />
