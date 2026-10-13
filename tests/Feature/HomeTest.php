<?php

use App\Enums\ConversationStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceStatus;
use App\Models\BackupJob;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoSeeder;

test('the root is the home page: guests log in first', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Get started');
});

test('the home page puts first what waits for the person, across their projects', function () {
    $user = User::factory()->withGit()->withClaude()->create(['name' => 'Maria Rossi']);
    $shop = Workspace::factory()->running()->create(['user_id' => $user->id]);
    $other = Workspace::factory()->create();

    Conversation::factory()->for($shop)->create(['user_id' => $user->id, 'title' => 'Date filter', 'status' => ConversationStatus::WaitingPermission, 'last_activity_at' => now()]);
    Conversation::factory()->for($shop)->create(['user_id' => $user->id, 'title' => 'Quiet one', 'status' => ConversationStatus::Idle]);
    Conversation::factory()->for($other)->create(['user_id' => $other->user_id, 'title' => 'Not mine', 'status' => ConversationStatus::WaitingPermission]);
    $shop->update(['status' => WorkspaceStatus::Failed, 'last_error' => 'composer install failed']);

    $this->actingAs($user)->get('/')->assertOk()
        ->assertSee('Hi Maria')
        ->assertSee(['Waiting for you', 'Date filter', $shop->project->name])
        ->assertDontSee(['Quiet one', 'Not mine', 'Get started'])
        ->assertSee('composer install failed');
});

test('guests are sent to the login page', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('a member sees only the projects they belong to', function () {
    $user = User::factory()->create();
    $mine = Project::factory()->create(['name' => 'Mio progetto']);
    $other = Project::factory()->create(['name' => 'Altro progetto']);
    $mine->members()->attach($user);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Mio progetto')
        ->assertDontSee('Altro progetto');
});

test('an administrator sees every project', function () {
    $admin = User::factory()->admin()->create();
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->create(['name' => 'Beta']);

    $this->actingAs($admin)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('Beta');
});

test('the demo seeds an agency with roles per project, a service account, the shop\'s infrastructure and its backups, twice over', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $luca = User::query()->where('email', 'luca@agency.test')->sole();
    $apis = Project::query()->where('name', 'API')->pluck('slug');

    expect($apis)->toHaveCount(2)
        ->and($apis[0])->not->toBe($apis[1])
        ->and($luca->roleIn(Project::query()->where('slug', 'shop-acme')->sole()))->toBe(UserRole::Dev)
        ->and($luca->roleIn(Project::query()->where('slug', 'loyalty')->sole()))->toBe(UserRole::Pm)
        // the Droplet, the Cipi app, the zone, the Boogle project and the bucket of the shop
        ->and(ProjectResource::query()->visibleTo($luca)->count())->toBe(5)
        ->and(BackupJob::query()->count())->toBe(3)
        ->and(BackupJob::query()->where('name', 'Shop Acme · site and database')->sole()->schedules()->count())->toBe(3);
});
