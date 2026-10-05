<?php

namespace App\Livewire\Settings;

use App\Enums\ClaudeAuthMode;
use App\Git\Providers\GitProvider;
use App\Jobs\SyncWorkspaceCredentials;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * What a person brings to their workspaces: a token for the installation's git
 * provider (GitHub, GitLab, Bitbucket or Azure DevOps), so git and pull requests
 * are theirs, and a Claude credential, so the agent runs on their own plan or key.
 */
#[Layout('layouts::app')]
#[Title('Connections')]
class Connections extends Component
{
    public string $git_token = '';

    public string $claude_auth_mode = 'subscription';

    public string $claude_token = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->claude_auth_mode !== ClaudeAuthMode::None) {
            $this->claude_auth_mode = $user->claude_auth_mode->value;
        }
    }

    public function saveGit(): void
    {
        $this->validate(['git_token' => ['required', 'string', 'min:20', 'max:512']]);
        $git = GitProvider::current();

        try {
            $who = $git->whoAmI($this->git_token);
        } catch (RuntimeException $e) {
            $this->addError('git_token', $e->getMessage());

            return;
        }

        $user = auth()->user();
        $handle = $user->handle ?: $this->freeHandle(User::handleFromLogin($who['login']), $user);

        if ($handle === null) {
            $this->addError('git_token', __('No free handle could be derived from the login :login: ask an administrator.', ['login' => $who['login']]));

            return;
        }

        $user->forceFill(['git_token' => $this->git_token, 'git_login' => $who['login'], 'handle' => $handle])->save();

        $this->git_token = '';
        $this->afterChange($user);

        Flux::toast(variant: 'success', text: __(':provider connected as :login.', ['provider' => $git->label(), 'login' => $who['login']]));
    }

    public function forgetGit(): void
    {
        $user = auth()->user();
        $user->forceFill(['git_token' => null, 'git_login' => null])->save();
        $this->afterChange($user);

        Flux::toast(text: __(':provider token removed.', ['provider' => GitProvider::current()->label()]));
    }

    public function saveClaude(): void
    {
        $this->validate([
            'claude_auth_mode' => ['required', Rule::in(['subscription', 'api_key'])],
            'claude_token' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $user = auth()->user();
        $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::from($this->claude_auth_mode), 'claude_token' => $this->claude_token])->save();

        $this->claude_token = '';
        $this->afterChange($user);

        Flux::toast(variant: 'success', text: __('Claude connected.'));
    }

    public function forgetClaude(): void
    {
        $user = auth()->user();
        $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::None, 'claude_token' => null])->save();
        $this->afterChange($user);

        Flux::toast(text: __('Claude credential removed.'));
    }

    /**
     * The handle becomes a Linux user and the first half of the preview hosts, so it has
     * to be unique and must not land on an existing project's host: the login as it is,
     * or with a short counter.
     */
    private function freeHandle(string $base, User $user): ?string
    {
        foreach (range(1, 9) as $attempt) {
            $handle = $attempt === 1 ? $base : rtrim(substr($base, 0, User::HANDLE_MAX - 2), '-').'-'.$attempt;

            $taken = User::query()->whereNot('id', $user->id)->where('handle', $handle)->exists()
                || Project::siteHostShadowedByHandle($handle) !== null;

            if (! $taken) {
                return $handle;
            }
        }

        return null;
    }

    private function afterChange(User $user): void
    {
        if ($user->workspaces()->where('driver', 'native')->exists()) {
            SyncWorkspaceCredentials::dispatch($user);
        }
    }

    public function render(): View
    {
        return view('livewire.settings.connections', ['user' => auth()->user(), 'git' => GitProvider::current()]);
    }
}
