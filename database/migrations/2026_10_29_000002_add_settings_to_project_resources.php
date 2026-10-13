<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project does with something attached to it beyond seeing it: for a Cipi app, whether
 * its agent's MCP server (and the server's own) goes to the project's chats, and the tokens
 * typed in when the app's .env does not give them. Encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_resources', function (Blueprint $table) {
            $table->text('settings')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('project_resources', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
