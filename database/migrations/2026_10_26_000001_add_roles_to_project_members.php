<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person's role is the project's to say: a developer on one project may be the
 * PM of another and a client on a third. Each membership takes the role the person
 * had everywhere until now; the role on the user stays as the administrator flag
 * and as the role a new membership starts with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('user_id');
        });

        foreach (DB::table('users')->get(['id', 'role']) as $user) {
            DB::table('project_members')->where('user_id', $user->id)
                ->update(['role' => in_array($user->role, ['pm', 'dev', 'client'], true) ? $user->role : 'dev']);
        }
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
