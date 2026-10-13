<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the chat's status bar shows as it is, not as it was asked for:
 * conversations.runtime is what Claude Code said when it started (the model it
 * really runs, the credential it really uses); users.claude_usage is the last
 * word of the CLI on the person's plan limits (its rate_limit_event).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('runtime')->nullable()->after('effort');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('claude_usage')->nullable()->after('claude_signed_in');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('runtime');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('claude_usage');
        });
    }
};
