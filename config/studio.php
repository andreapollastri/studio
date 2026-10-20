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

    // This server's public IPv4 addresses, which other domains must point at (Administration →
    // Domains); empty = what studio.<domain> resolves to, right for every usual installation.
    'server_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('STUDIO_SERVER_IPS', ''))))),

    // Any other <name>.<domain> shows Studio's welcome page. Its certificate is
    // issued on demand like the others, but only for so many new names a day:
    // Let's Encrypt allows 50 new certificates a week per registered domain.
    'welcome_certificates_per_day' => (int) env('STUDIO_WELCOME_CERTIFICATES_PER_DAY', 3),

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
    | Security
    |--------------------------------------------------------------------------
    | Who may reach the endpoints meant for Caddy, the bridges and the SSH
    | gateway (127.0.0.1:8081); how many sign-in attempts an address gets; the
    | password rules; the remember-me cookie; the headers of every page. The
    | security log (storage/logs/security.log, App\Support\SecurityLog) is what
    | fail2ban reads on a server. Who must set two-factor up is a setting of
    | Administration → System (App\Server\SecurityPolicy).
    */
    'security' => [
        // off for a development setup whose bridge does not run on this machine (a container)
        'internal_loopback_only' => (bool) env('STUDIO_INTERNAL_LOOPBACK_ONLY', true),
        // sign-in attempts a minute: on one account from one address, and from one address whatever the accounts
        'login_per_minute' => (int) env('STUDIO_LOGIN_PER_MINUTE', 5),
        'login_per_ip_per_minute' => (int) env('STUDIO_LOGIN_PER_IP_PER_MINUTE', 20),
        // the shortest password (every password is also refused when it appears in leaked lists)
        'password_min' => (int) env('STUDIO_PASSWORD_MIN', 12),
        // Strict-Transport-Security, sent over https; empty for none
        'hsts' => env('STUDIO_HSTS', 'max-age=31536000; includeSubDomains'),
    ],

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
    | SSH terminal
    |--------------------------------------------------------------------------
    | The SSH page talks to the gateway (ssh-gateway/, the studio-ssh service)
    | over a WebSocket; Caddy serves it at /ssh/ws on Studio's own host. The
    | gateway trades a one-time ticket for the connection at /api/ssh/redeem.
    | STUDIO_SSH_URL only for development, e.g. ws://127.0.0.1:8082/ssh/ws.
    */
    'ssh' => [
        'url' => env('STUDIO_SSH_URL'),
        'ticket_ttl' => (int) env('STUDIO_SSH_TICKET_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    | Tools → Backups and the projects' Backups tab: rclone moves the data (S3
    | and SFTP, any direction), ssh makes the archives on the source host. The
    | runs go to the backups queue, which the studio-backups worker serves.
    */
    'backups' => [
        'rclone' => env('STUDIO_RCLONE', 'rclone'),
        'ssh' => env('STUDIO_SSH_BINARY', 'ssh'),
        'ssh_keyscan' => env('STUDIO_SSH_KEYSCAN', 'ssh-keyscan'),
        'timeout' => (int) env('STUDIO_BACKUP_TIMEOUT', 21600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Databases
    |--------------------------------------------------------------------------
    | Tools → Databases: MySQL, MariaDB, PostgreSQL, SQLite, Redis and Valkey,
    | direct or through SSH tunnels (OpenSSH masters, forwards to Unix sockets
    | under runtime/). Imports and exports run on the databases queue, which the
    | studio-databases worker serves; the native tools are used when present.
    */
    'databases' => [
        'ssh' => env('STUDIO_SSH_BINARY', 'ssh'),
        'runtime' => env('STUDIO_DB_RUNTIME'),
        // how long a tunnel nobody uses stays open, in seconds
        'tunnel_idle' => (int) env('STUDIO_DB_TUNNEL_IDLE', 900),
        'connect_timeout' => (int) env('STUDIO_DB_CONNECT_TIMEOUT', 10),
        // a statement of the SQL editor, in seconds
        'timeout' => (int) env('STUDIO_DB_TIMEOUT', 120),
        'transfer_timeout' => (int) env('STUDIO_DB_TRANSFER_TIMEOUT', 21600),
        // rows a statement of the editor shows at most
        'row_limit' => (int) env('STUDIO_DB_ROW_LIMIT', 1000),
        // days an export stays to download
        'keep_exports_days' => (int) env('STUDIO_DB_KEEP_EXPORTS_DAYS', 7),
        'max_import_mb' => (int) env('STUDIO_DB_MAX_IMPORT_MB', 100),
        // empty: found on the PATH (mariadb-dump before mysqldump, mariadb before mysql)
        'binaries' => [
            'mysqldump' => env('STUDIO_MYSQLDUMP'),
            'mysql' => env('STUDIO_MYSQL'),
            'pg_dump' => env('STUDIO_PG_DUMP'),
            'psql' => env('STUDIO_PSQL'),
            'sqlite3' => env('STUDIO_SQLITE3'),
        ],
    ],

    // The drive (Tools → Drive): files on the local disk under storage/app/private/drive.
    'drive' => [
        'max_upload_mb' => (int) env('STUDIO_DRIVE_MAX_UPLOAD_MB', 100),
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
        'default' => ['label' => 'Ask before actions', 'hint' => 'Claude asks before edits and before commands it may not run on its own'],
        'acceptEdits' => ['label' => 'Ask, edits allowed', 'hint' => 'File edits in the workspace run; commands still ask'],
        'auto' => ['label' => 'Autopilot', 'hint' => 'A classifier reviews every action; nothing waits for you'],
        'plan' => ['label' => 'Plan only', 'hint' => 'Reads and proposes, changes nothing'],
    ],

    /*
    | What runs without asking in every mode but "plan", per role, beyond Claude Code's own
    | read-only commands (ls, cat, git status…): the everyday commands of a Laravel project
    | that change nothing outside the workspace. Each person adds their own with "Always
    | allow" on a permission card; Claude Code's rule syntax, Bash(prefix:*) for a prefix.
    */
    'allowed_tools' => [
        'admin' => $everyday = [
            'Bash(php artisan test:*)', 'Bash(vendor/bin/pest:*)', 'Bash(vendor/bin/phpunit:*)',
            'Bash(vendor/bin/pint:*)', 'Bash(vendor/bin/phpstan:*)', 'Bash(vendor/bin/rector:*)',
            'Bash(php artisan route:list:*)', 'Bash(php artisan about:*)', 'Bash(php artisan optimize:clear:*)',
            'Bash(composer install:*)', 'Bash(composer dump-autoload:*)',
            'Bash(npm ci:*)', 'Bash(npm install:*)', 'Bash(npm run build:*)', 'Bash(npm run lint:*)', 'Bash(npm test:*)',
            'Bash(git add:*)', 'Bash(git commit:*)',
        ],
        'dev' => $everyday,
        'pm' => $everyday,
        'client' => [],
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
    | Shortcuts shown in the Terminal tab: each types its command into the
    | workspace's shell and runs it, as if typed by hand.
    */
    'terminal' => [
        ['label' => 'Backlog', 'command' => 'php artisan larapilot:spec-list'],
        ['label' => 'Quality', 'command' => 'php artisan larapilot:quality'],
        ['label' => 'Tests', 'command' => 'php artisan test --compact'],
        ['label' => 'Migrate', 'command' => 'php artisan migrate --force'],
        ['label' => 'Fresh + seed', 'command' => 'php artisan migrate:fresh --seed'],
        ['label' => 'Optimize Clear', 'command' => 'php artisan optimize:clear'],
    ],

];
