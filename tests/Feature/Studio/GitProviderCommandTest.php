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

test('the default host changes, with projects too, and nobody\'s accounts are forgotten', function () {
    $user = User::factory()->withGit('github_pat_old')->create();
    Project::factory()->create();

    $this->artisan('studio:git-provider', ['provider' => 'azure', '--url' => 'https://dev.azure.com/acme/'])
        ->expectsOutputToContain('Azure DevOps')
        ->assertSuccessful();

    expect(file_get_contents($this->envDir.'/.env'))->toContain("STUDIO_GIT_PROVIDER=azure\n")->toContain("STUDIO_GIT_URL=https://dev.azure.com/acme\n")
        ->and($user->gitAccounts()->sole()->credential('token'))->toBe('github_pat_old');
});

test('without the Azure DevOps organization, or for an unknown host, nothing changes', function () {
    $this->artisan('studio:git-provider', ['provider' => 'azure'])->expectsOutputToContain('STUDIO_GIT_URL')->assertFailed();
    $this->artisan('studio:git-provider', ['provider' => 'gitea'])->assertFailed();

    expect(file_get_contents($this->envDir.'/.env'))->toContain('STUDIO_GIT_PROVIDER=github')->not->toContain('azure');
});
