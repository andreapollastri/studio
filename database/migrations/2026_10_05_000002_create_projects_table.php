<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->string('repo_url');
            $table->string('default_branch', 100)->default('develop');
            $table->string('php_version', 8)->default('8.4');
            $table->string('db_engine', 16)->default('mariadb');
            $table->foreignId('deploy_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('site_status', 20)->default('new');
            $table->text('larapilot_api_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_id')->nullable();
            $table->string('deployed_sha', 64)->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
