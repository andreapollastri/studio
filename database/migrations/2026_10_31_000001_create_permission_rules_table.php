<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Always allow": the rules a person added from a permission card (Bash(npm run build:*)),
 * each for one project, handed to Claude Code as --allowedTools on every turn of their
 * conversations there; and on each request, the rules Claude Code itself suggested for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Claude Code's own rule syntax: Tool or Tool(specifier)
            $table->string('rule', 500);
            $table->timestamps();

            $table->unique(['user_id', 'project_id', 'rule']);
        });

        Schema::table('permission_requests', function (Blueprint $table) {
            $table->json('suggestions')->nullable()->after('input');
        });
    }

    public function down(): void
    {
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->dropColumn('suggestions');
        });
        Schema::dropIfExists('permission_rules');
    }
};
