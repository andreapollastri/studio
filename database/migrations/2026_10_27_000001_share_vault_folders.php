<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A folder of the password manager is shared as an item is: with a project, for the
 * roles ticked, and with single people, to view or to edit. What is inside it, its
 * folders and the items added later included, goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_folders', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('parent_id')->constrained()->nullOnDelete();
            $table->json('project_roles')->nullable()->after('project_id');
        });

        Schema::create('vault_folder_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->timestamps();
            $table->unique(['vault_folder_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_folder_user');

        Schema::table('vault_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('project_roles');
        });
    }
};
