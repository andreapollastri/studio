<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The databases the project's Db tab opens for a person (their workspace's, the project
 * site's) are connections Studio keeps for them: `managed` says which (workspace:<id>,
 * site:<id>), their credentials are refreshed on every open, and Tools → Databases does
 * not list them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->string('managed', 120)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->dropIndex(['managed']);
            $table->dropColumn('managed');
        });
    }
};
