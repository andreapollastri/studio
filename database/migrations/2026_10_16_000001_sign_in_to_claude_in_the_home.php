<?php

use App\Jobs\SyncWorkspaceCredentials;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Claude subscription is no longer a token pasted into Studio: Anthropic
 * does not let a platform collect, store or forward claude.ai credentials.
 * The person signs in to Claude Code in their own home on the server instead
 * (Settings → Connections → Sign in with Claude). Stored setup-tokens are
 * dropped here, and the workspaces lose them at the next credential sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('claude_checked_at')->nullable()->after('claude_token');
        });

        $ids = DB::table('users')->where('claude_auth_mode', 'subscription')->pluck('id')->all();
        DB::table('users')->whereIn('id', $ids)->update(['claude_auth_mode' => 'none', 'claude_token' => null]);

        // Take the token out of their workspaces' environment too (a queued job: the helper runs as root).
        if ($ids !== [] && config('studio.driver') === 'native') {
            User::query()->whereIn('id', $ids)->each(fn (User $user) => SyncWorkspaceCredentials::dispatch($user));
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('claude_checked_at');
        });
    }
};
