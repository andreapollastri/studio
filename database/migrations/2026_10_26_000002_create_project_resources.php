<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thing at a provider (a droplet, a Cloudflare zone, a Cipi app, a Boogle
 * project) that an administrator attached to a project from its connector's page:
 * the roles of the project that see it, the roles that may act on it and the
 * actions they may run. The account and its credentials stay the administrators'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_resources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_account_id')->constrained('connector_accounts')->cascadeOnDelete();
            $table->string('section', 60);
            $table->string('resource_id');
            $table->string('label', 160);
            $table->json('scope')->nullable();
            $table->json('view_roles');
            $table->json('act_roles');
            $table->json('actions');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'connector_account_id', 'section', 'resource_id'], 'project_resources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_resources');
    }
};
