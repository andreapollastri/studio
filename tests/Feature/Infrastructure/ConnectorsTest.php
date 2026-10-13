<?php

use App\Infrastructure\Aws\Signer;
use App\Infrastructure\Aws\Xml;
use App\Infrastructure\Connector;
use App\Infrastructure\Connectors;
use App\Livewire\Admin\Connectors as AdminConnectors;
use App\Livewire\Infrastructure\Console;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
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
    expect(array_keys(Connectors::all()))->toBe(['aws', 'digitalocean', 'hetzner', 'cloudflare', 'cipi', 'boogle', 'hackly']);

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
        ->assertSee('Administrators only');

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
