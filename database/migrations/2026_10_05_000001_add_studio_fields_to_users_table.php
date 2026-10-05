<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('dev')->after('email');
            $table->string('handle', 64)->nullable()->unique()->after('remember_token');
            $table->text('github_token')->nullable()->after('handle');
            $table->string('github_login', 64)->nullable()->after('github_token');
            $table->string('claude_auth_mode', 16)->default('none')->after('github_login');
            $table->text('claude_token')->nullable()->after('claude_auth_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'handle', 'github_token', 'github_login', 'claude_auth_mode', 'claude_token']);
        });
    }
};
