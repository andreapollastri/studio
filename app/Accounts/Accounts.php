<?php

namespace App\Accounts;

use App\Git\GitAccount;
use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\CursorAccount;
use App\Models\Project;
use App\Models\ProjectPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which of a person's accounts a project runs on. For Claude: the one chosen
 * for the project, else its group's, else the person's default, else the
 * first ready one. For git: the one chosen for the project, else its group's,
 * else the person's account on the repository's host; an account counts only
 * where the repository lives (a client's GitLab account never pushes to
 * GitHub), so a group's git default simply skips the projects elsewhere.
 */
final class Accounts
{
    public static function preference(User $user, Project $project): ?ProjectPreference
    {
        return ProjectPreference::query()->with(['group.claudeAccount', 'group.gitAccount', 'claudeAccount', 'gitAccount'])
            ->where('user_id', $user->id)->where('project_id', $project->id)->first();
    }

    /** @return Choice<ClaudeAccount>|null */
    public static function claude(User $user, Project $project, ?ProjectPreference $preference = null): ?Choice
    {
        $preference ??= self::preference($user, $project);
        $group = $preference?->group;

        if ($preference?->claudeAccount && $preference->claudeAccount->user_id === $user->id) {
            return new Choice($preference->claudeAccount, Choice::PROJECT);
        }

        if ($group?->claudeAccount && $group->claudeAccount->user_id === $user->id) {
            return new Choice($group->claudeAccount, Choice::GROUP, $group);
        }

        $accounts = $user->claudeAccounts()->orderBy('id')->get();

        if ($default = $accounts->firstWhere('id', $user->claude_account_id)) {
            return new Choice($default, Choice::DEFAULT);
        }

        $first = $accounts->first(fn (ClaudeAccount $account) => $account->isUsable()) ?? $accounts->first();

        return $first ? new Choice($first, Choice::MATCH) : null;
    }

    /**
     * The Cursor subscription a conversation runs on: the first one that is
     * signed in, otherwise the first. A project does not pick one, because
     * each person chooses their own agent.
     */
    public static function cursor(User $user): ?CursorAccount
    {
        $accounts = $user->cursorAccounts()->orderBy('id')->get();

        return $accounts->first(fn (CursorAccount $account) => $account->signed_in) ?? $accounts->first();
    }

    /** @return Choice<ConnectorAccount>|null */
    public static function git(User $user, Project $project, ?ProjectPreference $preference = null): ?Choice
    {
        $preference ??= self::preference($user, $project);
        $group = $preference?->group;
        $fits = fn (?ConnectorAccount $account) => $account !== null && $account->user_id === $user->id && $account->matchesRemote($project->repo_url);

        if ($fits($preference?->gitAccount)) {
            return new Choice($preference->gitAccount, Choice::PROJECT);
        }

        if ($fits($group?->gitAccount)) {
            return new Choice($group->gitAccount, Choice::GROUP, $group);
        }

        $first = self::gitAccountsFor($user, $project)->first();

        return $first ? new Choice($first, Choice::MATCH) : null;
    }

    public static function gitAccount(User $user, Project $project): ?GitAccount
    {
        $choice = self::git($user, $project);

        return $choice ? new GitAccount($choice->account) : null;
    }

    /**
     * The person's accounts on the host of the project's repository, oldest first.
     *
     * @return Collection<int, ConnectorAccount>
     */
    public static function gitAccountsFor(User $user, Project $project): Collection
    {
        return ConnectorAccount::query()->gitFor($user)->orderBy('id')->get()
            ->filter(fn (ConnectorAccount $account) => $account->matchesRemote($project->repo_url))
            ->values();
    }

    /**
     * Keep what the person chose for a project: its group, its accounts (null: the default).
     *
     * @param  array{project_group_id?: int|null, claude_account_id?: int|null, git_account_id?: int|null}  $values
     */
    public static function choose(User $user, Project $project, array $values): ProjectPreference
    {
        return ProjectPreference::query()->updateOrCreate(['user_id' => $user->id, 'project_id' => $project->id], $values);
    }
}
