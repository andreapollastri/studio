<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tools → Databases: the connections a person keeps to MySQL, MariaDB, PostgreSQL, SQLite,
 * Redis and Valkey (direct or through an SSH tunnel, personal or shared with a project's
 * roles), each person's groups and stars, the queries each person ran on them, and the imports and exports made on the
 * databases queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // shared: the project's roles reach it as project_access says (role => read|write)
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->json('project_access')->nullable();
            $table->string('name', 60);
            // mysql, mariadb, pgsql, sqlite, redis or valkey
            $table->string('driver', 10);
            // local, development, testing, staging or production
            $table->string('environment', 12)->default('development');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 120)->nullable();
            $table->text('password')->nullable();
            // the default database, SQLite's file, Redis's database number
            $table->string('database', 1024)->nullable();
            $table->boolean('tls')->default(false);
            // the SSH connection the tunnel goes through, and whose credential opens it:
            // the owner's (members reach this database only) or each member's own
            $table->foreignId('ssh_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tunnel_as', 8)->default('owner');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        // Each person sorts the connections they see into groups of their own, coloured, and
        // stars some: their own and those shared with them alike.
        Schema::create('database_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('zinc');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('database_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_group_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'database_connection_id']);
        });

        // What each person ran in the SQL editor or the Redis console: their history, and the
        // queries they saved under a name.
        Schema::create('database_queries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('database', 255)->nullable();
            $table->text('sql');
            $table->string('title', 120)->nullable();
            $table->boolean('saved')->default(false);
            // ok or error
            $table->string('status', 8)->default('ok');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'database_connection_id', 'saved']);
        });

        Schema::create('database_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // export or import
            $table->string('kind', 8);
            // sql, csv, json (redis: json)
            $table->string('format', 8);
            // the database, the tables or the query, the options of the format
            $table->json('scope');
            // queued, running, success or failed
            $table->string('status', 10)->default('queued');
            // the file: the export made, or the file to import; on the local disk
            $table->string('file_path', 1024)->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedBigInteger('rows')->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['database_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_transfers');
        Schema::dropIfExists('database_queries');
        Schema::dropIfExists('database_preferences');
        Schema::dropIfExists('database_groups');
        Schema::dropIfExists('database_connections');
    }
};
