<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person puts the projects they see in groups of their own (Personal,
 * Open source, Work, Clients…); a group says which Claude account and which
 * git account its projects use unless a project says otherwise. What a person
 * chose for one project (its group, its accounts) is a preference of theirs:
 * someone else in the same project works with their own accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('zinc');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
            $table->foreignId('git_account_id')->nullable()->constrained('connector_accounts')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('project_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
            $table->foreignId('git_account_id')->nullable()->constrained('connector_accounts')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_preferences');
        Schema::dropIfExists('project_groups');
    }
};
