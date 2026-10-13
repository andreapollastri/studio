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
];
