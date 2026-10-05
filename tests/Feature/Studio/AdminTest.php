<?php

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Jobs\DeployProjectSite;
use App\Jobs\DestroyProjectSite;
use App\Jobs\ProvisionProjectSite;
use App\Livewire\Admin\Projects;
use App\Livewire\Admin\Users;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('an administrator creates a project, becomes its deployer and the site is provisioned', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->set('name', 'Shop Acme')
        ->set('repo_url', 'https://github.com/acme/shop.git')
        ->set('members', [$dev->id])
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('slug', 'shop-acme')->firstOrFail();

    expect($project->members()->pluck('users.id')->all())->toBe([$dev->id])
        ->and($project->deploy_user_id)->toBe($admin->id)
        ->and($project->site_status)->toBe(SiteStatus::Provisioning)
        ->and($project->siteHost())->toBe('shop-acme.'.config('studio.domain'));

    Queue::assertPushed(ProvisionProjectSite::class, fn (ProvisionProjectSite $job) => $job->project->is($project));
});

test('only GitHub repositories are accepted', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->set('name', 'Elsewhere')
        ->set('repo_url', 'https://gitlab.com/acme/shop.git')
        ->call('save')
        ->assertHasErrors(['repo_url']);
});

test('a slug that would not fit a Linux user, or that a preview host already spells, is refused', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['handle' => 'maria']);
    Project::factory()->create(['slug' => 'shop']);

    $form = fn () => Livewire::actingAs($admin)->test(Projects::class)->call('create')->set('name', 'X')->set('repo_url', 'https://github.com/acme/x.git');

    // prj-<slug> must stay within the 32 characters of a Linux user name
    $form()->set('slug', str_repeat('a', 29))->call('save')->assertHasErrors(['slug' => 'max']);
    $form()->set('slug', str_repeat('a', 28))->call('save')->assertHasNoErrors();
    // "maria-shop" is the host of Maria's preview of "shop"
    $form()->set('slug', 'maria-shop')->call('save')->assertHasErrors(['slug']);
    $form()->set('slug', 'maria-crm')->call('save')->assertHasNoErrors();
});

test('a branch name that git could read as an option is refused', function () {
    $admin = User::factory()->admin()->create();

    $form = fn () => Livewire::actingAs($admin)->test(Projects::class)->call('create')->set('name', 'X')->set('repo_url', 'https://github.com/acme/x.git');

    $form()->set('default_branch', '--upload-pack=evil')->call('save')->assertHasErrors(['default_branch']);
    $form()->set('default_branch', 'release/../main')->call('save')->assertHasErrors(['default_branch']);
    $form()->set('default_branch', 'release/2026.10')->call('save')->assertHasNoErrors();
});

test('a handle that would put the previews on the host of an existing project is refused', function () {
    $admin = User::factory()->admin()->create();
    Project::factory()->create(['slug' => 'maria-shop']);
    Project::factory()->create(['slug' => 'shop']);

    $form = fn () => Livewire::actingAs($admin)->test(Users::class)->call('create')->set('name', 'Maria')->set('email', 'maria@acme.test')->set('role', 'dev');

    $form()->set('handle', 'maria')->call('save')->assertHasErrors(['handle']);
    $form()->set('handle', 'Maria')->call('save')->assertHasErrors(['handle' => 'regex']);
    $form()->set('handle', str_repeat('m', 25))->call('save')->assertHasErrors(['handle' => 'max']);
    $form()->set('handle', 'mrossi')->call('save')->assertHasNoErrors();
});

test('deleting a project marks it and leaves the work to the queue, once', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create();

    Livewire::actingAs($admin)->test(Projects::class)->call('delete', $project->id)->call('delete', $project->id);

    expect($project->fresh()->site_status)->toBe(SiteStatus::Deleting);
    Queue::assertPushed(DestroyProjectSite::class, 1);
});

test('editing a project keeps its site and token', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create(['larapilot_api_token' => 'keep-me']);

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('edit', $project->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('Renamed')
        ->and($project->fresh()->larapilot_api_token)->toBe('keep-me');
});

test('an administrator can trigger a deploy of the project site', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create();

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('deploy', $project->id);

    Queue::assertPushed(DeployProjectSite::class, fn (DeployProjectSite $job) => $job->project->is($project));
});

test('a second deploy while the site is being created is ignored', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['site_status' => SiteStatus::Provisioning]);

    Livewire::actingAs($admin)->test(Projects::class)->call('deploy', $project->id);

    Queue::assertNothingPushed();
});

test('a developer cannot mount the admin project component', function () {
    $dev = User::factory()->create();

    Livewire::actingAs($dev)->test(Projects::class)->assertForbidden();
});

test('an administrator invites a user and gets a one-time password', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('create')
        ->set('name', 'Maria Rossi')
        ->set('email', 'maria@acme.test')
        ->set('role', 'pm')
        ->set('handle', 'mariarossi')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'maria@acme.test')->firstOrFail();

    expect($user->role)->toBe(UserRole::Pm)
        ->and($user->handle)->toBe('mariarossi')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($component->get('generatedPassword'))->toHaveLength(16);
});

test('an administrator gives a person a new password: shown once, sessions closed, 2FA off', function () {
    $admin = User::factory()->admin()->create();
    $maria = User::factory()->create(['name' => 'Maria', 'two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now(), 'remember_token' => 'old']);
    DB::table('sessions')->insert(['id' => 'maria-laptop', 'user_id' => $maria->id, 'payload' => '', 'last_activity' => time()]);

    $component = Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('resetPassword', $maria->id)
        ->assertSet('resetFor', 'Maria');

    $password = $component->get('generatedPassword');
    $maria->refresh();

    expect($password)->toHaveLength(16)
        ->and(Hash::check($password, $maria->password))->toBeTrue()
        ->and($maria->two_factor_secret)->toBeNull()
        ->and($maria->two_factor_confirmed_at)->toBeNull()
        ->and($maria->remember_token)->not->toBe('old')
        ->and(DB::table('sessions')->where('user_id', $maria->id)->count())->toBe(0);

    // your own password is changed in Settings, not here
    $before = $admin->password;
    Livewire::actingAs($admin)->test(Users::class)->call('resetPassword', $admin->id)->assertSet('generatedPassword', null);
    expect($admin->fresh()->password)->toBe($before);
});

test('the forgot-password page asks an administrator while Studio cannot send email, and offers the form once it can', function () {
    config(['mail.default' => 'array']);
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('does not send email yet')
        ->assertDontSee('Email password reset link');

    config(['mail.default' => 'smtp']);
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('Email password reset link')
        ->assertDontSee('does not send email yet');
});

test('an administrator cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('setRole', $admin->id, 'dev');

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('an administrator creates a repository on GitHub and the project is marked for bootstrap', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_login' => 'andrea', 'git_token' => 'github_pat_admin']);

    Http::fake([
        'https://api.github.com/user/orgs*' => Http::response([['login' => 'acme-agency']]),
        'https://api.github.com/orgs/acme-agency/repos' => Http::response(['full_name' => 'acme-agency/shop-next', 'clone_url' => 'https://github.com/acme-agency/shop-next.git', 'html_url' => 'https://github.com/acme-agency/shop-next', 'default_branch' => 'main'], 201),
    ]);

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->assertSet('owners', [['value' => 'acme-agency', 'label' => 'acme-agency']])
        ->set('source', 'new')
        ->set('name', 'Shop Next')
        ->set('new_owner', 'acme-agency')
        ->set('new_name', 'shop-next')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('slug', 'shop-next')->firstOrFail();

    expect($project->repo_url)->toBe('https://github.com/acme-agency/shop-next.git')
        ->and($project->needsBootstrap())->toBeTrue()
        ->and($project->repositoryPath())->toBe('acme-agency/shop-next');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/acme-agency/repos' && $request['name'] === 'shop-next' && $request['private'] === true);
    Queue::assertPushed(ProvisionProjectSite::class);
});

test('creating a repository without a GitHub token is refused', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create(['git_token' => null]);
    Http::fake();

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('create')
        ->set('source', 'new')
        ->set('name', 'Nope')
        ->set('new_name', 'nope')
        ->call('save')
        ->assertHasErrors(['new_name']);

    expect(Project::query()->count())->toBe(0);
});

test('the project form starts on a PHP the server has', function () {
    $admin = User::factory()->admin()->create();

    config(['studio.php_versions' => ['8.3', '8.4', '8.5']]);
    Livewire::actingAs($admin)->test(Projects::class)->assertSet('php_version', '8.4');

    // Ubuntu 26.04 without ppa:ondrej/php: only the PHP the distribution ships
    config(['studio.php_versions' => ['8.5']]);
    Livewire::actingAs($admin)->test(Projects::class)
        ->assertSet('php_version', '8.5')
        ->call('create')
        ->assertSet('php_version', '8.5')
        ->assertSee('8.5');
});
