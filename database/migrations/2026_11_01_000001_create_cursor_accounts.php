<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's Cursor subscriptions. Each one is a sign-in to the unmodified
 * Cursor CLI in a folder of their home on the server (~/.cursor for the first,
 * ~/.cursor-<slug> for the others). Studio holds nothing of the login: only
 * the name, the folder, and whether the CLI last said it was signed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursor_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('dir', 60);
            $table->boolean('signed_in')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->unique(['user_id', 'dir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursor_accounts');
    }
};
