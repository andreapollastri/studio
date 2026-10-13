<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A connection may belong to a project: its administrators and developers see host, port and user.
        Schema::table('ssh_connections', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // What one person brings to a connection someone else shared: their own key or
        // password, and their own star. Credentials never travel between people.
        Schema::create('ssh_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ssh_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('auth', 10)->nullable();
            $table->foreignId('ssh_key_id')->nullable()->constrained()->nullOnDelete();
            $table->text('password')->nullable();
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->unique(['ssh_connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_credentials');
        Schema::table('ssh_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
