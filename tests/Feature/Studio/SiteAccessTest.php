<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\IpMatcher;
use App\Server\SiteAccess;

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
