<?php

namespace App\Livewire\Settings;

use App\Claude\ClaudeLogin;
use App\Cursor\CursorLogin;
use App\Enums\ClaudeAuthMode;
use App\Models\ClaudeAccount;
use App\Models\CursorAccount;
use App\Models\ProjectGroup;
use App\Models\ProjectPreference;
use App\Ssh\Gateway;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What a person brings to their work, in one place (Settings → Accounts): git
 * accounts (personal GitHub, work Azure DevOps, a client's GitLab…), each with
 * the identity its commits carry (GitAccounts), SSH keys (SshKeys), and Claude
 * accounts are kept here, as many as the person has: API keys, stored
 * encrypted, and subscriptions, each a sign-in to Claude Code in a folder of
 * its own in the person's home on the server, through Anthropic's own flow
 * (ClaudeLogin): Studio never holds them. One is the default; a group of
 * projects or a single project may use another (Projects). Cursor
 * subscriptions sit beside them: each is the person's own `agent login` in a
 * folder of their home (CursorLogin). Studio never holds that login either,
 * and a Cursor API key is not one of these accounts.
 */
#[Layout('layouts::app')]
#[Title('Accounts')]
class Connections extends Component
{
    /** claude, cursor, git or ssh */
    #[Url]
    public string $tab = 'claude';

    /** The Claude account being added: an API key or a subscription. */
    public string $new_kind = 'api_key';

    public string $new_name = '';

    /** An Anthropic API key, the only Claude credential Studio keeps. */
    public string $new_token = '';

    /** Name of a Cursor subscription being added. */
    public string $new_cursor_name = '';

    /** uuid of the Cursor subscription being renamed */
    public ?string $editing_cursor = null;

    public string $edit_cursor_name = '';

    /** uuid of the Claude account being edited */
    public ?string $editing = null;

    public string $edit_name = '';

    public string $edit_token = '';

    /**
     * A one-time ticket for the person's own `claude auth login` in their home
     * on the server, in the folder of one of their subscriptions; the page opens
     * it in a terminal through the gateway.
     *
     * @return array{ticket: string, url: string}|null
     */
    public function claudeLoginTicket(?string $uuid = null): ?array
    {
        $account = $this->account($uuid);

        if (! $account->isSubscription()) {
            return null;
        }

        if (! ClaudeLogin::onServer()) {
            Flux::toast(variant: 'warning', text: __('In development Claude Code uses the sign-in of this computer: run claude auth login in your terminal, then Check.'));

            return null;
        }

        if (! auth()->user()->ensureHandle()) {
            Flux::toast(variant: 'danger', text: __('No free name could be found for your home on the server: ask an administrator.'));

            return null;
        }

        return ['ticket' => Gateway::issueLogin($account), 'url' => Gateway::url()];
    }

    /** Ask Claude Code whether a subscription is signed in, and keep the answer; quietly, nothing is said. */
    public function refreshClaude(ClaudeLogin $login, ?string $uuid = null, bool $quiet = false): void
    {
        $account = $this->account($uuid);
        $status = $login->refresh($account);

        if ($quiet) {
            return;
        }

        match (true) {
            $status['error'] !== null => Flux::toast(variant: 'danger', text: $status['error']),
            $status['logged_in'] && $status['method'] === 'claude.ai' => Flux::toast(variant: 'success', text: __(':account is signed in with your subscription.', ['account' => $account->name])),
            $status['logged_in'] => Flux::toast(variant: 'warning', text: __('Claude Code is signed in, but not with a Claude subscription (:method).', ['method' => (string) $status['method']])),
            default => Flux::toast(variant: 'warning', text: __('Not signed in to Claude yet.')),
        };
    }

    /** Check every subscription on page load, without a word. */
    public function refreshSubscriptions(ClaudeLogin $login): void
    {
        auth()->user()->claudeAccounts()->where('kind', ClaudeAuthMode::Subscription)->get()
            ->each(fn (ClaudeAccount $account) => $login->refresh($account));
    }

    public function logoutClaude(ClaudeLogin $login, string $uuid): void
    {
        $account = $this->account($uuid);
        $login->logout($account);

        Flux::toast(text: __('Signed out of :account.', ['account' => $account->name]));
    }

    /**
     * Add a Claude account: an API key is checked for its shape and kept; a subscription gets
     * its folder and its uuid goes back to the page, which opens the sign-in for it.
     */
    public function addClaude(): ?string
    {
        $user = auth()->user();

        $this->validate([
            'new_kind' => ['required', Rule::in([ClaudeAuthMode::ApiKey->value, ClaudeAuthMode::Subscription->value])],
            'new_name' => ['required', 'string', 'max:80', Rule::unique('claude_accounts', 'name')->where('user_id', $user->id)],
            ...($this->new_kind === ClaudeAuthMode::ApiKey->value ? ['new_token' => $this->keyRules()] : []),
        ], $this->keyMessages('new_token'));

        $subscription = $this->new_kind === ClaudeAuthMode::Subscription->value;

        $account = $user->claudeAccounts()->create([
            'name' => trim($this->new_name),
            'kind' => $this->new_kind,
            'token' => $subscription ? null : $this->new_token,
            'dir' => $subscription ? ClaudeAccount::freeDir($user) : null,
        ]);

        // the first one is the default: what every project uses until told otherwise
        if ($user->claude_account_id === null) {
            $user->forceFill(['claude_account_id' => $account->id])->save();
        }

        $this->reset('new_name', 'new_token');

        if (! $subscription) {
            Flux::toast(variant: 'success', text: __('API key :account saved.', ['account' => $account->name]));
        }

        return $account->uuid;
    }

    public function edit(string $uuid): void
    {
        $account = $this->account($uuid);

        $this->editing = $account->uuid;
        $this->edit_name = $account->name;
        $this->edit_token = '';
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        $account = $this->account($this->editing);

        $this->validate([
            'edit_name' => ['required', 'string', 'max:80', Rule::unique('claude_accounts', 'name')->where('user_id', $account->user_id)->ignore($account->id)],
            ...(! $account->isSubscription() && $this->edit_token !== '' ? ['edit_token' => $this->keyRules()] : []),
        ], $this->keyMessages('edit_token'));

        $account->name = trim($this->edit_name);

        if (! $account->isSubscription() && $this->edit_token !== '') {
            $account->token = $this->edit_token;
        }

        $account->save();
        $this->cancelEdit();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'edit_name', 'edit_token');
        $this->resetValidation();
    }

    /** The account every project uses unless its group or the project itself says otherwise. */
    public function makeDefault(string $uuid): void
    {
        $account = $this->account($uuid);
        auth()->user()->forceFill(['claude_account_id' => $account->id])->save();

        Flux::toast(text: __(':account is your default Claude account.', ['account' => $account->name]));
    }

    /** Remove a Claude account: a subscription is signed out and its folder goes; groups and projects fall back to the default. */
    public function removeClaude(ClaudeLogin $login, string $uuid): void
    {
        $user = auth()->user();
        $account = $this->account($uuid);

        if ($account->isSubscription()) {
            $login->forget($account);
        }

        $account->delete();

        if ($user->claude_account_id === null || $user->claude_account_id === $account->id) {
            $next = $user->claudeAccounts()->orderBy('id')->get()->first(fn (ClaudeAccount $a) => $a->isUsable()) ?? $user->claudeAccounts()->orderBy('id')->first();
            $user->forceFill(['claude_account_id' => $next?->id])->save();
        }

        Flux::toast(text: __(':account removed.', ['account' => $account->name]));
    }

    /**
     * A one-time ticket for the person's own `agent login` in their home on
     * the server, in the folder of one of their Cursor subscriptions.
     *
     * @return array{ticket: string, url: string}|null
     */
    public function cursorLoginTicket(?string $uuid = null): ?array
    {
        $account = $this->cursorAccount($uuid);

        if (! CursorLogin::onServer()) {
            Flux::toast(variant: 'warning', text: __('In development Cursor uses the sign-in of this computer: run agent login in your terminal, then Check.'));

            return null;
        }

        if (! auth()->user()->ensureHandle()) {
            Flux::toast(variant: 'danger', text: __('No free name could be found for your home on the server: ask an administrator.'));

            return null;
        }

        return ['ticket' => Gateway::issueCursorLogin($account), 'url' => Gateway::url()];
    }

    /** Ask the Cursor CLI whether a subscription is signed in, and keep the answer. */
    public function refreshCursor(CursorLogin $login, ?string $uuid = null, bool $quiet = false): void
    {
        $account = $this->cursorAccount($uuid);
        $status = $login->refresh($account);

        if ($quiet) {
            return;
        }

        match (true) {
            $status['error'] !== null => Flux::toast(variant: 'danger', text: $status['error']),
            $status['logged_in'] && filled($status['email']) => Flux::toast(variant: 'success', text: __(':account is signed in as :email.', ['account' => $account->name, 'email' => $status['email']])),
            $status['logged_in'] => Flux::toast(variant: 'success', text: __(':account is signed in with your Cursor subscription.', ['account' => $account->name])),
            default => Flux::toast(variant: 'warning', text: __('Not signed in to Cursor yet.')),
        };
    }

    /** Check every Cursor subscription on page load, without a word. */
    public function refreshCursorSubscriptions(CursorLogin $login): void
    {
        auth()->user()->cursorAccounts()->get()
            ->each(fn (CursorAccount $account) => $login->refresh($account));
    }

    public function logoutCursor(CursorLogin $login, string $uuid): void
    {
        $account = $this->cursorAccount($uuid);
        $login->logout($account);

        Flux::toast(text: __('Signed out of :account.', ['account' => $account->name]));
    }

    /** Add a Cursor subscription and hand its uuid back so the page opens the sign-in. */
    public function addCursor(): ?string
    {
        $user = auth()->user();

        $this->validate([
            'new_cursor_name' => ['required', 'string', 'max:80', Rule::unique('cursor_accounts', 'name')->where('user_id', $user->id)],
        ]);

        $account = $user->cursorAccounts()->create([
            'name' => trim($this->new_cursor_name),
            'dir' => CursorAccount::freeDir($user),
        ]);

        $this->reset('new_cursor_name');

        return $account->uuid;
    }

    public function editCursor(string $uuid): void
    {
        $account = $this->cursorAccount($uuid);

        $this->editing_cursor = $account->uuid;
        $this->edit_cursor_name = $account->name;
        $this->resetValidation();
    }

    public function saveCursor(): void
    {
        $account = $this->cursorAccount($this->editing_cursor);

        $this->validate([
            'edit_cursor_name' => ['required', 'string', 'max:80', Rule::unique('cursor_accounts', 'name')->where('user_id', $account->user_id)->ignore($account->id)],
        ]);

        $account->name = trim($this->edit_cursor_name);
        $account->save();
        $this->cancelCursor();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function cancelCursor(): void
    {
        $this->reset('editing_cursor', 'edit_cursor_name');
        $this->resetValidation();
    }

    /** Remove a Cursor subscription: signed out, and its folder goes (the first one stays, signed out). */
    public function removeCursor(CursorLogin $login, string $uuid): void
    {
        $account = $this->cursorAccount($uuid);
        $login->forget($account);
        $account->delete();

        Flux::toast(text: __(':account removed.', ['account' => $account->name]));
    }

    private function cursorAccount(?string $uuid): CursorAccount
    {
        return auth()->user()->cursorAccounts()->where('uuid', (string) $uuid)->firstOrFail();
    }

    private function account(?string $uuid): ClaudeAccount
    {
        return auth()->user()->claudeAccounts()->where('uuid', (string) $uuid)->firstOrFail();
    }

    /** @return list<mixed> */
    private function keyRules(): array
    {
        // sk-ant-oat… is a subscription's setup-token: Studio may not hold one
        return ['required', 'string', 'min:20', 'max:2000', 'starts_with:sk-ant-', 'not_regex:/^sk-ant-oat/'];
    }

    /** @return array<string, string> */
    private function keyMessages(string $field): array
    {
        return [
            $field.'.starts_with' => __('An Anthropic API key starts with sk-ant-. For a Claude subscription add a subscription and sign in to Claude Code.'),
            $field.'.not_regex' => __('That is a subscription token: add a subscription and sign in to Claude Code instead: Studio does not keep subscription tokens.'),
        ];
    }

    /**
     * Where each account is used: the groups that take it by default, and how many projects
     * the person chose it for.
     *
     * @return array<int, array{groups: list<string>, projects: int}>
     */
    private function usage(string $column): array
    {
        $user = auth()->user();
        $used = [];

        foreach (ProjectGroup::query()->where('user_id', $user->id)->whereNotNull($column)->get(['name', $column]) as $group) {
            $used[$group->{$column}]['groups'][] = $group->name;
        }

        foreach (ProjectPreference::query()->where('user_id', $user->id)->whereNotNull($column)->pluck($column)->countBy() as $id => $total) {
            $used[$id]['projects'] = $total;
        }

        return array_map(fn (array $u) => ['groups' => $u['groups'] ?? [], 'projects' => $u['projects'] ?? 0], $used);
    }

    public function render(): View
    {
        $user = auth()->user();
        $this->tab = in_array($this->tab, ['claude', 'cursor', 'git', 'ssh'], true) ? $this->tab : 'claude';

        return view('livewire.settings.connections', [
            'user' => $user,
            'claudeAccounts' => $user->claudeAccounts()->orderBy('id')->get(),
            'claudeUsage' => $this->usage('claude_account_id'),
            'cursorAccounts' => $user->cursorAccounts()->orderBy('id')->get(),
        ]);
    }
}
