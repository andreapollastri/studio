<?php

use App\Models\Project;
use App\Models\User;

test('the root redirects to the projects page', function () {
    $this->get('/')->assertRedirect('/projects');
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
