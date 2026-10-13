<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure connectors: the clouds and the services the team runs its
 * servers on, driven through their APIs, for the administrators only. An
 * administrator turns a connector on; each one holds any number of accounts, whose
 * credentials are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_settings', function (Blueprint $table) {
            $table->id();
            $table->string('connector', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('connector_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('connector', 40)->index();
            $table->string('name', 80);
            $table->text('credentials');
            $table->string('status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['connector', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_accounts');
        Schema::dropIfExists('connector_settings');
    }
};
