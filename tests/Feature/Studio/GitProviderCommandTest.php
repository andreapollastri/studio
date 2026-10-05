<?php

use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->envDir = sys_get_temp_dir().'/studio-env-'.uniqid();
    mkdir($this->envDir);
    file_put_contents($this->envDir.'/.env', "APP_NAME=Studio\nSTUDIO_GIT_PROVIDER=github\n");
    app()->useEnvironmentPath($this->envDir);
});

afterEach(function () {
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
});

test('without arguments it shows the provider of the installation', function () {
    config(['studio.git.provider' => 'gitlab', 'studio.git.url' => 'https://git.acme.test']);

    $this->artisan('studio:git-provider')
        ->expectsOutputToContain('GitLab')
        ->expectsOutputToContain('https://git.acme.test')
        ->assertSuccessful();
});

test('while there are no projects the provider changes and the old tokens are forgotten', function () {
    $user = User::factory()->create(['git_token' => 'github_pat_old', 'git_login' => 'maria']);

    $this->artisan('studio:git-provider', ['provider' => 'azure', '--url' => 'https://dev.azure.com/acme/'])
        ->expectsOutputToContain('Azure DevOps')
        ->assertSuccessful();

    expect(file_get_contents($this->envDir.'/.env'))->toContain("STUDIO_GIT_PROVIDER=azure\n")->toContain("STUDIO_GIT_URL=https://dev.azure.com/acme\n")
        ->and($user->fresh()->git_token)->toBeNull()
        ->and($user->fresh()->git_login)->toBeNull();
});

test('with projects, or without the Azure DevOps organization, nothing changes', function () {
    $this->artisan('studio:git-provider', ['provider' => 'azure'])->expectsOutputToContain('STUDIO_GIT_URL')->assertFailed();
    $this->artisan('studio:git-provider', ['provider' => 'gitea'])->assertFailed();

    Project::factory()->create();
    $this->artisan('studio:git-provider', ['provider' => 'gitlab'])->expectsOutputToContain('chosen once')->assertFailed();

    expect(file_get_contents($this->envDir.'/.env'))->toContain('STUDIO_GIT_PROVIDER=github')->not->toContain('gitlab');
});
