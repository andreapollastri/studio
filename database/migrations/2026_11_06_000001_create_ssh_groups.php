<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SSH page's groups, as the databases' ones: each person's own (Clients, Production…),
 * with a colour and a place in their list, and where each connection they see sits in them,
 * the ones shared with them through a project too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('zinc');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('ssh_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'ssh_connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_preferences');
        Schema::dropIfExists('ssh_groups');
    }
};
