<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keys of the installation next to the personal ones: an SSH key without a
 * person belongs to Studio. Administrators share it with projects and, for
 * each one, decide whether its developers may use it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::create('project_ssh_key', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_key_id')->constrained()->cascadeOnDelete();
            $table->boolean('developers')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'ssh_key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_ssh_key');

        DB::table('ssh_keys')->whereNull('user_id')->delete();
        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
