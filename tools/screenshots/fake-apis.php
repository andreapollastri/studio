<?php

/*
 * What GitHub, Azure DevOps and DigitalOcean answer in the screenshots (see router.php):
 * the demo agency's repository acme/shop with its pull requests, issues, branches, runs and
 * releases, what waits for each person, and three Droplets. The demo tokens are
 * "demo_token_<login>", so each account answers as its own person.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$ago = fn (string $when) => now()->modify('-'.$when)->toIso8601String();
$repo = 'https://api.github.com/repos/agency/shop-acme';
$pull = fn (int $n, string $title, string $author, string $branch, string $updated, array $extra = []) => [
    'number' => $n, 'title' => $title, 'state' => 'open', 'draft' => false, 'user' => ['login' => $author],
    'html_url' => "https://github.com/agency/shop-acme/pull/$n", 'repository_url' => $repo,
    'head' => ['ref' => $branch], 'base' => ['ref' => 'develop', 'repo' => ['full_name' => 'agency/shop-acme']],
    'merged_at' => null, 'updated_at' => $ago($updated), 'labels' => [], 'assignees' => [], 'pull_request' => ['merged_at' => null],
    'comments' => 2, ...$extra,
];
$issue = fn (int $n, string $title, string $author, string $updated, array $labels = []) => [
    'number' => $n, 'title' => $title, 'state' => 'open', 'user' => ['login' => $author], 'repository_url' => $repo,
    'html_url' => "https://github.com/agency/shop-acme/issues/$n", 'labels' => array_map(fn ($l) => ['name' => $l], $labels),
    'assignees' => [['login' => 'lucabianchi']], 'comments' => 1, 'updated_at' => $ago($updated),
];
$pulls = [
    $pull(51, 'Returns form: validation and refund amount', 'lucabianchi', 'feature/US-015-returns', '2 hours'),
    $pull(50, 'Date range filter on the orders list', 'mariarossi', 'feature/US-014-date-filter', '40 minutes'),
    $pull(48, 'Returns badge on the order page', 'lucabianchi', 'feature/US-011-badge', '1 day'),
    $pull(46, 'Upgrade to Laravel 13.4', 'dependabot[bot]', 'dependabot/composer/laravel-13.4', '3 days', ['draft' => true]),
];
$issues = [
    $issue(53, 'Search ignores accents in customer names', 'giulia-acme', '3 hours', ['bug']),
    $issue(49, 'Weekly sales dashboard', 'mariarossi', '2 days', ['feature']),
    $issue(44, 'Export orders as CSV for the accountant', 'giulia-acme', '5 days', ['feature']),
];
$login = fn (Request $request) => str_replace('demo_token_', '', (string) preg_replace('/^(Bearer|token)\s+/i', '', (string) ($request->header('Authorization')[0] ?? '')));
$droplet = fn (int $id, string $name, string $size, string $ip, string $status = 'active') => [
    'id' => $id, 'name' => $name, 'status' => $status, 'size_slug' => $size, 'memory' => 4096, 'vcpus' => 2, 'disk' => 80,
    'region' => ['slug' => 'fra1', 'name' => 'Frankfurt 1'], 'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64', 'slug' => 'ubuntu-24-04-x64'],
    'networks' => ['v4' => [['ip_address' => $ip, 'type' => 'public']]], 'created_at' => $ago('120 days'), 'features' => ['backups', 'monitoring'], 'tags' => ['studio'],
];

return [
    // GitHub
    'api.github.com/user/emails' => Http::response([]),
    'api.github.com/user/repos*' => Http::response([
        ['full_name' => 'agency/shop-acme', 'html_url' => 'https://github.com/agency/shop-acme', 'private' => true, 'language' => 'PHP', 'default_branch' => 'develop', 'open_issues_count' => 7, 'archived' => false, 'pushed_at' => $ago('40 minutes')],
        ['full_name' => 'agency/supplier-portal', 'html_url' => 'https://github.com/agency/supplier-portal', 'private' => true, 'language' => 'PHP', 'default_branch' => 'develop', 'open_issues_count' => 2, 'archived' => false, 'pushed_at' => $ago('1 day')],
        ['full_name' => 'agency/acme-api', 'html_url' => 'https://github.com/agency/acme-api', 'private' => true, 'language' => 'PHP', 'default_branch' => 'main', 'open_issues_count' => 0, 'archived' => false, 'pushed_at' => $ago('4 days')],
    ]),
    'api.github.com/user/orgs*' => Http::response([['login' => 'agency']]),
    'api.github.com/user' => fn (Request $request) => Http::response(['id' => 7, 'login' => $login($request) ?: 'andreapollastri', 'name' => null, 'public_repos' => 12, 'total_private_repos' => 31]),
    'api.github.com/rate_limit' => Http::response(['resources' => ['core' => ['remaining' => 4987, 'limit' => 5000], 'search' => ['remaining' => 29, 'limit' => 30]]]),
    'api.github.com/notifications*' => Http::response([]),
    'api.github.com/search/issues*' => function (Request $request) use ($pulls, $issues) {
        $q = (string) ($request->data()['q'] ?? '');
        $items = match (true) {
            str_contains($q, 'review-requested') => [$pulls[0], $pulls[2]],
            str_contains($q, 'is:pr') && str_contains($q, 'author') => [$pulls[1]],
            str_contains($q, 'is:pr') => $pulls,
            default => $issues,
        };

        return Http::response(['total_count' => count($items), 'items' => $items]);
    },
    'api.github.com/repos/agency/shop-acme/pulls*' => Http::response($pulls),
    'api.github.com/repos/agency/shop-acme/issues*' => Http::response($issues),
    'api.github.com/repos/agency/shop-acme/branches*' => Http::response(array_map(fn ($b) => ['name' => $b, 'protected' => $b === 'develop' || $b === 'main', 'commit' => ['sha' => substr(md5($b), 0, 40)]], ['develop', 'main', 'feature/US-014-date-filter', 'feature/US-015-returns', 'feature/US-011-badge'])),
    'api.github.com/repos/agency/shop-acme/actions/runs*' => Http::response(['workflow_runs' => [
        ['id' => 901, 'run_number' => 212, 'display_title' => 'Date range filter on the orders list', 'name' => 'Tests', 'head_branch' => 'feature/US-014-date-filter', 'event' => 'pull_request', 'status' => 'in_progress', 'conclusion' => null, 'actor' => ['login' => 'mariarossi'], 'created_at' => $ago('35 minutes'), 'html_url' => 'https://github.com/agency/shop-acme/actions/runs/901'],
        ['id' => 900, 'run_number' => 211, 'display_title' => 'Returns form: validation and refund amount', 'name' => 'Tests', 'head_branch' => 'feature/US-015-returns', 'event' => 'pull_request', 'status' => 'completed', 'conclusion' => 'failure', 'actor' => ['login' => 'lucabianchi'], 'created_at' => $ago('2 hours'), 'html_url' => 'https://github.com/agency/shop-acme/actions/runs/900'],
        ['id' => 899, 'run_number' => 210, 'display_title' => 'Returns badge on the order page', 'name' => 'Tests', 'head_branch' => 'feature/US-011-badge', 'event' => 'pull_request', 'status' => 'completed', 'conclusion' => 'success', 'actor' => ['login' => 'lucabianchi'], 'created_at' => $ago('1 day'), 'html_url' => 'https://github.com/agency/shop-acme/actions/runs/899'],
    ]]),
    'api.github.com/repos/agency/shop-acme/releases*' => Http::response([
        ['id' => 31, 'tag_name' => 'v1.3.0', 'name' => 'Release 1.3', 'draft' => false, 'prerelease' => false, 'published_at' => $ago('12 days'), 'author' => ['login' => 'andreapollastri'], 'html_url' => 'https://github.com/agency/shop-acme/releases/tag/v1.3.0'],
        ['id' => 30, 'tag_name' => 'v1.2.0', 'name' => 'Release 1.2', 'draft' => false, 'prerelease' => false, 'published_at' => $ago('40 days'), 'author' => ['login' => 'andreapollastri'], 'html_url' => 'https://github.com/agency/shop-acme/releases/tag/v1.2.0'],
    ]),
    'api.github.com/repos/agency/shop-acme' => Http::response(['full_name' => 'agency/shop-acme', 'html_url' => 'https://github.com/agency/shop-acme', 'default_branch' => 'develop', 'private' => true, 'clone_url' => 'https://github.com/agency/shop-acme.git', 'ssh_url' => 'git@github.com:agency/shop-acme.git']),
    'api.github.com/*' => Http::response([]),

    // Azure DevOps
    'dev.azure.com/*' => Http::response(['count' => 0, 'value' => []]),

    // DigitalOcean
    'api.digitalocean.com/v2/account' => Http::response(['account' => ['email' => 'ops@agency.test', 'status' => 'active', 'droplet_limit' => 25]]),
    'api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => [
        $droplet(424242, 'shop-acme-production', 's-2vcpu-4gb', '203.0.113.42'),
        $droplet(424243, 'supplier-portal-production', 's-2vcpu-4gb', '203.0.113.43'),
        $droplet(424250, 'agency-ci-runner', 's-4vcpu-8gb', '203.0.113.50', 'off'),
    ], 'links' => []]),
    'api.digitalocean.com/*' => Http::response(['links' => []]),

    // Cipi: the server web1 and the shop's app, with the Cipi agent in it
    'api.web1.agency.test/api/apps/shopacme/env' => Http::response(['data' => ['app' => 'shopacme', 'vars' => [
        'APP_NAME' => 'Shop Acme', 'APP_ENV' => 'production', 'APP_KEY' => 'base64:demo', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://shop.acme.test',
        'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'shopacme', 'DB_USERNAME' => 'shopacme', 'DB_PASSWORD' => 'demo-secret',
        'MAIL_MAILER' => 'smtp', 'MAIL_FROM_ADDRESS' => 'shop@acme.test', 'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis',
        'CIPI_APP_USER' => 'shopacme', 'CIPI_WEBHOOK_TOKEN' => 'demo', 'CIPI_MCP' => 'true', 'CIPI_MCP_TOKEN' => 'demo-mcp', 'CIPI_ANONYMIZER' => 'true', 'CIPI_ANONYMIZER_TOKEN' => 'demo-anon',
    ]]]),
    'api.web1.agency.test/api/apps/shopacme/deploy/audit*' => Http::response(['data' => ['app' => 'shopacme', 'records' => [
        ['ts' => $ago('9 days'), 'event' => 'published', 'release' => 41, 'commit' => 'a1b2c3d4e5f6', 'trigger' => 'webhook', 'claimed' => ['actor' => 'lucabianchi']],
        ['ts' => $ago('4 days'), 'event' => 'failed', 'release' => 42, 'commit' => '0f9e8d7c6b5a', 'trigger' => 'webhook', 'claimed' => ['actor' => 'mariarossi']],
        ['ts' => $ago('4 days'), 'event' => 'published', 'release' => 43, 'commit' => '1a2b3c4d5e6f', 'trigger' => 'api', 'operator' => 'studio', 'claimed' => ['actor' => 'lucabianchi']],
        ['ts' => $ago('2 hours'), 'event' => 'published', 'release' => 44, 'commit' => '9c8b7a6d5e4f', 'trigger' => 'webhook', 'claimed' => ['actor' => 'mariarossi']],
    ]]]),
    'api.web1.agency.test/api/apps/shopacme/logs*' => Http::response(['data' => ['app' => 'shopacme', 'type' => 'laravel', 'available_types' => ['all', 'laravel', 'nginx', 'php', 'worker', 'deploy'], 'files' => [
        ['path' => '/home/shopacme/shared/storage/logs/laravel.log', 'total_lines' => 1840, 'page' => 1, 'per_page' => 200, 'total_pages' => 10, 'lines' => [
            '[2026-10-13 09:41:07] production.INFO: Order #10482 paid {"amount":"129.00","gateway":"stripe"}',
            '[2026-10-13 09:41:12] production.INFO: Shipment label created for order #10482',
            '[2026-10-13 09:44:30] production.WARNING: Slow query (1.8s) on orders by created_at range',
            '[2026-10-13 09:52:03] production.ERROR: Stripe webhook signature mismatch {"exception":"[object] (Stripe\\Exception\\SignatureVerificationException(code: 0))"}',
            '[2026-10-13 09:52:03] production.INFO: Retrying webhook evt_3Q8 in 60s',
            '[2026-10-13 10:05:44] production.INFO: Order #10483 paid {"amount":"42.50","gateway":"paypal"}',
        ]],
    ]]]),
    'api.web1.agency.test/api/apps/shopacme' => Http::response(['data' => ['app' => 'shopacme', 'domain' => 'shop.acme.test', 'type' => 'laravel', 'php' => '8.5', 'branch' => 'main', 'repository' => 'git@github.com:agency/shop-acme.git', 'suspended' => false, 'force_https' => true]]),
    'api.web1.agency.test/api/apps' => Http::response(['data' => [
        ['app' => 'shopacme', 'domain' => 'shop.acme.test', 'type' => 'laravel', 'php' => '8.5', 'branch' => 'main', 'suspended' => false, 'force_https' => true],
        ['app' => 'portal', 'domain' => 'suppliers.acme.test', 'type' => 'laravel', 'php' => '8.4', 'branch' => 'main', 'suspended' => false, 'force_https' => true],
    ]]),
    'api.web1.agency.test/api/status' => Http::response(['data' => ['system' => ['ip' => '203.0.113.9', 'hostname' => 'web1', 'os' => 'Ubuntu 24.04', 'cipi' => '5.6.1', 'uptime' => '41 days'], 'apps' => 2,
        'resources' => ['cpu' => ['usage_percent' => 23], 'memory' => ['used_mb' => 5120, 'total_mb' => 8192, 'usage_percent' => 62], 'disk' => ['usage_percent' => 71, 'display' => '113G / 160G']],
        'services' => ['nginx' => 'running', 'mariadb' => 'running', 'redis' => 'running', 'supervisor' => 'running']]]),
    'api.web1.agency.test/api/disk' => Http::response(['data' => ['disk' => ['size_gb' => 160, 'used_gb' => 113, 'used_percent' => 71], 'apps' => [
        ['app' => 'shopacme', 'files_gb' => 6.4, 'database_gb' => 1.9, 'total_gb' => 8.3, 'limit_gb' => 20, 'limit_percent' => 42, 'over_limit' => false],
    ]]]),
    'api.web1.agency.test/*' => Http::response(['data' => []]),
    'shop.acme.test/cipi/mcp' => function (Request $request) use ($ago) {
        $call = json_decode($request->body(), true);
        $answer = fn (array $result) => Http::response(['jsonrpc' => '2.0', 'id' => $call['id'] ?? null, 'result' => $result]);
        $text = fn ($data) => $answer(['content' => [['type' => 'text', 'text' => json_encode($data)]]]);

        return match ($call['method'] ?? '') {
            'initialize' => $answer(['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'cipi-agent', 'version' => '2.0.0'], 'capabilities' => ['tools' => new stdClass]]),
            'tools/list' => $answer(['tools' => array_map(fn ($n) => ['name' => $n], ['health', 'app_info', 'deploy', 'deploy_status', 'logs', 'artisan', 'db_query', 'db_schema', 'env_check', 'cipi_yml', 'anonymizer'])]),
            'tools/call' => match ($call['params']['name'] ?? '') {
                'deploy_status' => $text(['release' => '44', 'commit' => '9c8b7a6d5e4f', 'deploy_branch' => 'main', 'pending' => false, 'in_progress' => false, 'releases' => [
                    ['name' => '44', 'commit' => '9c8b7a6d5e4f3a2b', 'current' => true, 'created_at' => $ago('2 hours')],
                    ['name' => '43', 'commit' => '1a2b3c4d5e6f7a8b', 'current' => false, 'created_at' => $ago('4 days')],
                    ['name' => '41', 'commit' => 'a1b2c3d4e5f6a7b8', 'current' => false, 'created_at' => $ago('9 days')],
                    ['name' => '40', 'commit' => '5f4e3d2c1b0a9f8e', 'current' => false, 'created_at' => $ago('16 days')],
                    ['name' => '39', 'commit' => 'c0ffee12345678ab', 'current' => false, 'created_at' => $ago('23 days')],
                ]]),
                'anonymizer' => $text(($call['params']['arguments']['action'] ?? '') === 'audit'
                    ? ['valid' => true, 'covered' => ['users.name', 'users.email', 'users.password', 'customers.email', 'customers.phone', 'addresses.street'], 'uncovered' => ['customers.notes', 'orders.billing_name'], 'truncated' => ['sessions', 'jobs', 'failed_jobs']]
                    : ['transformations' => [
                        'users' => ['name' => 'fakeName', 'email' => ['type' => 'fakeEmail', 'unique' => true, 'consistent' => true], 'password' => 'password', 'remember_token' => 'null'],
                        'customers' => ['first_name' => 'fakeFirstName', 'last_name' => 'fakeLastName', 'email' => ['type' => 'fakeEmail', 'consistent' => true], 'phone' => 'mask:3,2', 'tax_code' => 'redact'],
                        'addresses' => ['street' => 'fakeStreetAddress', 'city' => 'fakeCity', 'postcode' => 'fakePostcode'],
                        'orders' => ['billing_name' => 'fakeName', 'card_last_four' => 'numerify:####'],
                    ], 'truncate' => ['sessions', 'jobs', 'failed_jobs', 'password_reset_tokens'], 'options' => ['faker_locale' => 'it_IT', 'password' => 'password', 'consistent' => true]]),
                default => $text([]),
            },
            default => Http::response('', 202),
        };
    },
    'shop.acme.test/cipi/db' => Http::response(['status' => 'queued', 'link_valid_minutes' => 15]),

    // Cloudflare, Boogle, Johnny
    'api.cloudflare.com/client/v4/zones/zone-acme/dns_records*' => Http::response(['result' => [], 'result_info' => ['total_count' => 14, 'total_pages' => 1]]),
    'api.cloudflare.com/client/v4/zones*' => Http::response(['result' => [
        ['id' => 'zone-acme', 'name' => 'acme.test', 'status' => 'active', 'paused' => false, 'plan' => ['name' => 'Pro Website'], 'name_servers' => ['ada.ns.cloudflare.com', 'rex.ns.cloudflare.com'], 'account' => ['name' => 'Agency']],
    ], 'result_info' => ['total_count' => 1, 'total_pages' => 1]]),
    'api.cloudflare.com/*' => Http::response(['result' => [], 'result_info' => ['total_count' => 0, 'total_pages' => 1]]),
    'boogle.agency.test/api/admin/projects/12/exceptions*' => Http::response(['total' => 3, 'data' => [
        ['id' => 901, 'issue_code' => 'SHOP-41', 'exception' => 'Stripe\\Exception\\SignatureVerificationException', 'error' => 'No signatures found matching the expected signature', 'status' => 'OPEN'],
    ]]),
    'boogle.agency.test/api/admin/projects*' => Http::response(['data' => [
        ['id' => 12, 'title' => 'Shop Acme', 'url' => 'https://shop.acme.test', 'group' => ['title' => 'Acme'], 'uptime_enabled' => true],
    ], 'next_page_url' => null]),
    'boogle.agency.test/*' => Http::response(['data' => [], 'next_page_url' => null]),
    'panel.johnny.agency.test/api/buckets/shop-acme-media' => Http::response(['data' => ['name' => 'shop-acme-media', 'objects' => 18342, 'bytes' => 2_147_483_648, 'keys' => [['id' => 'GKmedia'], ['id' => 'GKapp']]]]),
    'panel.johnny.agency.test/api/buckets*' => Http::response(['data' => [['name' => 'shop-acme-media', 'created' => $ago('80 days')], ['name' => 'agency-backups', 'created' => $ago('200 days')]]]),
    'panel.johnny.agency.test/*' => Http::response(['data' => []]),
];
