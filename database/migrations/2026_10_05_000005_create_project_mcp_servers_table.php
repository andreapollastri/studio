<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remote MCP servers that the chats of a project get, for the roles chosen.
        Schema::create('project_mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('url', 500);
            $table->text('headers')->nullable();
            $table->json('roles');
            $table->boolean('enabled')->default(true);
            $table->string('status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_mcp_servers');
    }
};
