<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a conversation runs on. Each person chooses a default (Claude, as before);
 * a conversation copies it when it starts and can switch later. The other CLI
 * cannot resume a session, so switching clears agent_session_id in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('agent', 16)->default('claude');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('agent', 16)->default('claude');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('agent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agent');
        });
    }
};
