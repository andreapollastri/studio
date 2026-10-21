<?php

use App\Enums\DomainStatus;
use App\Enums\SiteStatus;
use App\Jobs\ConfigureProjectSite;
use App\Livewire\Admin\Domains;
use App\Livewire\Projects\Settings;
use App\Models\Conversation;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\DomainDns;
use App\Server\ProjectSites;
use App\Server\SiteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** A DNS that answers from a table: a host, or `*.name` for any name under it. */
class FakeDns extends DomainDns
{
    /** @param  array<string, list<string>>  $answers */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        if (isset($this->answers[$host])) {
            return $this->answers[$host];
        }

        $parent = substr($host, strpos($host, '.') + 1);

        return $this->answers['*.'.$parent] ?? [];
    }
}

function fakeDns(array $answers): FakeDns
{
    $dns = new FakeDns($answers);
    app()->instance(DomainDns::class, $dns);

    return $dns;
}

function verifiedDomain(string $name, bool $wildcard = true, bool $apex = true): Domain
{
    return Domain::query()->create([
        'name' => $name, 'wildcard' => $wildcard, 'status' => DomainStatus::Verified, 'verified_at' => now(),
        'records' => ['expected' => ['203.0.113.10'], 'name' => $apex ? ['203.0.113.10'] : [], 'wildcard' => $wildcard ? ['203.0.113.10'] : []],
    ]);
}

beforeEach(function () {
    config(['studio.domain' => 'dev.example.test', 'studio.server_ips' => ['203.0.113.10']]);
});

test('a domain is verified when it or its wildcard points here, lost when it stops, pending until then', function () {
    $domain = Domain::query()->create(['name' => 'client.test', 'wildcard' => true, 'status' => DomainStatus::Pending]);

    fakeDns([]);
    app(DomainDns::class)->check($domain);
    expect($domain->status)->toBe(DomainStatus::Pending)->and($domain->last_error)->toContain('does not resolve yet');

    fakeDns(['client.test' => ['198.51.100.1']]);
    app(DomainDns::class)->check($domain);
    expect($domain->status)->toBe(DomainStatus::Pending)->and($domain->last_error)->toContain('points at 198.51.100.1, not at this server');

    fakeDns(['*.client.test' => ['203.0.113.10']]);
    app(DomainDns::class)->check($domain);
    expect($domain->status)->toBe(DomainStatus::Verified)
        ->and($domain->wildcardPointsHere())->toBeTrue()
        ->and($domain->namePointsHere())->toBeFalse()
        ->and($domain->last_error)->toContain('client.test itself does not point here')
        ->and($domain->verified_at)->not->toBeNull();

    fakeDns(['client.test' => ['203.0.113.10'], '*.client.test' => ['203.0.113.10']]);
    app(DomainDns::class)->check($domain);
    expect($domain->last_error)->toBeNull();

    fakeDns([]);
    $this->artisan('studio:check-domains')->expectsOutputToContain('client.test: lost')->assertSuccessful();
    expect($domain->fresh()->status)->toBe(DomainStatus::Lost);
});

test('a domain covers itself and, with the wildcard, one label under it', function () {
    $wild = verifiedDomain('client.test');
    verifiedDomain('other.test', wildcard: false);

    expect($wild->covers('client.test'))->toBeTrue()
        ->and($wild->covers('staging.client.test'))->toBeTrue()
        ->and($wild->covers('a.b.client.test'))->toBeFalse()
        ->and($wild->covers('evilclient.test'))->toBeFalse()
        ->and(Domain::covering('staging.client.test')?->name)->toBe('client.test')
        ->and(Domain::covering('staging.other.test'))->toBeNull()
        ->and(Domain::covering('other.test')?->name)->toBe('other.test');
});

test('administrators add, check and remove domains; one a project uses stays', function () {
    $admin = User::factory()->admin()->create();
    fakeDns(['client.test' => ['203.0.113.10'], '*.client.test' => ['203.0.113.10']]);

    Livewire::actingAs($admin)->test(Domains::class)
        ->set('name', 'dev.example.test')->call('add')->assertHasErrors('name')
        ->set('name', 'x.dev.example.test')->call('add')->assertHasErrors('name')
        ->set('name', 'https://client.test')->call('add')->assertHasErrors('name')
        ->set('name', ' Client.test ')->set('wildcard', true)->call('add')->assertHasNoErrors()
        ->assertSee('client.test');

    $domain = Domain::query()->where('name', 'client.test')->firstOrFail();
    expect($domain->status)->toBe(DomainStatus::Verified)->and($domain->user_id)->toBe($admin->id);

    Project::factory()->create(['slug' => 'shop', 'site_host' => 'client.test', 'site_domain_id' => $domain->id]);
    Livewire::actingAs($admin)->test(Domains::class)->call('remove', $domain->uuid);
    expect(Domain::query()->whereKey($domain->id)->exists())->toBeTrue();

    Project::query()->update(['site_host' => null, 'site_domain_id' => null]);
    Livewire::actingAs($admin)->test(Domains::class)->call('remove', $domain->uuid);
    expect(Domain::query()->whereKey($domain->id)->exists())->toBeFalse();

    $this->actingAs(User::factory()->create())->get(route('admin.domains'))->assertForbidden();
});

test('a project gives its site a public address on a domain that points here; previews stay on Studio\'s domain', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['slug' => 'shop', 'site_status' => SiteStatus::Ready]);
    $wild = verifiedDomain('client.test');
    $apexOnly = verifiedDomain('brand.test', wildcard: false);
    $pending = Domain::query()->create(['name' => 'later.test', 'wildcard' => true, 'status' => DomainStatus::Pending]);

    $page = Livewire::actingAs($admin)->test(Settings::class, ['project' => $project, 'tab' => 'server'])
        ->assertSee('client.test')->assertDontSee('later.test');

    $page->set('site_domain', $pending->uuid)->call('saveSettings')->assertHasErrors('site_domain');
    $page->set('site_domain', $apexOnly->uuid)->set('site_label', 'www')->call('saveSettings')->assertHasNoErrors();
    expect($project->fresh()->site_host)->toBe('brand.test');

    $page->set('site_domain', $wild->uuid)->set('site_label', 'Staging')->call('saveSettings')->assertHasNoErrors();

    $project->refresh();
    $maria = User::factory()->create(['handle' => 'maria']);
    expect($project->siteHost())->toBe('staging.client.test')
        ->and($project->siteAliases())->toBe(['shop.dev.example.test'])
        ->and($project->siteUrl())->toBe('https://staging.client.test')
        ->and($project->previewHostFor($maria))->toBe('maria-shop.dev.example.test')
        ->and($project->sandboxHostFor($maria, 7))->toBe('maria-shop--c7.dev.example.test');
    Queue::assertPushed(ConfigureProjectSite::class);

    // another project cannot take the same address
    $other = Project::factory()->create(['slug' => 'blog']);
    Livewire::actingAs($admin)->test(Settings::class, ['project' => $other, 'tab' => 'server'])
        ->set('site_domain', $wild->uuid)->set('site_label', 'staging')->call('saveSettings')->assertHasErrors('site_label');

    // back to Studio's domain
    $page->set('site_domain', '')->call('saveSettings')->assertHasNoErrors();
    expect($project->fresh()->siteHost())->toBe('shop.dev.example.test')->and($project->fresh()->siteAliases())->toBe([]);
});

test('a project keeps a domain whose DNS was lost, but cannot pick one that is lost', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $domain = verifiedDomain('client.test');
    $project = Project::factory()->create(['slug' => 'shop', 'site_host' => 'client.test', 'site_domain_id' => $domain->id]);
    $domain->update(['status' => DomainStatus::Lost, 'records' => ['expected' => ['203.0.113.10'], 'name' => [], 'wildcard' => []]]);

    Livewire::actingAs($admin)->test(Settings::class, ['project' => $project, 'tab' => 'server'])
        ->assertSet('site_domain', $domain->uuid)
        ->call('saveSettings')->assertHasNoErrors()
        ->set('site_label', 'www')->call('saveSettings')->assertHasErrors('site_domain');
});

test('the gatekeeper and Caddy find a site at its public address, and the previews under Studio\'s domain', function () {
    $domain = verifiedDomain('client.test');
    $user = User::factory()->create(['handle' => 'maria']);
    $project = Project::factory()->create(['slug' => 'shop', 'site_host' => 'staging.client.test', 'site_domain_id' => $domain->id]);
    $workspace = Workspace::factory()->create(['project_id' => $project->id, 'user_id' => $user->id, 'app_url' => 'https://maria-shop.dev.example.test']);
    Conversation::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'sandbox_host' => 'maria-shop--c3.dev.example.test', 'sandbox' => ['branch' => 'sandbox/maria-3']]);
    $access = app(SiteAccess::class);

    expect($access->resolveHost('staging.client.test'))->toMatchArray(['kind' => 'site', 'public_address' => true, 'public' => false])
        ->and($access->resolveHost('shop.dev.example.test')['project']->is($project))->toBeTrue()
        ->and($access->resolveHost('maria-shop.dev.example.test'))->toMatchArray(['kind' => 'preview', 'public_address' => false, 'public' => false])
        ->and($access->resolveHost('maria-shop--c3.dev.example.test')['branch'])->toBe('sandbox/maria-3')
        ->and($access->resolveHost('nobody.client.test'))->toBeNull();

    $this->get('/api/tls/ask?domain=staging.client.test')->assertOk();
    $this->get('/api/tls/ask?domain=shop.dev.example.test')->assertOk();
    $this->get('/api/tls/ask?domain=studio.dev.example.test')->assertOk();
    // no welcome page, and no certificate, for an unknown name on a client's domain
    $this->get('/api/tls/ask?domain=nobody.client.test')->assertNotFound();

    $domain->update(['status' => DomainStatus::Lost]);
    $this->get('/api/tls/ask?domain=staging.client.test')->assertNotFound();
    $this->get('/api/tls/ask?domain=shop.dev.example.test')->assertOk();
});

test('the server gets the site with its aliases, and every workspace comes back under Studio\'s domain', function () {
    config(['studio.driver' => 'native', 'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'), 'studio.native.sudo' => false]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;

    $domain = verifiedDomain('client.test');
    $maria = User::factory()->create(['handle' => 'maria']);
    $project = Project::factory()->create(['slug' => 'shop', 'site_status' => SiteStatus::Ready, 'site_host' => 'staging.client.test', 'site_domain_id' => $domain->id]);
    // a preview left on a client's domain by an older release
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop', 'app_url' => 'https://maria-shop.client.test']);

    app(ProjectSites::class)->configure($project);
    $calls = file_get_contents($log);
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);

    expect($calls)->toContain('"site_host":"staging.client.test"')
        ->toContain('"site_aliases":"shop.dev.example.test"')
        ->toContain('"preview_host":"maria-shop.dev.example.test"')
        ->and($workspace->fresh()->app_url)->toBe('https://maria-shop.dev.example.test');
});

test('the Domains page lists each project at its public address, public or not', function () {
    $admin = User::factory()->admin()->create();
    $domain = verifiedDomain('prove.test');
    Project::factory()->create(['slug' => 'a', 'site_host' => 'project-a.prove.test', 'site_domain_id' => $domain->id, 'settings' => ['access' => ['public' => ['site' => true]]]]);
    Project::factory()->create(['slug' => 'b', 'site_host' => 'project-b.prove.test', 'site_domain_id' => $domain->id]);

    Livewire::actingAs($admin)->test(Domains::class)
        ->assertSeeInOrder(['project-a.prove.test', 'public', 'project-b.prove.test', 'not public'])
        ->assertDontSee('Put online');
});

test('the upgrade to public addresses keeps what was open open at the public address only, and previews come home', function () {
    $migration = require database_path('migrations/2026_11_05_000001_public_address_per_project.php');
    $migration->down();

    DB::table('domains')->insert([
        ['id' => 901, 'uuid' => 'd-on', 'name' => 'on.test', 'wildcard' => true, 'status' => 'verified', 'online' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 902, 'uuid' => 'd-off', 'name' => 'off.test', 'wildcard' => true, 'status' => 'verified', 'online' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $row = fn (int $id, string $slug, ?string $host, ?int $site, ?int $previews, ?array $public) => [
        'id' => $id, 'uuid' => "p-$slug", 'name' => strtoupper($slug), 'slug' => $slug, 'repo_url' => 'https://github.com/acme/'.$slug.'.git',
        'default_branch' => 'main', 'php_version' => '8.4', 'db_engine' => 'mariadb', 'site_host' => $host, 'site_domain_id' => $site,
        'preview_domain_id' => $previews, 'settings' => $public === null ? null : json_encode(['access' => ['public' => $public]]), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('projects')->insert([
        $row(901, 'ondomain', 'a.on.test', 901, 901, ['site' => false, 'previews' => false]),
        $row(902, 'publicsite', 'b.off.test', 902, null, ['site' => true, 'previews' => true]),
        $row(903, 'publicnoaddress', null, null, null, ['site' => true, 'previews' => true]),
        $row(904, 'closed', 'd.off.test', 902, 902, null),
    ]);

    $migration->up();

    $public = fn (string $slug) => Project::query()->where('slug', $slug)->firstOrFail()->access()['public'];
    expect($public('ondomain'))->toBe(['site' => true, 'previews' => false])
        ->and($public('publicsite'))->toBe(['site' => true, 'previews' => false])
        ->and($public('publicnoaddress'))->toBe(['site' => false, 'previews' => false])
        ->and($public('closed'))->toBe(['site' => false, 'previews' => false])
        ->and(Schema::hasColumn('projects', 'preview_domain_id'))->toBeFalse()
        ->and(Schema::hasColumn('domains', 'online'))->toBeFalse();
});
