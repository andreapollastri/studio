<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the person last opened a conversation: one that finished after that
 * shows a green dot in the list. What is there today counts as seen, or every
 * conversation would turn green on the update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('last_activity_at');
        });

        DB::table('conversations')->update(['seen_at' => DB::raw('last_activity_at')]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
