<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The token a person connects is for the installation's git provider, whichever it is. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('github_token', 'git_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('github_login', 'git_login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('git_token', 'github_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('git_login', 'github_login');
        });
    }
};
