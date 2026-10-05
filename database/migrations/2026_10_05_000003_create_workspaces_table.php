<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 20);
            $table->string('status', 20)->default('new');
            $table->string('app_url')->nullable();
            $table->string('bridge_url')->nullable();
            $table->text('bridge_token')->nullable();
            $table->text('callback_token')->nullable();
            $table->string('callback_token_hash', 64)->nullable()->unique();
            $table->string('path')->nullable();
            $table->string('branch', 200)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
