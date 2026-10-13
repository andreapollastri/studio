<?php

namespace App\Livewire\Settings;

use App\Claude\ClaudeLogin;
use App\Enums\ClaudeAuthMode;
use App\Git\Providers\GitProvider;
use App\Jobs\SyncWorkspaceCredentials;
use App\Models\Project;
use App\Models\User;
use App\Ssh\Gateway;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * What a person brings to their workspaces: a token for the installation's git
 * provider (GitHub, GitLab, Bitbucket or Azure DevOps), so git and pull requests
 * are theirs, and Claude, so the agent runs on their own plan or key. A Claude
 * subscription is a sign-in to Claude Code in their own home on the server,
 * through Anthropic's own flow (ClaudeLogin): Studio never holds it. Only an
 * API key is stored, encrypted.
 */
#[Layout('layouts::app')]
#[Title('Connections')]
class Connections extends Component
{
    public string $git_token = '';

    /** subscription (Sign in with Claude) or api_key */
    public string $claude_auth_mode = 'subscription';

    /** An Anthropic API key, the only Claude credential Studio keeps. */
    public string $claude_token = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->claude_auth_mode !== ClaudeAuthMode::None) {
            $this->claude_auth_mode = $user->claude_auth_mode->value;
        }
    }

    /**
     * A one-time ticket for the person's own `claude auth login` in their home
     * on the server; the page opens it in a terminal through the gateway.
     *
     * @return array{ticket: string, url: string}|null
     */
    public function claudeLoginTicket(): ?array
    {
        $user = auth()->user();

        if (! ClaudeLogin::onServer()) {
            Flux::toast(variant: 'warning', text: __('In development Claude Code uses the sign-in of this computer: run claude auth login in your terminal, then Check.'));

            return null;
        }

        if (! filled($user->handle)) {
            Flux::toast(variant: 'warning', text: __('Connect your git provider first: your home on the server is named after it.'));

            return null;
        }

        return ['ticket' => Gateway::issueLogin($user), 'url' => Gateway::url()];
    }

    /** Ask Claude Code in the person's home whether they are signed in, and keep the answer. */
    public function refreshClaude(ClaudeLogin $login, bool $quiet = false): void
    {
        $user = auth()->user();
        $before = $user->claude_auth_mode;
        $status = $login->refresh($user);

        if ($user->claude_auth_mode !== $before) {
            $this->afterChange($user);
        }

        if ($user->claude_auth_mode === ClaudeAuthMode::Subscription) {
            $this->claude_auth_mode = 'subscription';
        }

        if ($quiet) {
            return;
        }

        match (true) {
            $status['error'] !== null => Flux::toast(variant: 'danger', text: $status['error']),
            $status['logged_in'] && $status['method'] === 'claude.ai' => Flux::toast(variant: 'success', text: __('Signed in to Claude with your subscription.')),
            $status['logged_in'] => Flux::toast(variant: 'warning', text: __('Claude Code is signed in, but not with a Claude subscription (:method).', ['method' => (string) $status['method']])),
            default => Flux::toast(variant: 'warning', text: __('Not signed in to Claude yet.')),
        };
    }

    public function logoutClaude(ClaudeLogin $login): void
    {
        $user = auth()->user();
        $login->logout($user);
        $this->afterChange($user);

        Flux::toast(text: __('Signed out of Claude.'));
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

    /** An Anthropic API key: billed on the person's Console account, set as ANTHROPIC_API_KEY in their workspaces. */
    public function saveClaude(): void
    {
        $this->validate([
            // sk-ant-oat… is a subscription's setup-token: Studio may not hold one
            'claude_token' => ['required', 'string', 'min:20', 'max:2000', 'starts_with:sk-ant-', 'not_regex:/^sk-ant-oat/'],
        ], [
            'claude_token.starts_with' => __('An Anthropic API key starts with sk-ant-. For a Claude subscription use “Sign in with Claude”.'),
            'claude_token.not_regex' => __('That is a subscription token: use “Sign in with Claude” instead, Studio does not keep subscription tokens.'),
        ]);

        $user = auth()->user();
        $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::ApiKey, 'claude_token' => $this->claude_token])->save();

        $this->claude_token = '';
        $this->afterChange($user);

        Flux::toast(variant: 'success', text: __('API key saved.'));
    }

    public function forgetClaude(): void
    {
        $user = auth()->user();
        $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::None, 'claude_token' => null])->save();
        $this->afterChange($user);

        Flux::toast(text: __('API key removed.'));
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
