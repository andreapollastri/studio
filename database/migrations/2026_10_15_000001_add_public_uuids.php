<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A public identifier for everything that shows up in a URL or in the page:
 * links, buttons and pickers carry the uuid, never the sequential id. The
 * integer keys stay where nobody sees them: relations, and the ports derived
 * from them (a workspace's bridge at 42000 + id, Reverb at 9000 + project id).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'users', 'projects', 'workspaces', 'conversations', 'permission_requests', 'project_mcp_servers',
        'ssh_keys', 'ssh_connections', 'vault_folders', 'vault_items', 'drive_folders', 'drive_files',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            DB::table($table)->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
