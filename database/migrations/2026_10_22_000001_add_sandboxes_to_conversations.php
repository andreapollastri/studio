<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation may run in its own sandbox: a git worktree of the workspace on
 * its own branch, with its own database and preview host. `sandbox` keeps its
 * state (status, branch, path, url, error); `sandbox_host` is the preview host
 * alone, indexed, because the gatekeeper looks it up on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('sandbox')->nullable()->after('effort');
            $table->string('sandbox_host')->nullable()->after('sandbox')->index();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['sandbox_host']);
            $table->dropColumn(['sandbox', 'sandbox_host']);
        });
    }
};
