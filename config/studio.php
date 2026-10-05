<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The server
    |--------------------------------------------------------------------------
    | One Ubuntu VPS hosts Studio, every project site and every preview:
    |   studio.<domain>            this app
    |   <project>.<domain>         the deploy branch of a project
    |   <user>-<project>.<domain>  one person's workspace on a project
    */
    'domain' => env('STUDIO_DOMAIN', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | The git provider
    |--------------------------------------------------------------------------
    | One per installation, chosen in the installer: github, gitlab, bitbucket
    | or azure, the same remote forges Larapilot integrates with. Every project
    | is a repository there and every person connects a token for it.
    | STUDIO_GIT_URL: a self-managed GitLab (default https://gitlab.com), or the
    | Azure DevOps organization (https://dev.azure.com/<organization>, required).
    */
    'git' => [
        'provider' => env('STUDIO_GIT_PROVIDER', 'github'),
        'url' => env('STUDIO_GIT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    | The installed release and where the root updater leaves its state; the
    | updater only ever moves to the newest stable release tag, never to main.
    */
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

    'updates' => [
        'state' => env('STUDIO_UPDATE_STATE', '/var/lib/studio-update/state.json'),
        'repository' => env('STUDIO_UPDATE_REPOSITORY', 'https://github.com/andreapollastri/studio'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    | Invite-only: an administrator creates every account.
    */
    'registration' => false,

    /*
    |--------------------------------------------------------------------------
    | Workspace driver
    |--------------------------------------------------------------------------
    | "native" runs workspaces on this server through the privileged helper
    | the installer puts in place. "local" points every workspace at one
    | bridge on this machine: the development mode, and what the tests use.
    */
    'driver' => env('STUDIO_WORKSPACE_DRIVER', 'local'),

    // PHP versions installed on the server, for the sites and workspaces; the
    // installer and the updater keep this list in step with what is installed.
    'php_versions' => array_values(array_filter(array_map('trim', explode(',', (string) env('STUDIO_PHP_VERSIONS', '8.3,8.4,8.5'))))),

    'native' => [
        'helper' => env('STUDIO_HELPER', '/usr/local/sbin/studio-admin'),
        'sudo' => (bool) env('STUDIO_HELPER_SUDO', true),
        'timeout' => (int) env('STUDIO_HELPER_TIMEOUT', 1200),
        // Each workspace's bridge listens on 127.0.0.1 at base + workspace id.
        'bridge_port_base' => (int) env('STUDIO_BRIDGE_PORT_BASE', 42000),
        // Reverb, when a project has it on: the site listens at 9000 + project id,
        // each workspace preview at this base + workspace id.
        'workspace_reverb_port_base' => (int) env('STUDIO_WORKSPACE_REVERB_PORT_BASE', 20000),
    ],

    'local' => [
        'bridge_url' => env('STUDIO_LOCAL_BRIDGE_URL', 'http://127.0.0.1:4455'),
        'bridge_token' => env('STUDIO_LOCAL_BRIDGE_TOKEN', 'local-bridge-token'),
        'app_url' => env('STUDIO_LOCAL_APP_URL', 'http://127.0.0.1:8000'),
    ],

    'bridge' => [
        'callback_url' => env('STUDIO_CALLBACK_URL'),
        'timeout' => (int) env('STUDIO_BRIDGE_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    */
    'demo' => [
        'staging_url' => env('DEMO_STAGING_URL'),
        'preview_url' => env('DEMO_PREVIEW_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission presets
    |--------------------------------------------------------------------------
    | The --permission-mode a new conversation starts with, per role, and the
    | modes each role may switch to from the chat. "auto" is Claude Code's
    | autopilot: a classifier reviews actions instead of asking; "plan" reads
    | and proposes without changing anything.
    */
    'permission_mode' => [
        'admin' => 'acceptEdits',
        'pm' => 'default',
        'dev' => 'acceptEdits',
        'client' => 'plan',
    ],

    'modes' => [
        'default' => ['label' => 'Ask before actions', 'hint' => 'Claude asks before commands and edits outside the automatic scope'],
        'acceptEdits' => ['label' => 'Ask, edits allowed', 'hint' => 'File edits in the workspace run; commands still ask'],
        'auto' => ['label' => 'Autopilot', 'hint' => 'A classifier reviews every action; nothing waits for you'],
        'plan' => ['label' => 'Plan only', 'hint' => 'Reads and proposes, changes nothing'],
    ],

    'modes_by_role' => [
        'admin' => ['default', 'acceptEdits', 'auto', 'plan'],
        'pm' => ['default', 'acceptEdits', 'auto', 'plan'],
        'dev' => ['default', 'acceptEdits', 'auto', 'plan'],
        'client' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and effort
    |--------------------------------------------------------------------------
    | What the chat offers. An empty model leaves Claude Code's own default.
    */
    'models' => [
        ['id' => '', 'label' => 'Default'],
        ['id' => 'claude-fable-5-1', 'label' => 'Fable 5.1'],
        ['id' => 'claude-opus-5-5', 'label' => 'Opus 5.5'],
        ['id' => 'claude-sonnet-5-5', 'label' => 'Sonnet 5.5'],
        ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5'],
    ],

    'efforts' => ['' => 'Default', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'max' => 'Max'],

    /*
    |--------------------------------------------------------------------------
    | Project terminal
    |--------------------------------------------------------------------------
    | Shortcuts shown in the Terminal tab. Whatever is typed is checked by the
    | bridge against its own allowlist (php artisan, composer, npm, git,
    | vendor/bin/{pest,pint,phpstan}; no shell, no pipes, no redirections).
    */
    'terminal' => [
        ['label' => 'Doctor', 'command' => 'php artisan larapilot:doctor --human'],
        ['label' => 'Backlog', 'command' => 'php artisan larapilot:spec-list'],
        ['label' => 'Quality', 'command' => 'php artisan larapilot:quality'],
        ['label' => 'Tests', 'command' => 'php artisan test --compact'],
        ['label' => 'Migrate', 'command' => 'php artisan migrate --force'],
        ['label' => 'Fresh + seed', 'command' => 'php artisan migrate:fresh --seed'],
        ['label' => 'Routes', 'command' => 'php artisan route:list'],
        ['label' => 'About', 'command' => 'php artisan about'],
        ['label' => 'Composer install', 'command' => 'composer install'],
        ['label' => 'Build assets', 'command' => 'npm run build'],
        ['label' => 'Git status', 'command' => 'git status --short --branch'],
        ['label' => 'Git log', 'command' => 'git log --oneline -20'],
        ['label' => 'Pull', 'command' => 'git pull --rebase'],
        ['label' => 'Clear caches', 'command' => 'php artisan optimize:clear'],
    ],

];
