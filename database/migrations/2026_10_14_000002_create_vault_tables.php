<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person's folders in the password manager, nested.
        Schema::create('vault_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('vault_folders')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
        });

        // One secret. Everything but title and URL is encrypted with APP_KEY.
        Schema::create('vault_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->json('project_roles')->nullable();
            $table->string('title', 120);
            $table->string('url', 500)->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('notes')->nullable();
            $table->text('totp_secret')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Shared with one person, to read or also to change.
        Schema::create('vault_item_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->timestamps();
            $table->unique(['vault_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_item_user');
        Schema::dropIfExists('vault_items');
        Schema::dropIfExists('vault_folders');
    }
};
