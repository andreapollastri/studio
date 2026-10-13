<?php

use App\Infrastructure\Git\Forge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's repository may live on any git host that is on: the account of
 * an administrator it was created or imported with (the repository account)
 * registers its webhook and deploys its site. Each person's git accounts are
 * connector accounts now (see 2026_10_24_000001), so the one token a person
 * had on their user goes: it was copied there as their first account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('git_account_id')->nullable()->after('deploy_user_id')->constrained('connector_accounts')->nullOnDelete();
        });

        // the git account a workspace was last given: a change of preference restarts only the bridges it moves
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('git_account_id')->nullable()->after('branch');
        });

        // the deploying administrator's account on the repository's host
        foreach (DB::table('projects')->whereNotNull('deploy_user_id')->get(['id', 'repo_url', 'deploy_user_id']) as $project) {
            $account = DB::table('connector_accounts')->where('user_id', $project->deploy_user_id)->whereNotNull('host')->orderBy('id')->get(['id', 'host'])
                ->first(fn ($account) => Forge::remoteOn((string) $account->host, (string) $project->repo_url));

            if ($account !== null) {
                DB::table('projects')->where('id', $project->id)->update(['git_account_id' => $account->id]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['git_token', 'git_login']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('git_token')->nullable();
            $table->string('git_login')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('git_account_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('git_account_id');
        });
    }
};
