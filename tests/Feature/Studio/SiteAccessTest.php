<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\IpMatcher;
use App\Server\SiteAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => config(['studio.domain' => 'dev.example.test']));

test('ip rules match exact addresses and CIDR ranges, v4 and v6', function () {
    expect(IpMatcher::matches('203.0.113.9', '203.0.113.9'))->toBeTrue()
        ->and(IpMatcher::matches('203.0.113.9', '203.0.113.0/24'))->toBeTrue()
        ->and(IpMatcher::matches('203.0.114.9', '203.0.113.0/24'))->toBeFalse()
        ->and(IpMatcher::matches('10.1.2.3', '10.0.0.0/8'))->toBeTrue()
        ->and(IpMatcher::matches('2001:db8::1', '2001:db8::/32'))->toBeTrue()
        ->and(IpMatcher::matches('2001:db9::1', '2001:db8::/32'))->toBeFalse()
        ->and(IpMatcher::matches('203.0.113.9', '2001:db8::/32'))->toBeFalse()
        ->and(IpMatcher::matches('not-an-ip', '203.0.113.0/24'))->toBeFalse()
        ->and(IpMatcher::isValidRule('203.0.113.0/24'))->toBeTrue()
        ->and(IpMatcher::isValidRule('203.0.113.0/33'))->toBeFalse()
        ->and(IpMatcher::isValidRule('office'))->toBeFalse();
});

test('every host wants a logged-in person with access to the project, in any role; an old public mode is ignored', function () {
    $member = User::factory()->create();
    $client = User::factory()->client()->create();
    $admin = User::factory()->admin()->create();
    $stranger = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'settings' => ['access' => ['site' => ['mode' => 'public', 'ips' => []], 'previews' => ['mode' => 'public', 'ips' => []]]]]);
    $project->members()->attach([$member->id, $client->id]);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $member->id]);
    $access = new SiteAccess;

    foreach ([['site', null], ['preview', $workspace]] as [$kind, $on]) {
        expect($access->decide($project, $kind, $on, '198.51.100.7', null))->toBe(SiteAccess::LOGIN)
            ->and($access->decide($project, $kind, $on, '198.51.100.7', $stranger))->toBe(SiteAccess::FORBIDDEN)
            ->and($access->decide($project, $kind, $on, '198.51.100.7', $member))->toBe(SiteAccess::ALLOW)
            ->and($access->decide($project, $kind, $on, '198.51.100.7', $client))->toBe(SiteAccess::ALLOW)
            ->and($access->decide($project, $kind, $on, '198.51.100.7', $admin))->toBe(SiteAccess::ALLOW);
    }
});

test('an address list narrows the login, it never replaces it', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'settings' => ['access' => ['site' => ['ips' => ['203.0.113.0/24']]]]]);
    $project->members()->attach($member);
    $access = new SiteAccess;

    expect($access->decide($project, 'site', null, '203.0.113.9', null))->toBe(SiteAccess::LOGIN)
        ->and($access->decide($project, 'site', null, '203.0.113.9', $member))->toBe(SiteAccess::ALLOW)
        ->and($access->decide($project, 'site', null, '198.51.100.7', $member))->toBe(SiteAccess::FORBIDDEN)
        ->and($access->decide($project, 'site', null, '198.51.100.7', null))->toBe(SiteAccess::FORBIDDEN);
});

test('a branch override replaces the address list, for the deploy branch and for a workspace on that branch', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'default_branch' => 'develop', 'settings' => ['access' => [
        'site' => ['ips' => ['203.0.113.0/24']],
        'branches' => [['branch' => 'develop', 'ips' => ['10.0.0.0/8']], ['branch' => 'feature/secret', 'ips' => ['192.0.2.1']], ['branch' => 'feature/empty', 'ips' => []]],
    ]]]);
    $project->members()->attach($member);
    $secret = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $member->id, 'branch' => 'feature/secret']);
    $access = new SiteAccess;

    expect($access->decide($project, 'site', null, '10.1.2.3', $member))->toBe(SiteAccess::ALLOW)
        ->and($access->decide($project, 'site', null, '203.0.113.9', $member))->toBe(SiteAccess::FORBIDDEN)
        ->and($access->decide($project, 'preview', $secret, '192.0.2.1', $member))->toBe(SiteAccess::ALLOW)
        ->and($access->decide($project, 'preview', $secret, '198.51.100.7', $member))->toBe(SiteAccess::FORBIDDEN)
        // a branch rule without addresses would mean nothing: it is dropped
        ->and(array_column($project->access()['branches'], 'branch'))->toBe(['develop', 'feature/secret']);
});

test('the gatekeeper sends a stranger to the Studio login, and turns away an address off the list', function () {
    config(['app.url' => 'https://studio.dev.example.test']);
    Project::factory()->create(['slug' => 'shop', 'settings' => ['access' => ['site' => ['ips' => ['203.0.113.0/24']]]]]);
    $headers = ['X-Forwarded-Host' => 'shop.dev.example.test', 'X-Forwarded-Uri' => '/larapilot?page=2', 'X-Forwarded-Method' => 'GET'];

    $this->withHeaders([...$headers, 'X-Forwarded-For' => '203.0.113.9'])->get('/api/site-auth')
        ->assertRedirect('https://studio.dev.example.test/site-login?host=shop.dev.example.test&r='.urlencode('/larapilot?page=2'));
    $this->withHeaders([...$headers, 'X-Forwarded-For' => '198.51.100.7'])->get('/api/site-auth')->assertForbidden();
    $this->withHeaders([...$headers, 'X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Method' => 'POST'])->get('/api/site-auth')->assertStatus(401);
    $this->withHeaders(['X-Forwarded-Host' => 'nobody.dev.example.test'])->get('/api/site-auth')->assertNotFound();
});

test('a name nobody configured gets Studio\'s welcome page, which links to Studio', function () {
    config(['app.url' => 'https://studio.dev.example.test']);

    $this->withHeaders(['X-Forwarded-Host' => 'fasfafas.dev.example.test', 'X-Forwarded-Uri' => '/any/path'])->get('/api/site-auth')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Nothing here yet')
        ->assertSee('fasfafas.dev.example.test')
        ->assertSee('href="https://studio.dev.example.test"', false);
});

test('a member goes from the Studio login back to the host with a one-time token, which becomes a pass for that host only', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'settings' => []]);
    $project->members()->attach($member);
    Project::factory()->create(['slug' => 'other', 'settings' => []])->members()->attach($member);

    $this->get('/site-login?host=shop.dev.example.test&r=/orders')->assertRedirect(route('login'));

    $hop = $this->actingAs($member)->get('/site-login?host=shop.dev.example.test&r=/orders');
    $location = (string) $hop->headers->get('Location');
    expect($location)->toStartWith('https://shop.dev.example.test/.studio-auth?token=');

    $callback = substr($location, strlen('https://shop.dev.example.test'));
    $redeemed = $this->withHeaders(['X-Forwarded-Host' => 'shop.dev.example.test', 'X-Forwarded-Uri' => $callback])->get('/api/site-auth');
    $redeemed->assertRedirect('/orders');
    $pass = collect($redeemed->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'studio_site_pass');
    expect($pass)->not->toBeNull()
        ->and($pass->getDomain())->toBeNull()
        ->and($pass->isHttpOnly())->toBeTrue()
        ->and($pass->isSecure())->toBeTrue();

    // the token works once
    $this->withHeaders(['X-Forwarded-Host' => 'shop.dev.example.test', 'X-Forwarded-Uri' => $callback])->get('/api/site-auth')->assertForbidden();

    // the pass opens shop, and only shop
    $this->withUnencryptedCookie('studio_site_pass', $pass->getValue())
        ->withHeaders(['X-Forwarded-Host' => 'shop.dev.example.test', 'X-Forwarded-Uri' => '/orders', 'X-Forwarded-For' => '198.51.100.7'])
        ->get('/api/site-auth')->assertNoContent();
    $this->withUnencryptedCookie('studio_site_pass', $pass->getValue())
        ->withHeaders(['X-Forwarded-Host' => 'other.dev.example.test', 'X-Forwarded-Uri' => '/', 'X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Method' => 'GET'])
        ->get('/api/site-auth')->assertRedirect();
});

test('someone who is not a member is stopped at the Studio hop, and an outside path is never followed', function () {
    $stranger = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'settings' => []]);
    $project->members()->attach($member);

    $this->actingAs($stranger)->get('/site-login?host=shop.dev.example.test&r=/')->assertForbidden();
    $this->actingAs($member)->get('/site-login?host=nobody.dev.example.test')->assertNotFound();

    $location = (string) $this->actingAs($member)->get('/site-login?host=shop.dev.example.test&r=//evil.example.com/')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['r'])->toBe('/');
});

test('a public address that is public lets anybody in; not public it asks the browser; the team\'s addresses and the previews keep the Studio login', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'site_host' => 'project-a.prove.test', 'settings' => ['access' => ['public' => ['site' => true, 'previews' => true]]]]);
    $project->members()->attach($member);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $member->id]);
    $access = new SiteAccess;

    expect($project->isPublished())->toBeTrue()
        ->and($project->access()['public'])->toBe(['site' => true, 'previews' => false])
        // public at the public address: anybody, /larapilot included (it asks for a Larapilot user of its own in staging)
        ->and($access->decide($project, 'site', null, '198.51.100.7', null, public: true, basic: true))->toBe(SiteAccess::ALLOW)
        // not public there: the browser asks, and a stranger's account is asked again
        ->and($access->decide($project, 'site', null, '198.51.100.7', null, basic: true))->toBe(SiteAccess::BASIC)
        ->and($access->decide($project, 'site', null, '198.51.100.7', User::factory()->create(), basic: true))->toBe(SiteAccess::BASIC)
        ->and($access->decide($project, 'site', null, '198.51.100.7', $member, basic: true))->toBe(SiteAccess::ALLOW)
        // the team's address and a preview: Studio's login
        ->and($access->decide($project, 'site', null, '198.51.100.7', null))->toBe(SiteAccess::LOGIN)
        ->and($access->decide($project, 'preview', $workspace, '198.51.100.7', null))->toBe(SiteAccess::LOGIN)
        ->and($access->decide($project, 'site', null, '198.51.100.7', User::factory()->create()))->toBe(SiteAccess::FORBIDDEN);

    // without a public address, nothing is public
    $project->update(['site_host' => null]);
    expect($project->fresh()->isPublished())->toBeFalse();
});

test('an address list still narrows the public address, public or not', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'site_host' => 'project-a.prove.test', 'settings' => ['access' => ['public' => ['site' => true], 'site' => ['ips' => ['203.0.113.0/24']]]]]);
    $project->members()->attach($member);
    $access = new SiteAccess;

    expect($access->decide($project, 'site', null, '203.0.113.9', null, public: true, basic: true))->toBe(SiteAccess::ALLOW)
        ->and($access->decide($project, 'site', null, '198.51.100.7', null, public: true, basic: true))->toBe(SiteAccess::FORBIDDEN)
        ->and($access->decide($project, 'site', null, '198.51.100.7', $member, basic: true))->toBe(SiteAccess::FORBIDDEN);
});

test('on one domain each project is public or not on its own, and its team address keeps the Studio login', function () {
    config(['app.url' => 'https://studio.dev.example.test']);
    Project::factory()->create(['slug' => 'a', 'site_host' => 'project-a.prove.test', 'settings' => ['access' => ['public' => ['site' => true]]]]);
    Project::factory()->create(['name' => 'B', 'slug' => 'b', 'site_host' => 'project-b.prove.test', 'settings' => ['access' => ['public' => ['site' => false]]]]);
    Project::factory()->create(['slug' => 'c', 'site_host' => 'progetto-bello-c.prove.test', 'settings' => ['access' => ['public' => ['site' => true]]]]);
    $ask = fn (string $host, string $uri = '/orders?page=2') => $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => $uri, 'X-Forwarded-Method' => 'GET', 'X-Forwarded-For' => '198.51.100.7'])->get('/api/site-auth');

    $ask('project-a.prove.test')->assertNoContent()->assertHeaderMissing('X-Studio-Strip-Auth');
    $ask('project-a.prove.test', '/larapilot')->assertNoContent();
    // credentials meant for the app (its own API) are not Studio's business: they go through untouched
    $this->withHeaders(['X-Forwarded-Host' => 'project-a.prove.test', 'X-Forwarded-Uri' => '/api/orders', 'X-Forwarded-For' => '198.51.100.7', 'Authorization' => 'Basic '.base64_encode('api:secret')])
        ->get('/api/site-auth')->assertNoContent()->assertHeaderMissing('X-Studio-Strip-Auth');
    $ask('project-b.prove.test')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Basic realm="B · project-b.prove.test", charset="UTF-8"');
    $ask('progetto-bello-c.prove.test')->assertNoContent();

    // the team's address of a public site: Studio's login, as ever
    $ask('a.dev.example.test')->assertRedirect('https://studio.dev.example.test/site-login?host=a.dev.example.test&r='.urlencode('/orders?page=2'));
});

test('at a public address that is not public, a member signs in with the browser, and Caddy is told to keep the password from the app', function () {
    $member = User::factory()->create(['email' => 'maria@agency.test', 'password' => 'correct horse battery']);
    $stranger = User::factory()->create(['email' => 'luca@agency.test', 'password' => 'another long password']);
    $project = Project::factory()->create(['slug' => 'b', 'site_host' => 'project-b.prove.test']);
    $project->members()->attach($member);
    $ask = fn (?string $credentials, string $ip = '198.51.100.7') => $this->withHeaders(array_filter([
        'X-Forwarded-Host' => 'project-b.prove.test', 'X-Forwarded-Uri' => '/', 'X-Forwarded-Method' => 'GET', 'X-Forwarded-For' => $ip,
        'Authorization' => $credentials === null ? null : 'Basic '.base64_encode($credentials),
    ]))->get('/api/site-auth');

    $ask(null)->assertUnauthorized();
    $ask('maria@agency.test:wrong')->assertUnauthorized();
    $ask('luca@agency.test:another long password')->assertUnauthorized();
    $ask('MARIA@agency.test:correct horse battery')->assertNoContent()->assertHeader('X-Studio-Strip-Auth', '1');
    // the pair is remembered: the next request of the page does not hash the password again
    expect(Cache::get('site-basic-ok|'.hash('sha256', $member->id.'|correct horse battery|'.$member->fresh()->password)))->toBe($member->id);
    $ask('maria@agency.test:correct horse battery')->assertNoContent();

    // a new password ends what was remembered
    $member->update(['password' => 'a brand new password']);
    $ask('maria@agency.test:correct horse battery')->assertUnauthorized();
    $ask('maria@agency.test:a brand new password')->assertNoContent();
});

test('the browser sign-in is limited as the login form is: five tries on an account from an address', function () {
    $member = User::factory()->create(['email' => 'maria@agency.test', 'password' => 'correct horse battery']);
    $project = Project::factory()->create(['slug' => 'b', 'site_host' => 'project-b.prove.test']);
    $project->members()->attach($member);
    $ask = fn (string $credentials) => $this->withHeaders([
        'X-Forwarded-Host' => 'project-b.prove.test', 'X-Forwarded-Uri' => '/', 'X-Forwarded-Method' => 'GET', 'X-Forwarded-For' => '198.51.100.7',
        'Authorization' => 'Basic '.base64_encode($credentials),
    ])->get('/api/site-auth');

    foreach (range(1, 5) as $try) {
        $ask('maria@agency.test:wrong '.$try)->assertUnauthorized();
    }

    // locked for a minute, even with the right password
    $ask('maria@agency.test:correct horse battery')->assertUnauthorized();
    expect(RateLimiter::tooManyAttempts('site-basic|maria@agency.test|198.51.100.7', 5))->toBeTrue();
});
