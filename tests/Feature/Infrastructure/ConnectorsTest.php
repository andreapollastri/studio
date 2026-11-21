<?php

use App\Infrastructure\Aws\Signer;
use App\Infrastructure\Aws\Xml;
use App\Infrastructure\Connector;
use App\Infrastructure\Connectors;
use App\Livewire\Admin\Connectors as AdminConnectors;
use App\Livewire\Infrastructure\Console;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

function enable(string $connector): void
{
    ConnectorSetting::query()->updateOrCreate(['connector' => $connector], ['enabled' => true]);
}

function droplet(int $id, string $name, string $status = 'active'): array
{
    return [
        'id' => $id, 'name' => $name, 'status' => $status, 'size_slug' => 's-1vcpu-1gb', 'region' => ['slug' => 'fra1'],
        'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'], 'networks' => ['v4' => [['ip_address' => '203.0.113.7', 'type' => 'public']]],
        'created_at' => '2026-10-01T10:00:00Z', 'features' => [],
    ];
}

test('every connector describes itself consistently', function () {
    expect(array_keys(Connectors::all()))->toBe(['aws', 'digitalocean', 'hetzner', 'cloudflare', 'cipi', 'boogle', 'hackly', 'johnny']);

    foreach (Connectors::all() as $key => $connector) {
        /** @var Connector $connector */
        $sections = $connector->sections();
        $keys = array_map(fn ($s) => $s->key, $sections);

        expect($connector->key())->toBe($key)
            ->and($connector->fields())->not->toBeEmpty()
            ->and($keys)->toBe(array_values(array_unique($keys)))
            ->and($sections[0]->isChild())->toBeFalse();

        foreach ($sections as $section) {
            expect($section->list)->not->toBeNull("{$key}/{$section->key} has no list");

            if ($section->isChild()) {
                expect($keys)->toContain($section->parent);
            }

            foreach ([...$section->actions, ...$section->toolbar] as $action) {
                expect($action->run !== null || $action->opens !== null)->toBeTrue("{$key}/{$section->key}/{$action->key} does nothing");

                if ($action->opens !== null) {
                    expect($connector->section($action->opens)?->parent)->toBe($section->key);
                }
            }
        }
    }

    // All of Lightsail is under AWS.
    $lightsail = array_filter(Connectors::make('aws')->sections(), fn ($s) => $s->group === 'Lightsail');
    expect(array_map(fn ($s) => $s->key, array_values($lightsail)))->toContain(
        'lightsail', 'lightsail-snapshots', 'lightsail-static-ips', 'lightsail-disks', 'lightsail-disk-snapshots', 'lightsail-key-pairs',
        'lightsail-databases', 'lightsail-load-balancers', 'lightsail-containers', 'lightsail-buckets', 'lightsail-domains',
        'lightsail-domain-entries', 'lightsail-distributions', 'lightsail-certificates', 'lightsail-alarms', 'lightsail-operations',
    );
});

test('only enabled connectors show under Infrastructure, and only to the administrators', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('projects.index'))->assertOk()->assertDontSee('Infrastructure');

    enable('hetzner');
    enable('cloudflare');

    $this->actingAs($admin)->get(route('projects.index'))->assertSee('Infrastructure')->assertSee('Hetzner')->assertSee('Cloudflare')->assertDontSee('DigitalOcean');
    $this->actingAs($admin)->get(route('infrastructure', 'cloudflare'))->assertRedirect(route('password.confirm'));
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('infrastructure', 'cloudflare'))->assertOk()->assertSee('Cloudflare');
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('infrastructure', 'nope'))->assertForbidden();

    // every other role: no menu, no page, no component
    foreach (['pm', 'dev', 'client'] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertDontSee('Infrastructure')->assertDontSee('Cloudflare');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('infrastructure', 'cloudflare'))->assertForbidden();
        Livewire::actingAs($user)->test(Console::class, ['connector' => 'cloudflare'])->assertForbidden();
    }
});

test('administrators turn connectors on and off', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.connectors'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.connectors'))->assertOk()->assertSee('Cipi')->assertSee('Hackly');

    Livewire::actingAs($admin)->test(AdminConnectors::class)
        ->call('toggle', 'cipi')
        ->assertSee('Administrators; projects by role');

    expect(ConnectorSetting::for('cipi')->enabled)->toBeTrue();

    Livewire::actingAs($admin)->test(AdminConnectors::class)->call('toggle', 'cipi');
    expect(ConnectorSetting::for('cipi')->enabled)->toBeFalse();
});

test('an account is checked against the provider and stored encrypted', function () {
    enable('digitalocean');
    $admin = User::factory()->admin()->create();

    Http::fake([
        'api.digitalocean.com/v2/account' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer dop_v1_good')
            ? Http::response(['account' => ['email' => 'ops@acme.test', 'team' => ['name' => 'Acme']]])
            : Http::response(['id' => 'unauthorized', 'message' => 'Unable to authenticate you'], 401),
        'api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => [], 'links' => []]),
    ]);

    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'digitalocean'])
        ->assertSee('No DigitalOcean account yet')
        ->call('manageAccounts')
        ->set('account_name', 'Acme')
        ->set('credentials.token', 'dop_v1_bad')
        ->call('saveAccount')
        ->assertHasErrors('credentials');

    expect(ConnectorAccount::query()->count())->toBe(0);

    $page->set('credentials.token', 'dop_v1_good')->call('saveAccount')->assertHasNoErrors();

    $account = ConnectorAccount::query()->sole();
    $raw = DB::table('connector_accounts')->where('id', $account->id)->value('credentials');

    expect($account->credential('token'))->toBe('dop_v1_good')
        ->and($account->status)->toBe('ok · Acme')
        ->and($raw)->not->toContain('dop_v1_good');

    // Editing without retyping the token keeps it.
    $page->call('editAccount', $account->uuid)->assertSet('credentials.token', '')->set('account_name', 'Acme EU')->call('saveAccount')->assertHasNoErrors()->assertDontSee('dop_v1_good');
    expect($account->fresh()->name)->toBe('Acme EU')->and($account->fresh()->credential('token'))->toBe('dop_v1_good');

    // Nobody else gets as far as the page.
    Livewire::actingAs(User::factory()->create())->test(Console::class, ['connector' => 'digitalocean'])->assertForbidden();
});

test('droplets are listed and driven from the page', function () {
    enable('digitalocean');
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->of('digitalocean', ['token' => 'dop_v1_x'])->create(['name' => 'Acme']);

    Http::fake([
        'api.digitalocean.com/v2/droplets/42/actions' => Http::response(['action' => ['id' => 1, 'status' => 'in-progress']], 201),
        'api.digitalocean.com/v2/droplets/42' => Http::response(null, 204),
        'api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => [droplet(42, 'web-1'), droplet(43, 'db-1', 'off')], 'links' => []]),
        'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [['slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 80, 'price_monthly' => 24, 'available' => true]], 'links' => []]),
    ]);

    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'digitalocean', 'tab' => 'droplets'])
        ->assertSee('web-1')->assertSee('db-1')->assertSee('203.0.113.7')
        ->set('search', 'db-')->assertDontSee('web-1')->assertSee('db-1')
        ->set('search', '')
        ->call('start', 'reboot', '42');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/droplets/42/actions') && $r['type'] === 'reboot');

    // A form: its defaults come from the row, its choices from the provider.
    $page->call('start', 'resize', '42')
        ->assertSet('formAction', 'resize')
        ->assertSet('form.size', 's-1vcpu-1gb')
        ->assertSee('s-2vcpu-4gb')
        ->set('form.size', 's-2vcpu-4gb')
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/droplets/42/actions') && $r['type'] === 'resize' && $r['size'] === 's-2vcpu-4gb');

    $page->call('start', 'delete', '42');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/droplets/42'));

    // An action that does not apply to the row is refused.
    $page->call('start', 'power_on', '42');
    Http::assertNotSent(fn (Request $r) => ($r->data()['type'] ?? null) === 'power_on');
});

test('Hetzner snapshots and backups are listed as two image types', function () {
    enable('hetzner');
    ConnectorAccount::factory()->of('hetzner', ['token' => 'hcloud'])->create();

    $type = function (Request $request): ?string {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['type']) ? (string) $query['type'] : null;
    };

    Http::fake([
        'api.hetzner.cloud/v1/images*' => function (Request $request) use ($type) {
            return Http::response(['images' => match ($type($request)) {
                'snapshot' => [['id' => 1, 'description' => 'web-before-upgrade', 'type' => 'snapshot', 'image_size' => 4.2, 'created' => '2026-10-01T10:00:00Z', 'created_from' => ['name' => 'web-1']]],
                'backup' => [['id' => 2, 'description' => 'web-nightly', 'type' => 'backup', 'image_size' => 4.5, 'created' => '2026-10-18T03:00:00Z', 'created_from' => ['name' => 'web-1']]],
                default => [],
            }, 'meta' => ['pagination' => ['next_page' => null]]]);
        },
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'hetzner', 'tab' => 'images'])
        ->assertSee('web-before-upgrade')
        ->assertSee('web-nightly')
        ->assertSee('snapshot')
        ->assertSee('backup');

    Http::assertSent(fn (Request $request) => $type($request) === 'snapshot');
    Http::assertSent(fn (Request $request) => $type($request) === 'backup');
    Http::assertNotSent(fn (Request $request) => $type($request) === 'snapshot,backup');
});

test('a refusal of the provider shows on the page', function () {
    enable('hetzner');
    ConnectorAccount::factory()->of('hetzner', ['token' => 'bad'])->create();

    Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401)]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'hetzner'])
        ->assertSee('Refused (401)')->assertSee('unable to authenticate');
});

test('a child list opens with the scope of its row and goes back', function () {
    enable('cloudflare');
    ConnectorAccount::factory()->of('cloudflare', ['token' => 'cf'])->create();

    Http::fake([
        'api.cloudflare.com/client/v4/zones/z1/dns_records/r1' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones/z1/dns_records*' => Http::response(['success' => true, 'result' => [
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.acme.test', 'content' => '203.0.113.9', 'proxied' => true, 'ttl' => 1],
        ], 'result_info' => ['page' => 1, 'total_pages' => 1]]),
        'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [
            ['id' => 'z1', 'name' => 'acme.test', 'status' => 'active', 'paused' => false, 'plan' => ['name' => 'Free'], 'name_servers' => ['ada.ns.cloudflare.com'], 'account' => ['name' => 'Acme']],
        ], 'result_info' => ['page' => 1, 'total_pages' => 1]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cloudflare'])
        ->assertSee('acme.test')
        ->call('start', 'records', 'z1')
        ->assertSet('tab', 'records')
        ->assertSet('scope', ['zone' => 'z1', 'zone_name' => 'acme.test'])
        ->assertSee('www.acme.test')
        ->call('start', 'unproxy', 'r1')
        ->call('back')
        ->assertSet('tab', 'zones')
        ->assertSet('scope', []);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/zones/z1/dns_records/r1') && $r['proxied'] === false);
});

test('AWS requests are signed with Signature Version 4', function () {
    // The AWS test suite (get-vanilla, get-vanilla-query-order-key-case).
    $signer = new Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');
    $at = new DateTimeImmutable('2015-08-30T12:36:00Z');

    expect($signer->sign('GET', 'https://example.amazonaws.com/', 'us-east-1', 'service', [], '', $at)['Authorization'])
        ->toEndWith('SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31')
        ->and($signer->sign('GET', 'https://example.amazonaws.com/?Param2=value2&Param1=value1', 'us-east-1', 'service', [], '', $at)['Authorization'])
        ->toEndWith('Signature=b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500');

    expect(Xml::parse('<R xmlns="x"><set><item><id>a</id></item><item><id>b</id></item></set><one><item><id>c</id></item></one><empty/></R>'))
        ->toBe(['set' => [['id' => 'a'], ['id' => 'b']], 'one' => [['id' => 'c']], 'empty' => ''])
        ->and(Xml::list(''))->toBe([])
        ->and(Xml::list(['DBInstanceIdentifier' => 'db']))->toBe([['DBInstanceIdentifier' => 'db']]);
});

test('EC2 instances and Lightsail resources come from the region picked', function () {
    enable('aws');
    ConnectorAccount::factory()->of('aws', ['access_key_id' => 'AKIATEST', 'secret_access_key' => 'secret', 'region' => 'eu-south-1'])->create();

    Http::fake([
        'ec2.eu-central-1.amazonaws.com/*' => Http::response('<DescribeInstancesResponse xmlns="http://ec2.amazonaws.com/doc/2016-11-15/"><reservationSet><item><instancesSet><item>'
            .'<instanceId>i-0abc</instanceId><instanceType>t3.small</instanceType><instanceState><name>running</name></instanceState><ipAddress>198.51.100.4</ipAddress>'
            .'<tagSet><item><key>Name</key><value>api-1</value></item></tagSet></item></instancesSet></item></reservationSet></DescribeInstancesResponse>'),
        'lightsail.eu-south-1.amazonaws.com/*' => fn (Request $r) => match ($r->header('X-Amz-Target')[0] ?? '') {
            'Lightsail_20161128.GetStaticIps' => Http::response(['staticIps' => [['name' => 'ip-web', 'ipAddress' => '192.0.2.10', 'isAttached' => false]]]),
            'Lightsail_20161128.AllocateStaticIp', 'Lightsail_20161128.ReleaseStaticIp' => Http::response(['operations' => []]),
            default => Http::response(['__type' => 'InvalidInputException', 'message' => 'unexpected'], 400),
        },
    ]);

    $page = Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'aws', 'tab' => 'ec2'])
        ->set('filters.region', 'eu-central-1')
        ->assertSee('api-1')->assertSee('198.51.100.4')->assertSee('EC2')->assertSee('Lightsail');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ec2.eu-central-1') && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIATEST/')
        && str_contains($r->header('Authorization')[0], '/eu-central-1/ec2/aws4_request') && str_contains($r->body(), 'Action=DescribeInstances'));

    $page->call('selectGroup', 'Lightsail')->assertSet('tab', 'lightsail')
        ->call('select', 'lightsail-static-ips')
        ->assertSee('ip-web')
        ->call('start', 'create')
        ->set('form.name', 'ip-api')
        ->call('submit')
        ->assertHasNoErrors()
        ->call('start', 'ReleaseStaticIp', 'ip-web');

    Http::assertSent(fn (Request $r) => ($r->header('X-Amz-Target')[0] ?? '') === 'Lightsail_20161128.AllocateStaticIp' && $r['staticIpName'] === 'ip-api');
    Http::assertSent(fn (Request $r) => ($r->header('X-Amz-Target')[0] ?? '') === 'Lightsail_20161128.ReleaseStaticIp' && $r['staticIpName'] === 'ip-web');
});

test('a Cipi job is followed until it completes', function () {
    Sleep::fake();
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    $checks = 0;
    Http::fake([
        'https://api.server.acme.test/api/apps/shop/deploy' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
        'https://api.server.acme.test/api/jobs/job-1' => function () use (&$checks) {
            // Four checks while the page waits, the fifth on "Check again".
            return ++$checks < 5
                ? Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy', 'status' => 'running']])
                : Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy', 'status' => 'completed', 'result' => ['release' => '20261013']]]);
        },
        'https://api.server.acme.test/api/apps' => Http::response(['data' => [['app' => 'shop', 'domain' => 'shop.acme.test', 'type' => 'laravel', 'php' => '8.4', 'suspended' => false]]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->assertSee('shop.acme.test')
        ->call('start', 'deploy', 'shop')
        ->assertSet('resultPoll', 'job-1')
        ->call('poll')
        ->assertSet('resultPoll', null)
        ->assertSee('20261013');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer cipi-token'));
});

test('a Cipi app\'s cipi.yml: its plan, applied as a job, auto-apply turned on, and a note for an older server', function () {
    Sleep::fake();
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/apps/crm/yml' => Http::response(['error' => 'Not Found'], 404),
        'https://api.server.acme.test/api/apps/crm/yml/apply' => Http::response(['message' => 'Invalid ability provided.'], 403),
        'https://api.server.acme.test/api/apps/shop/yml' => Http::response(['data' => [
            'app' => 'shop', 'file' => '/home/shop/current/cipi.yml', 'auto' => false, 'valid' => true, 'in_sync' => false,
            'errors' => [], 'warnings' => [], 'changes' => [['kind' => 'php', 'summary' => 'PHP 8.3 → 8.4']], 'blockers' => [], 'notes' => [],
        ]]),
        'https://api.server.acme.test/api/apps/shop/yml/apply' => Http::response(['job_id' => 'job-yml', 'status' => 'pending'], 202),
        'https://api.server.acme.test/api/apps/shop/yml/auto/enable' => Http::response(['data' => ['app' => 'shop', 'auto' => true, 'sudoers' => true, 'file' => '/home/shop/current/cipi.yml']]),
        'https://api.server.acme.test/api/jobs/job-yml' => Http::response(['data' => ['id' => 'job-yml', 'type' => 'app-yml-apply', 'status' => 'completed', 'result' => [
            'app' => 'shop', 'outcome' => 'applied', 'applied' => 1, 'failed' => 0, 'failures' => [], 'blockers' => [], 'errors' => [],
        ]]]),
        'https://api.server.acme.test/api/apps' => Http::response(['data' => [
            ['app' => 'shop', 'domain' => 'shop.acme.test', 'type' => 'laravel', 'php' => '8.3', 'suspended' => false],
            ['app' => 'crm', 'domain' => 'crm.acme.test', 'type' => 'laravel', 'php' => '8.3', 'suspended' => false],
        ]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->call('start', 'yml', 'shop')
        ->assertSee('PHP 8.3 → 8.4')
        ->call('start', 'yml-apply', 'shop')
        ->assertSet('resultPoll', null)
        ->assertSee('"outcome": "applied"')
        ->call('start', 'yml-auto', 'shop')
        ->assertSet('form.auto', 'on')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('"sudoers": true')
        // a server without the endpoint: a note, not an error
        ->call('start', 'yml', 'crm')
        ->assertSee('it needs Cipi 5.6.4 and cipi/api 1.35 or later')
        ->call('start', 'yml-apply', 'crm')
        ->assertDispatched('toast-show', fn (string $name, array $params) => str_contains($params['slots']['text'], 'lacks the yml-manage ability'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.server.acme.test/api/apps/shop/yml/auto/enable' && $r->method() === 'POST');
});

test('creating a Cipi app appends the database link', function () {
    Sleep::fake();
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    $created = [
        'app' => 'shop',
        'domain' => 'shop.acme.test',
        'ssh' => ['user' => 'shop', 'password' => 'ssh-secret'],
        'database' => ['user' => 'shop', 'password' => 'db-secret'],
    ];

    Http::fake([
        'https://api.server.acme.test/api/jobs/job-1' => Http::response(['data' => [
            'id' => 'job-1', 'type' => 'app-create', 'status' => 'completed', 'result' => $created,
            'output' => "APP CREATED: shop\n  IP:         203.0.113.8\n  mariadb+ssh://shop:ssh-secret@203.0.113.8/shop:db-secret@127.0.0.1/shop\n",
        ]]),
        'https://api.server.acme.test/api/apps' => function (Request $r) {
            return $r->method() === 'POST'
                ? Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202)
                : Http::response(['data' => []]);
        },
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->call('start', 'create')
        ->set('form.user', 'shop')
        ->set('form.domain', 'shop.acme.test')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('mariadb+ssh://shop:ssh-secret@203.0.113.8/shop:db-secret@127.0.0.1/shop');
});

test('a Cipi app created without the link in the output still gets one', function () {
    Sleep::fake();
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/status' => Http::response(['data' => ['system' => ['ip' => '203.0.113.9']]]),
        'https://api.server.acme.test/api/jobs/job-2' => Http::response(['data' => [
            'id' => 'job-2', 'type' => 'app-create', 'status' => 'completed',
            'result' => [
                'app' => 'billing',
                'ssh' => ['user' => 'billing', 'password' => 'ssh/secret'],
                'database' => ['user' => 'billing', 'password' => 'db@secret'],
            ],
            'output' => "APP CREATED: billing\n",
        ]]),
        'https://api.server.acme.test/api/apps' => function (Request $r) {
            return $r->method() === 'POST'
                ? Http::response(['job_id' => 'job-2', 'status' => 'pending'], 202)
                : Http::response(['data' => []]);
        },
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->call('start', 'create')
        ->set('form.user', 'billing')
        ->set('form.domain', 'billing.acme.test')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('mariadb+ssh://billing:ssh%2Fsecret@203.0.113.9/billing:db%40secret@127.0.0.1/billing');
});

test('the Cipi server tab shows each app\'s disk', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/status' => Http::response(['data' => [
            'system' => ['hostname' => 'web1'],
            'resources' => ['disk' => ['display' => '40G / 100G']],
            'services' => [],
            'php' => [],
        ]]),
        'https://api.server.acme.test/api/disk' => Http::response(['data' => ['apps' => [
            ['app' => 'secyapit5098', 'files_gb' => 6.4, 'database_gb' => 1.9, 'total_gb' => 8.3, 'percent' => 5.2],
            ['app' => 'infrasyp2534', 'files_gb' => 0, 'files_kb' => 4300, 'database_gb' => 0, 'database_kb' => 0, 'total_gb' => 0, 'total_kb' => 4300, 'percent' => 0, 'over_limit' => true, 'limit_gb' => 1],
        ]]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'status'])
        ->assertSee('secyapit5098')
        ->assertSee('8.3 GB')
        ->assertSee('6.4 GB files')
        ->assertSee('1.9 GB database')
        ->assertSee('5.2%')
        ->assertSee('infrasyp2534')
        ->assertSee('4.2 MB')
        ->assertSee('over 1 GB');
});

test('Cipi shows a live deploy status and prefers deploy history over the audit ledger', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/apps/shop/deploy/status*' => Http::response(['data' => [
            'app' => 'shop', 'state' => 'running', 'running' => true, 'phase' => 'post-deploy', 'queued' => false, 'locked' => true,
            'current' => ['trigger' => 'webhook', 'elapsed' => 9], 'last' => null, 'tail' => ['healthcheck ok'],
        ]]),
        'https://api.server.acme.test/api/apps/shop/deploy/history*' => Http::response(['data' => ['app' => 'shop', 'count' => 1, 'log' => '/home/shop/logs/deploy.log', 'runs' => [
            ['result' => 'ok', 'kind' => 'deploy', 'release' => 7, 'duration' => 55, 'trigger' => 'panel'],
        ]]]),
        'https://api.server.acme.test/api/apps' => Http::response(['data' => [['app' => 'shop', 'domain' => 'shop.acme.test', 'type' => 'laravel', 'php' => '8.6', 'env' => 'staging', 'suspended' => false]]]),
        'https://api.server.acme.test/api/status' => Http::response(['data' => ['system' => ['hostname' => 'web1', 'cipi' => '5.9.1', 'api' => '1.39.2'], 'apps' => 1]]),
        'https://api.server.acme.test/api/disk' => Http::response(['data' => ['apps' => []]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->assertSee('staging')
        ->call('start', 'deploy-status', 'shop')
        ->assertSee('post-deploy')
        ->assertSee('webhook')
        ->call('start', 'audit', 'shop')
        ->assertSee('panel')
        ->assertSee('55');

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'status'])
        ->assertSee('1.39.2');
});

test('Cipi lists Node runtimes and backup profiles when the API has them', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        // what cipi/api answers: the runtimes of `cipi node list`, each with the names of its apps
        'https://api.server.acme.test/api/node' => Http::response(['data' => [
            ['major' => '22', 'version' => '22.14.0', 'default' => true, 'apps' => ['shop', 'crm']],
            ['major' => '20', 'version' => '20.19.0', 'default' => false, 'apps' => []],
        ]]),
        'https://api.server.acme.test/api/backups' => Http::response(['data' => [
            'configured' => true,
            'profiles' => [
                ['name' => 'nightly', 'enabled' => true, 'overdue' => false, 'running' => false],
            ],
        ]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'node'])
        ->assertSee('22.14.0')
        ->assertSee('20.19.0')
        ->assertSee('shop, crm');

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'backups'])
        ->assertSee('nightly');
});

test('Cipi sends keep_path, strip_prefix and preserve_host as booleans, false included', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/apps/shop/redirects' => Http::response(['data' => ['redirect' => null, 'redirects' => []]]),
        'https://api.server.acme.test/api/apps/shop/redirect' => Http::response(['data' => ['enabled' => true, 'to' => 'https://new.acme.test/?from=old', 'code' => 302, 'keep_path' => false]]),
        'https://api.server.acme.test/api/apps/shop/proxies' => Http::response(['data' => ['proxies' => []]]),
        'https://api.server.acme.test/api/apps' => Http::response(['data' => [['app' => 'shop', 'domain' => 'shop.acme.test', 'type' => 'laravel']]]),
    ]);

    $page = Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->call('start', 'redirects', 'shop')
        ->assertSet('tab', 'redirects')
        // the API keeps the path when keep_path is missing: unticked must say false
        ->call('start', 'create')
        ->set('form.from', '/old')
        ->set('form.to', 'https://new.acme.test/?from=old')
        ->set('form.keep_path', false)
        ->call('submit')
        ->assertHasNoErrors()
        ->call('start', 'app-redirect', '*')
        ->set('form.to', 'https://new.acme.test/?from=old')
        ->set('form.code', '302')
        ->set('form.keep_path', false)
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/apps/shop/redirects') && $r['keep_path'] === false && $r['code'] === 301);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/apps/shop/redirect') && $r['keep_path'] === false && $r['code'] === 302);

    $page->call('back')
        ->call('start', 'proxies', 'shop')
        ->call('start', 'create')
        ->set('form.prefix', '/api')
        ->set('form.upstream', 'http://127.0.0.1:8080')
        ->set('form.strip_prefix', false)
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/apps/shop/proxies') && $r['strip_prefix'] === false && $r['preserve_host'] === false);
});

test('the whole-app redirect offers Enable or Disable only when one is set, as the server has it', function () {
    $cipi = Connectors::make('cipi');
    $section = $cipi->section('redirects');
    $row = fn () => ($section->list)($cipi->client(['url' => 'api.server.acme.test', 'token' => 'cipi-token']), ['app' => 'shop'])[0];

    Http::fake(['https://api.server.acme.test/api/apps/shop/redirects' => Http::sequence()
        ->push(['data' => ['redirect' => null, 'redirects' => []]])
        ->push(['data' => ['redirect' => ['enabled' => true, 'to' => 'https://new.acme.test', 'code' => 301, 'keep_path' => true], 'redirects' => []]])
        ->push(['data' => ['redirect' => ['enabled' => false, 'to' => 'https://new.acme.test', 'code' => 301, 'keep_path' => true], 'redirects' => []]]),
    ]);

    $offered = fn (array $row) => array_values(array_map(fn ($a) => $a->key, array_filter($section->actions, fn ($a) => $a->appliesTo($row))));

    // none set; one on; one saved but off
    expect($offered($row()))->toBe(['app-redirect'])
        ->and($offered($row()))->toBe(['app-redirect', 'disable', 'clear-app'])
        ->and($offered($row()))->toBe(['app-redirect', 'enable', 'clear-app']);
});

test('Cipi marks restore and the server-wide Node and backup changes destructive, so the safe hand-over leaves them out', function () {
    $cipi = Connectors::make('cipi');
    $danger = fn (string $section) => array_keys(array_filter(ProjectResource::actionsFor($cipi, $cipi->section($section)), fn ($a) => $a['danger']));

    expect($danger('databases'))->toContain('restore')
        ->and($danger('node'))->toContain('upgrade', 'default', 'delete')
        ->and($danger('backups'))->toContain('disable')
        ->and($cipi->section('databases')->action('restore')->confirm)->toContain('Overwrite')
        // confirmed like Suspend, not destructive: they can be turned off again
        ->and($cipi->section('redirects')->action('enable')->confirm)->not->toBeNull()
        ->and($cipi->section('redirects')->action('app-redirect')->confirm)->not->toBeNull()
        ->and($cipi->section('proxies')->toolbarAction('create')->confirm)->not->toBeNull()
        ->and($danger('apps'))->not->toContain('redirects.enable', 'redirects.app-redirect', 'proxies.@create');
});

test('a new Cipi app leaves PHP to the server unless one is chosen', function () {
    Sleep::fake();
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/jobs/job-1' => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-create', 'status' => 'completed', 'result' => [], 'output' => '']]),
        'https://api.server.acme.test/api/apps' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202)
            : Http::response(['data' => []]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'apps'])
        ->call('start', 'create')
        ->assertSet('form.php', '')
        ->set('form.user', 'shop')
        ->set('form.domain', 'shop.acme.test')
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps') && ! array_key_exists('php', $r->data()));
});

test('Cipi says why a list is not there: an older server, a token without the ability, a command that failed', function (int $status, string $error, string $note) {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake(['https://api.server.acme.test/api/backups' => Http::response(['error' => $error], $status)]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'backups'])
        ->assertSee($note);
})->with([
    'core too old' => [503, 'Backup management from the panel needs Cipi 5.9.0+ — this server runs Cipi 5.8.4. Run: cipi self-update', 'it needs Cipi 5.9 and cipi/api 1.39 or later'],
    'route missing' => [404, 'The route api/backups could not be found.', 'it needs Cipi 5.9 and cipi/api 1.39 or later'],
    'no ability' => [403, 'Token lacks the required ability', 'lacks the backups-view ability'],
    'command failed' => [503, 'cipi backup status answered no configured flag', 'cipi backup status answered no configured flag'],
]);

test('the Cipi databases show their size from size_mb, or the size older servers give', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake(['https://api.server.acme.test/api/dbs' => Http::response(['data' => [
        ['name' => 'shop', 'engine' => 'mariadb', 'size_mb' => 12.5, 'app' => 'shop'],
        ['name' => 'legacy', 'engine' => 'mariadb', 'size' => '3.1M'],
    ]])]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'databases'])
        ->assertSee('12.5 MB')
        ->assertSee('3.1M');
});

test('the Cipi PHP tab lists the installed versions', function () {
    enable('cipi');
    ConnectorAccount::factory()->of('cipi', ['url' => 'api.server.acme.test', 'token' => 'cipi-token'])->create();

    Http::fake([
        'https://api.server.acme.test/api/php' => Http::response(['data' => [
            'default' => '8.4',
            'installable' => ['8.3', '8.4', '8.5'],
            'versions' => [
                ['version' => '8.4', 'status' => 'running', 'apps' => 2, 'default' => true],
                ['version' => '8.3', 'status' => 'stopped', 'apps' => 0, 'default' => false],
            ],
        ]]),
    ]);

    Livewire::actingAs(User::factory()->admin()->create())->test(Console::class, ['connector' => 'cipi', 'tab' => 'php'])
        ->assertSee('8.4')
        ->assertSee('8.3')
        ->assertSee('running')
        ->assertSee('stopped')
        ->assertSee('2 items');
});

test('Boogle exceptions and Hackly findings are triaged from Studio', function () {
    enable('boogle');
    enable('hackly');
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->of('boogle', ['url' => 'https://boogle.acme.test', 'token' => 'b'])->create();
    ConnectorAccount::factory()->of('hackly', ['url' => 'https://hackly.acme.test/', 'token' => 'hk_x'])->create();

    Http::fake([
        'boogle.acme.test/api/admin/exceptions*' => Http::response(['current_page' => 1, 'next_page_url' => null, 'data' => [
            ['id' => 'e1', 'project_id' => 'p1', 'project' => ['id' => 'p1', 'title' => 'Shop'], 'exception' => 'QueryException', 'error' => 'Deadlock', 'status' => 'OPEN', 'issue_code' => '#BUG7', 'created_at' => '2026-10-12T10:00:00Z'],
        ]]),
        'boogle.acme.test/api/admin/projects/p1/exceptions/e1/status' => Http::response(['data' => []]),
        'hackly.acme.test/api/v1/findings/f1' => Http::response(['data' => []]),
        'hackly.acme.test/api/v1/findings*' => Http::response(['data' => [
            ['id' => 'f1', 'title' => 'Missing HSTS', 'severity' => 'medium', 'status' => 'open', 'subject' => 'acme.test', 'created_at' => '2026-10-12T10:00:00Z'],
        ], 'links' => ['next' => null]]),
        'hackly.acme.test/api/v1/*' => Http::response(['data' => []]),
    ]);

    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'boogle'])
        ->assertSee('#BUG7')->assertSee('Deadlock')
        ->call('start', 'status-fixed', 'e1');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/projects/p1/exceptions/e1/status') && $r['status'] === 'FIXED');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/admin/exceptions') && str_contains($r->url(), 'status=OPEN'));

    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'hackly', 'tab' => 'findings'])
        ->assertSee('Missing HSTS')
        ->call('start', 'triage', 'f1')
        ->set('form.status', 'false_positive')
        ->set('form.note', 'Behind the CDN')
        ->call('submit');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/api/v1/findings/f1') && $r['status'] === 'false_positive' && $r['note'] === 'Behind the CDN');
});

test('every tab and every form of every connector renders, whatever the provider answers', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response([])]);
    $admin = User::factory()->admin()->create();

    foreach (Connectors::all() as $key => $connector) {
        enable($key);
        ConnectorAccount::factory()->of($key, ['token' => 't', 'url' => 'https://x.test', 'access_key_id' => 'AKIA', 'secret_access_key' => 's', 'region' => 'eu-west-1'])->create();

        foreach ($connector->sections() as $section) {
            $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => $key])
                ->set('tab', $section->key)
                ->set('scope', $section->isChild() ? ['zone' => 'z', 'zone_name' => 'z.test', 'domain' => 'z.test', 'app' => 'a', 'project' => 'p', 'project_title' => 'P'] : [])
                ->assertOk()
                ->assertSee($section->label);

            foreach ($section->toolbar as $action) {
                $page->call('start', $action->key)->assertOk();
            }
        }

        $page->call('manageAccounts')->call('editAccount')->assertOk();
    }
});

test('Johnny panels: buckets, a bucket with its key in one step, keys and their permissions per bucket', function () {
    enable('johnny');
    $admin = User::factory()->admin()->create();

    Http::fake([
        'storage.acme.test/api/buckets/provision' => Http::response(['bucket' => 'b-x7k2', 'region' => 'johnny', 'endpoint' => 'https://s3.acme.test', 'path_style' => true, 'key_name' => 'b-x7k2-key',
            'credentials' => ['access_key_id' => 'GKabc', 'secret_access_key' => 'shown-once'], 'env' => ['AWS_BUCKET' => 'b-x7k2']], 201),
        'storage.acme.test/api/buckets/shop-uploads/keys/GK0011*' => Http::response(null, 204),
        'storage.acme.test/api/buckets/shop-uploads/keys' => Http::response(['bucket' => 'shop-uploads', 'key_id' => 'GK0022', 'read' => true, 'write' => true, 'owner' => false], 201),
        'storage.acme.test/api/buckets/shop-uploads' => Http::response(['data' => ['name' => 'shop-uploads', 'created' => '2026-10-01T10:00:00Z', 'keys' => [
            ['id' => 'GK0011', 'name' => 'shop-app', 'permissions' => 'read, write', 'scope' => 'read-write'],
        ]]]),
        'storage.acme.test/api/buckets' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['data' => ['name' => $r['name']]], 201)
            : Http::response(['data' => [['name' => 'default', 'created' => '2026-09-01T10:00:00Z'], ['name' => 'shop-uploads', 'created' => '2026-10-01T10:00:00Z']]]),
        'storage.acme.test/api/keys' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['id' => 'GK0033', 'name' => $r['name'], 'credentials' => ['access_key_id' => 'GK0033', 'secret_access_key' => 'key-secret']], 201)
            : Http::response(['data' => [['id' => 'GK0011', 'name' => 'shop-app', 'created' => '2026-10-01'], ['id' => 'GK0022', 'name' => 'backups', 'created' => '2026-10-02']]]),
    ]);

    // the account is checked against the panel before it is saved
    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny'])
        ->assertSee('No Johnny account yet')
        ->call('manageAccounts')->call('editAccount')
        ->set('account_name', 'Agency storage')->set('credentials.url', 'storage.acme.test')->set('credentials.token', '1|sanctum')
        ->call('saveAccount')->assertHasNoErrors();
    expect(ConnectorAccount::query()->where('connector', 'johnny')->sole()->status)->toBe('ok · 2 buckets');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://storage.acme.test/api/buckets' && $r->hasHeader('Authorization', 'Bearer 1|sanctum') && $r->hasHeader('Accept', 'application/json'));

    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'buckets'])
        ->assertSee(['default', 'shop-uploads'])
        // a bad name never reaches the panel
        ->call('start', 'create')->set('form.name', 'Shop Uploads')->call('submit')->assertHasErrors('form.name')
        ->set('form.name', 'shop-media')->call('submit')->assertHasNoErrors()
        // provisioning hands over the secret once
        ->call('start', 'provision')->call('submit')
        ->assertSet('resultTitle', 'Bucket b-x7k2 and its key')
        ->assertSee('shown-once');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://storage.acme.test/api/buckets' && $r->method() === 'POST' && $r['name'] === 'shop-media');
    // the bucket's own key gets full control unless another scope is chosen
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/buckets/provision') && $r['scope'] === 'full');

    // the keys of a bucket, each with its scope: give one a scope, change another's, take one off
    $page->call('start', 'keys', 'shop-uploads')->assertSet('tab', 'bucket-keys')->assertSee(['shop-app', 'Read and write'])
        ->call('start', 'grant')->set('form.key_id', 'GK0022')->set('form.scope', 'read')->call('submit')->assertHasNoErrors()
        ->call('start', 'scope', 'GK0011')->assertSet('form.scope', 'read-write')->set('form.scope', 'full')->call('submit')->assertHasNoErrors()
        ->call('start', 'revoke', 'GK0011');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://storage.acme.test/api/buckets/shop-uploads/keys' && $r['key_id'] === 'GK0022' && $r['scope'] === 'read' && ! isset($r['read']));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://storage.acme.test/api/buckets/shop-uploads/keys' && $r['key_id'] === 'GK0011' && $r['scope'] === 'full');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://storage.acme.test/api/buckets/shop-uploads/keys/GK0011' && $r['read'] === true && $r['write'] === true && $r['owner'] === true);

    // the default bucket is not offered for deletion; a new key shows its secret once
    expect(collect(Connectors::make('johnny')->section('buckets')->actions)->firstWhere('key', 'delete')->appliesTo(['name' => 'default']))->toBeFalse();
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'keys'])
        ->assertSee(['shop-app', 'GK0022'])
        ->call('start', 'create')->set('form.name', 'shop-cdn')->call('submit')->assertSee('key-secret');

    // like any connector, a bucket is attached to a project for the roles chosen
    $project = Project::factory()->create();
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'buckets'])
        ->call('attach', 'shop-uploads')->set('attach_project', $project->uuid)->set('attach_actions', ['bucket-keys.@grant'])->call('saveAttachment')->assertHasNoErrors();
    expect(ProjectResource::query()->sole()->resource_id)->toBe('shop-uploads');
});

test('Johnny 2.0: a bucket\'s quotas, CORS and expiry rules start from what it has; keys reach chosen buckets or every one', function () {
    enable('johnny');
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->create(['connector' => 'johnny', 'name' => 'Storage', 'credentials' => ['url' => 'https://storage.acme.test', 'token' => 't']]);

    Http::fake([
        'storage.acme.test/api/buckets/media/settings' => Http::response(['data' => [
            'bucket' => 'media',
            'usage' => ['size' => 3 * 1024 ** 3, 'objects' => 1200],
            'quotas' => ['max_size' => 10 * 1024 ** 3, 'max_objects' => 50000],
            'cors' => ['origins' => ['https://app.acme.test'], 'methods' => ['GET', 'PUT'], 'headers' => ['*'], 'max_age' => 600, 'rules' => 1],
            'lifecycle' => ['expire_days' => null, 'expire_prefix' => '', 'abort_days' => 7, 'other_rules' => 0],
        ]]),
        'storage.acme.test/api/buckets/media/*' => Http::response(['data' => []]),
        'storage.acme.test/api/buckets' => Http::response(['data' => [['name' => 'media'], ['name' => 'invoices']]]),
        'storage.acme.test/api/keys/GK0044/all-buckets' => Http::response(['data' => ['id' => 'GK0044', 'all_buckets' => true, 'scope' => 'read', 'buckets' => 2, 'failed_buckets' => []]]),
        'storage.acme.test/api/keys' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['id' => 'GK0055', 'name' => $r['name'], 'access' => $r['access'], 'scope' => $r['scope'], 'buckets' => $r['buckets'] ?? [], 'failed_buckets' => ['invoices'],
                'credentials' => ['access_key_id' => 'GK0055', 'secret_access_key' => 'once-only']], 201)
            : Http::response(['data' => [['id' => 'GK0044', 'name' => 'reports', 'all_buckets' => true, 'scope' => 'read']]]),
    ]);

    // settings: one row each, with what the bucket has now
    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'buckets'])
        ->call('start', 'settings', 'media')->assertSet('tab', 'bucket-settings')
        ->assertSee(['3.0 GB · 1,200 objects', 'at most 10 GB', 'at most 50,000 objects', 'https://app.acme.test', 'unfinished uploads removed after 7 days']);

    // changing only the objects keeps the size limit as it was
    $page->call('start', 'quotas', 'quotas')->assertSet('form.max_size', '10 GB')->set('form.max_objects', '60000')->call('submit')->assertHasNoErrors();
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/buckets/media/quotas') && $r['max_size'] === 10 * 1024 ** 3 && $r['max_objects'] === 60000);
    // an empty size is no limit; a size Johnny could not read never leaves
    $page->call('start', 'quotas', 'quotas')->set('form.max_size', 'lots')->call('submit')->assertHasErrors('form.max_size');
    $page->call('start', 'quotas', 'quotas')->set('form.max_size', '')->call('submit');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/buckets/media/quotas') && $r['max_size'] === null);

    // CORS: one website per line; none removes the rule
    $page->call('start', 'cors', 'cors')->assertSet('form.methods', ['GET', 'PUT'])
        ->set('form.origins', "https://app.acme.test\nhttps://admin.acme.test")->call('submit')->assertHasNoErrors();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/buckets/media/cors') && $r['origins'] === ['https://app.acme.test', 'https://admin.acme.test'] && $r['methods'] === ['GET', 'PUT'] && $r['max_age'] === 600);
    $page->call('start', 'cors', 'cors')->set('form.origins', '')->call('submit');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/buckets/media/cors') && $r['origins'] === [] && ! isset($r['methods']));

    // expiry rules delete objects: destructive, so the safe hand-over leaves them out
    $lifecycle = collect(Connectors::make('johnny')->section('bucket-settings')->actions)->firstWhere('key', 'lifecycle');
    expect($lifecycle->danger)->toBeTrue();
    $page->call('start', 'lifecycle', 'lifecycle')->assertSet('form.abort_days', '7')->set('form.expire_days', '30')->set('form.expire_prefix', 'tmp/')->call('submit');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/buckets/media/lifecycle') && $r['expire_days'] === 30 && $r['expire_prefix'] === 'tmp/' && $r['abort_days'] === 7);

    // a key for chosen buckets: the ones the grant missed are said
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'keys'])
        ->assertSee('every bucket · Read only')
        ->call('start', 'create')->set('form.name', 'reports-2')->set('form.access', 'buckets')->call('submit')->assertSet('resultTitle', null)
        ->set('form.buckets', ['media', 'invoices'])->set('form.scope', 'read')->call('submit')
        ->assertSee(['once-only', 'invoices']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/keys') && $r['access'] === 'buckets' && $r['buckets'] === ['media', 'invoices'] && $r['scope'] === 'read');

    // one scope everywhere, the new buckets too
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'keys'])
        ->call('start', 'all-buckets', 'GK0044')->assertSet('form.scope', 'read')->call('submit')->assertHasNoErrors();
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/keys/GK0044/all-buckets') && $r['scope'] === 'read');
});

test('Johnny 2.0: connections, transfers followed until they end, scheduled syncs; a panel before 2.0 says it needs the update', function () {
    enable('johnny');
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->create(['connector' => 'johnny', 'name' => 'Storage', 'credentials' => ['url' => 'https://storage.acme.test', 'token' => 't']]);

    $transfer = ['id' => 7, 'direction' => 'import', 'mode' => 'copy', 'connection_name' => 'R2', 'local_bucket' => 'media', 'remote_bucket' => 'media', 'status' => 'running', 'active' => true,
        'percent' => 42, 'bytes_done' => 420, 'bytes_total' => 1000, 'files_done' => 4, 'files_total' => 10, 'errors' => 0];
    Http::fake([
        'storage.acme.test/api/providers' => Http::response(['data' => [['id' => 'r2', 'label' => 'Cloudflare R2'], ['id' => 'sftp', 'label' => 'SFTP server']]]),
        'storage.acme.test/api/connections/3/check' => Http::response(['data' => ['id' => 3, 'reachable' => false, 'error' => 'SignatureDoesNotMatch']]),
        'storage.acme.test/api/connections' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['data' => ['id' => 3, 'name' => $r['name'], 'reachable' => false, 'check_error' => 'InvalidAccessKeyId']], 201)
            : Http::response(['data' => [['id' => 3, 'name' => 'R2', 'provider' => 'r2', 'provider_label' => 'Cloudflare R2', 'endpoint' => 'https://acc.r2.test', 'reachable' => true]]]),
        'storage.acme.test/api/transfers/7' => Http::sequence()->push(['data' => $transfer])->push(['data' => ['status' => 'completed', 'active' => false, 'message' => '10 files copied.'] + $transfer]),
        'storage.acme.test/api/transfers*' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['data' => ['status' => 'pending'] + $transfer], 201)
            : Http::response(['data' => [$transfer]]),
        'storage.acme.test/api/syncs/9' => Http::response(['data' => ['id' => 9, 'enabled' => false]]),
        'storage.acme.test/api/syncs' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['data' => ['id' => 9]], 201)
            : Http::response(['data' => [['id' => 9, 'direction' => 'export', 'connection_name' => 'R2', 'local_bucket' => 'media', 'remote_bucket' => 'backups/media', 'mode' => 'sync',
                'interval_minutes' => 15, 'interval_label' => 'Every 15 minutes', 'enabled' => true, 'next_run_at' => now()->addMinutes(10)->toIso8601String()]], 'scheduler_running' => false]),
    ]);

    // an S3 account: what was left empty stays out, path style is said either way; a refusal is said
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'connections'])
        ->assertSee(['R2', 'Cloudflare R2'])
        ->call('start', 'create')->set('form.name', 'R2 archive')->set('form.provider', 'r2')->set('form.endpoint', 'https://acc.r2.test')
        ->set('form.access_key_id', 'AK')->set('form.secret_access_key', 'SK')->call('submit')->assertHasNoErrors()
        ->call('start', 'check', '3');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/connections') && $r['provider'] === 'r2' && $r['path_style'] === false
        && ! isset($r['host']) && ! isset($r['password']) && ! isset($r['port']));

    // a transfer is followed until it ends
    $page = Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'transfers'])
        ->assertSee(['42%', '4 of 10 files'])
        ->call('start', 'create')->set('form.connection_id', '3')->set('form.remote_bucket', 'media')->set('form.local_bucket', 'media')->call('submit')
        ->assertSet('resultPoll', '7');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/transfers') && $r['connection_id'] === 3 && $r['direction'] === 'import' && $r['mode'] === 'copy' && $r['dry_run'] === false);
    $page->call('poll')->assertSet('resultPoll', '7')->call('poll')->assertSet('resultPoll', null)->assertSee('10 files copied.');

    // a mirror deletes at the destination: every way to start one asks first
    foreach (['transfers' => 'create', 'syncs' => 'create'] as $section => $key) {
        expect(Connectors::make('johnny')->section($section)->toolbarAction($key)?->confirm)->not->toBeNull();
    }

    // syncs: a scheduler that does not run is said on the row, the list stays
    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'syncs'])
        ->assertSee(['Every 15 minutes', 'mirror', 'the panel\'s scheduler is not running'])
        ->call('start', 'create')->set('form.direction', 'export')->set('form.connection_id', '3')->set('form.remote_bucket', 'backups/media')->set('form.local_bucket', 'media')
        ->set('form.mode', 'sync')->set('form.interval_minutes', '15')->call('submit')->assertHasNoErrors()
        ->call('start', 'pause', '9');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/syncs') && $r['interval_minutes'] === 15 && $r['mode'] === 'sync' && $r['direction'] === 'export');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/syncs/9') && $r['enabled'] === false);
});

test('a Johnny panel before 2.0 keeps buckets and keys, and says what needs the update', function () {
    enable('johnny');
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->create(['connector' => 'johnny', 'name' => 'Old', 'credentials' => ['url' => 'https://old.acme.test', 'token' => 't']]);
    Http::fake([
        'old.acme.test/api/buckets' => Http::response(['data' => [['name' => 'media']]]),
        'old.acme.test/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    foreach (['connections', 'transfers', 'syncs'] as $tab) {
        Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => $tab])->assertSee('it needs Johnny 2.0');
    }

    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'johnny', 'tab' => 'buckets'])->assertSee('media')
        ->call('start', 'settings', 'media')->assertSee('it needs Johnny 2.0');
});
