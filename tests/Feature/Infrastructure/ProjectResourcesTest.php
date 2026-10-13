<?php

use App\Infrastructure\Connectors;
use App\Livewire\Infrastructure\Console;
use App\Livewire\Projects\Infrastructure as ProjectInfrastructure;
use App\Livewire\Projects\ResourceConsole;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A DigitalOcean account of the team with two Droplets and a domain with one record. */
function digitalOcean(): ConnectorAccount
{
    ConnectorSetting::query()->updateOrCreate(['connector' => 'digitalocean'], ['enabled' => true]);

    $droplet = fn (int $id, string $name) => [
        'id' => $id, 'name' => $name, 'status' => 'active', 'size_slug' => 's-1vcpu-1gb', 'region' => ['slug' => 'fra1'],
        'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'], 'networks' => ['v4' => [['ip_address' => '203.0.113.'.$id, 'type' => 'public']]],
        'created_at' => '2026-10-01T10:00:00Z', 'features' => [],
    ];

    Http::fake([
        'api.digitalocean.com/v2/droplets/42/actions' => Http::response(['action' => ['id' => 1, 'status' => 'in-progress']], 201),
        'api.digitalocean.com/v2/droplets/43/actions' => Http::response(['action' => ['id' => 2, 'status' => 'in-progress']], 201),
        'api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => [$droplet(42, 'shop-web'), $droplet(43, 'crm-db')], 'links' => []]),
        'api.digitalocean.com/v2/domains/shop.test/records*' => Http::response(['domain_records' => [['id' => 7, 'type' => 'A', 'name' => '@', 'data' => '203.0.113.42', 'ttl' => 1800]], 'links' => []]),
        'api.digitalocean.com/v2/domains*' => Http::response(['domains' => [['name' => 'shop.test', 'ttl' => 1800, 'zone_file' => '']], 'links' => []]),
    ]);

    return ConnectorAccount::factory()->of('digitalocean', ['token' => 'dop_v1_team'])->create(['name' => 'Agency']);
}

test('an administrator attaches a Droplet to a project for the roles that see it and act on it', function () {
    $account = digitalOcean();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['name' => 'Shop']);

    Livewire::actingAs($admin)->test(Console::class, ['connector' => 'digitalocean', 'tab' => 'droplets'])
        ->call('attach', '42')
        ->assertSet('attach_label', 'shop-web')
        ->set('attach_project', $project->uuid)
        ->set('attach_view', ['pm', 'dev', 'client'])
        ->set('attach_act', ['dev'])
        ->set('attach_actions', ['reboot', 'not-an-action'])
        ->call('saveAttachment')
        ->assertHasErrors('attach_actions.1')
        ->set('attach_actions', ['reboot', 'power_cycle'])
        ->call('saveAttachment')
        ->assertHasNoErrors()
        // the row carries the project it is in, and opens the same dialog again
        ->assertSee('Shop');

    $resource = ProjectResource::query()->sole();

    expect($resource->project_id)->toBe($project->id)
        ->and($resource->connector_account_id)->toBe($account->id)
        ->and($resource->section)->toBe('droplets')
        ->and($resource->resource_id)->toBe('42')
        ->and($resource->view_roles)->toBe(['pm', 'dev', 'client'])
        ->and($resource->act_roles)->toBe(['dev'])
        ->and($resource->actions)->toBe(['reboot', 'power_cycle']);

    // A child list (DNS records) is no resource of its own; a member never attaches anything.
    $member = User::factory()->create();
    Livewire::actingAs($member)->test(Console::class, ['connector' => 'digitalocean'])->assertForbidden();
});

test('the actions offered for a resource are its row actions and those of the lists it opens', function () {
    $connector = Connectors::make('digitalocean');

    $droplet = ProjectResource::actionsFor($connector, $connector->section('droplets'));
    $domain = ProjectResource::actionsFor($connector, $connector->section('domains'));

    expect($droplet)->toHaveKeys(['reboot', 'power_cycle', 'delete'])
        ->and($droplet['delete']['danger'])->toBeTrue()
        ->and($domain)->toHaveKeys(['zone', 'delete', 'records.edit', 'records.delete', 'records.@create'])
        ->and($domain)->not->toHaveKey('records');
});

test('a project member sees the resource alone, and runs only the actions handed to their role', function () {
    $account = digitalOcean();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $pm = User::factory()->create();
    $client = User::factory()->client()->create();
    $outsider = User::factory()->create();
    $project->members()->attach([$dev->id => ['role' => 'dev'], $pm->id => ['role' => 'pm'], $client->id => ['role' => 'client']]);

    $droplet = ProjectResource::factory()->create([
        'project_id' => $project->id, 'connector_account_id' => $account->id, 'section' => 'droplets', 'resource_id' => '42', 'label' => 'Production web',
        'view_roles' => ['pm', 'dev'], 'act_roles' => ['dev'], 'actions' => ['reboot'],
    ]);

    // the developer: the row of Droplet 42 only, Reboot and nothing else, no list actions, no account
    $page = Livewire::actingAs($dev)->test(ResourceConsole::class, ['resource' => $droplet->uuid])
        ->assertSee('Production web')
        ->assertSee('shop-web')
        ->assertDontSee('crm-db')
        ->assertSee('Reboot')
        ->assertDontSee('Power cycle')
        ->assertDontSee('Create Droplet')
        ->assertDontSee('Agency');

    $page->call('start', 'reboot', '42')->assertOk();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.digitalocean.com/v2/droplets/42/actions' && $r['type'] === 'reboot');

    // another action, another row, a list action: refused
    $page->call('start', 'power_cycle', '42')->assertForbidden();
    Livewire::actingAs($dev)->test(ResourceConsole::class, ['resource' => $droplet->uuid])->call('start', 'reboot', '43');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/droplets/43/'));
    Livewire::actingAs($dev)->test(ResourceConsole::class, ['resource' => $droplet->uuid])->call('start', 'create')->assertForbidden();

    // the PM sees it and acts on nothing
    Livewire::actingAs($pm)->test(ResourceConsole::class, ['resource' => $droplet->uuid])->assertSee('shop-web')->assertDontSee('Reboot')
        ->call('start', 'reboot', '42')->assertForbidden();

    // the client and an outsider do not see it at all
    Livewire::actingAs($client)->test(ResourceConsole::class, ['resource' => $droplet->uuid])->assertForbidden();
    Livewire::actingAs($outsider)->test(ResourceConsole::class, ['resource' => $droplet->uuid])->assertForbidden();

    expect(ProjectResource::query()->visibleTo($dev)->count())->toBe(1)
        ->and(ProjectResource::query()->visibleTo($pm)->count())->toBe(1)
        ->and(ProjectResource::query()->visibleTo($client)->count())->toBe(0)
        ->and(ProjectResource::query()->visibleTo($outsider)->count())->toBe(0);
});

test('a resource opens its child lists, where the actions handed over apply too', function () {
    $account = digitalOcean();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->members()->attach($dev->id, ['role' => 'dev']);

    $domain = ProjectResource::factory()->create([
        'project_id' => $project->id, 'connector_account_id' => $account->id, 'section' => 'domains', 'resource_id' => 'shop.test', 'label' => 'shop.test',
        'view_roles' => ['dev'], 'act_roles' => ['dev'], 'actions' => ['records.edit'],
    ]);

    Livewire::actingAs($dev)->test(ResourceConsole::class, ['resource' => $domain->uuid])
        ->assertSee('DNS records')
        ->call('start', 'records', 'shop.test')
        ->assertSet('tab', 'records')
        ->assertSee('203.0.113.42')
        ->assertSee('Edit')
        ->assertDontSee('New record')
        ->call('start', 'create')->assertForbidden();
});

test('the project\'s Infrastructure tab lists what the role sees; administrators change who sees what, or detach', function () {
    $account = digitalOcean();
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->create();
    $client = User::factory()->client()->create();
    $project->members()->attach([$dev->id => ['role' => 'dev'], $client->id => ['role' => 'client']]);

    $web = ProjectResource::factory()->create(['project_id' => $project->id, 'connector_account_id' => $account->id, 'resource_id' => '42', 'label' => 'Production web', 'view_roles' => ['dev'], 'act_roles' => [], 'actions' => []]);
    ProjectResource::factory()->create(['project_id' => $project->id, 'connector_account_id' => $account->id, 'resource_id' => '43', 'label' => 'Database server', 'view_roles' => ['dev', 'client'], 'act_roles' => [], 'actions' => []]);

    $this->actingAs($dev)->get(route('projects.infrastructure', $project))->assertOk()->assertSee(['Production web', 'Database server']);
    $this->actingAs($client)->get(route('projects.infrastructure', $project))->assertOk()->assertSee('Database server')->assertDontSee('Production web');
    $this->actingAs(User::factory()->create())->get(route('projects.infrastructure', $project))->assertForbidden();

    Livewire::actingAs($dev)->test(ProjectInfrastructure::class, ['project' => $project])->call('edit', $web->uuid)->assertForbidden();

    Livewire::actingAs($admin)->test(ProjectInfrastructure::class, ['project' => $project])
        ->call('edit', $web->uuid)
        ->set('view_roles', ['dev', 'client'])
        ->set('act_roles', ['dev'])
        ->set('actions', ['reboot'])
        ->call('save')
        ->assertHasNoErrors();

    expect($web->fresh()->view_roles)->toBe(['dev', 'client'])->and($web->fresh()->allows($dev, 'reboot'))->toBeTrue()->and($web->fresh()->allows($client, 'reboot'))->toBeFalse();

    Livewire::actingAs($admin)->test(ProjectInfrastructure::class, ['project' => $project])->call('detach', $web->uuid);
    expect($web->fresh())->toBeNull()->and($project->resources()->count())->toBe(1);
});
