<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('agent_session_id')->nullable();
            $table->string('status', 20)->default('idle');
            $table->string('permission_mode', 32)->default('default');
            $table->string('model', 64)->nullable();
            $table->string('effort', 16)->nullable();
            $table->json('last_result')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('kind', 24);
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->string('agent_uuid')->nullable();
            $table->string('tool_use_id')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('request_id');
            $table->string('tool_name', 64);
            $table->text('description')->nullable();
            $table->json('input')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
