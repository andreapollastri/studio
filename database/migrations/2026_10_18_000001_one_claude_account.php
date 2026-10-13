<?php

use App\Jobs\SyncWorkspaceCredentials;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Claude account per person after all: their subscription (signed in to
 * Claude Code in their home) or an API key, as chosen in Settings → Connections.
 * Conversations no longer pick their own; whoever had both keeps the one they
 * had chosen, and the key goes when that was the subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversations', 'claude_auth')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('claude_auth');
            });
        }

        $dropped = DB::table('users')->where('claude_auth_mode', 'subscription')->whereNotNull('claude_token')->pluck('id')->all();
        DB::table('users')->whereIn('id', $dropped)->update(['claude_token' => null]);
        DB::table('users')->where('claude_auth_mode', 'none')->whereNotNull('claude_token')->update(['claude_auth_mode' => 'api_key']);
        DB::table('users')->where('claude_auth_mode', 'none')->where('claude_signed_in', true)->update(['claude_auth_mode' => 'subscription']);

        if ($dropped !== [] && config('studio.driver') === 'native') {
            User::query()->whereIn('id', $dropped)->each(fn (User $user) => SyncWorkspaceCredentials::dispatch($user));
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('claude_auth', 16)->nullable()->after('effort');
        });
    }
};
