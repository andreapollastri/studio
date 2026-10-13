<?php

use App\Git\Providers\GitProvider;
use App\Infrastructure\Git\Forges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Git hosts (GitHub, GitLab, Bitbucket, Azure DevOps) as connectors of
 * personal accounts: each person adds as many as they work with, each with
 * the commit identity that goes with it. A shared infrastructure account has
 * no user. The token a person had saved in Settings → Connections becomes
 * their first account on the installation's provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('uuid')->constrained()->cascadeOnDelete();
            $table->string('login')->nullable()->after('name');
            $table->string('host')->nullable()->after('login');
            $table->string('git_name')->nullable()->after('host');
            $table->string('git_email')->nullable()->after('git_name');
            $table->dropUnique(['connector', 'name']);
            $table->unique(['user_id', 'connector', 'name']);
        });

        $this->importConnectionTokens();
    }

    public function down(): void
    {
        DB::table('connector_accounts')->whereNotNull('user_id')->delete();

        Schema::table('connector_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'connector', 'name']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['login', 'host', 'git_name', 'git_email']);
            $table->unique(['connector', 'name']);
        });
    }

    private function importConnectionTokens(): void
    {
        $key = (string) config('studio.git.provider', 'github');

        if (! isset(Forges::ALL[$key]) || ! Schema::hasColumn('users', 'git_token')) {
            return;
        }

        $forge = Forges::make($key);

        try {
            $url = GitProvider::current()->baseUrl();
        } catch (Throwable) {
            return;
        }

        foreach (DB::table('users')->whereNotNull('git_token')->get(['id', 'name', 'email', 'git_token', 'git_login']) as $user) {
            try {
                $token = Crypt::decryptString($user->git_token);
            } catch (Throwable) {
                continue;
            }

            $credentials = array_filter(['token' => $token, 'url' => $key === 'github' || $key === 'bitbucket' ? null : $url]);
            $login = (string) ($user->git_login ?: $user->name);

            DB::table('connector_accounts')->insert([
                'uuid' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'connector' => $key,
                'name' => Str::limit($login, 80, ''),
                'login' => $user->git_login,
                'host' => $forge->host($credentials),
                'git_name' => $user->name,
                'git_email' => $user->email,
                'credentials' => Crypt::encryptString((string) json_encode($credentials)),
                'status' => 'ok · '.$login,
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
