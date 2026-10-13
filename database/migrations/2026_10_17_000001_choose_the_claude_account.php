<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person may have both a Claude subscription (signed in to Claude Code in
 * their home) and an Anthropic API key, and choose which one their
 * conversations use: claude_auth_mode is that choice, a conversation may say
 * otherwise (claude_auth), and claude_signed_in is what the CLI last said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('claude_signed_in')->default(false)->after('claude_token');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('claude_auth', 16)->nullable()->after('effort');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('claude_auth');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('claude_signed_in');
        });
    }
};
