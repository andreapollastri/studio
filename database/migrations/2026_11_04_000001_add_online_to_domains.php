<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A domain of Administration → Domains can be online: the sites and previews at its hosts
 * open to anybody, without the Studio login, as a public site does. Off by default, so a
 * domain added before stays behind the login until an administrator says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('online')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('online');
        });
    }
};
