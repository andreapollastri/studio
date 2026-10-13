<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person's SSH keys: the private half encrypted with APP_KEY, unprotected by any passphrase.
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 20);
            $table->unsignedSmallInteger('bits')->nullable();
            $table->text('public_key');
            $table->text('private_key');
            $table->string('fingerprint', 100);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        // A person's saved hosts for the SSH terminal; favourites come first.
        Schema::create('ssh_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('host');
            $table->unsignedInteger('port')->default(22);
            $table->string('username', 64);
            $table->string('auth', 10)->default('key');
            $table->foreignId('ssh_key_id')->nullable()->constrained()->nullOnDelete();
            $table->text('password')->nullable();
            $table->string('host_key', 100)->nullable();
            $table->boolean('favorite')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_connections');
        Schema::dropIfExists('ssh_keys');
    }
};
