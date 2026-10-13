<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A person may have several Claude accounts: API keys (stored encrypted) and
 * subscriptions, each signed in to Claude Code in a folder of its own in the
 * person's home (~/.claude for the first, ~/.claude-<slug> for the others;
 * Studio holds nothing for them). One is the person's default; a group of
 * projects or a single project may pick another. The one account a person
 * had in Settings → Connections becomes the first, and their default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->text('token')->nullable();
            $table->string('dir', 60)->nullable();
            $table->boolean('signed_in')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
            $table->unique(['user_id', 'dir']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('claude_account_id')->nullable()->after('handle')->constrained('claude_accounts')->nullOnDelete();
        });

        $this->importTheOneAccount();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['claude_auth_mode', 'claude_token', 'claude_checked_at', 'claude_signed_in', 'claude_usage']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('claude_auth_mode')->default('none');
            $table->text('claude_token')->nullable();
            $table->timestamp('claude_checked_at')->nullable();
            $table->boolean('claude_signed_in')->default(false);
            $table->json('claude_usage')->nullable();
        });

        foreach (DB::table('users')->whereNotNull('claude_account_id')->get(['id', 'claude_account_id']) as $user) {
            $account = DB::table('claude_accounts')->where('id', $user->claude_account_id)->first();

            if ($account === null) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update([
                'claude_auth_mode' => $account->kind,
                'claude_token' => $account->token,
                'claude_checked_at' => $account->checked_at,
                'claude_signed_in' => $account->signed_in,
                'claude_usage' => $account->usage,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claude_account_id');
        });

        Schema::dropIfExists('claude_accounts');
    }

    private function importTheOneAccount(): void
    {
        $users = DB::table('users')->get(['id', 'claude_auth_mode', 'claude_token', 'claude_checked_at', 'claude_signed_in', 'claude_usage']);

        foreach ($users as $user) {
            $chosen = null;

            // the key is kept even when the subscription was the one in use, and the other way round
            if (filled($user->claude_token)) {
                $id = $this->insert($user->id, 'API key', 'api_key', ['token' => $user->claude_token]);
                $chosen = $user->claude_auth_mode === 'api_key' ? $id : null;
            }

            if ($user->claude_auth_mode === 'subscription' || $user->claude_signed_in) {
                $id = $this->insert($user->id, 'Claude subscription', 'subscription', [
                    'dir' => '.claude',
                    'signed_in' => (bool) $user->claude_signed_in,
                    'checked_at' => $user->claude_checked_at,
                    'usage' => $user->claude_usage,
                ]);
                $chosen = $user->claude_auth_mode === 'subscription' ? $id : ($chosen ?? $id);
            }

            if ($chosen !== null) {
                DB::table('users')->where('id', $user->id)->update(['claude_account_id' => $chosen]);
            }
        }
    }

    /** @param  array<string, mixed>  $values */
    private function insert(int $userId, string $name, string $kind, array $values): int
    {
        return (int) DB::table('claude_accounts')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'user_id' => $userId,
            'name' => $name,
            'kind' => $kind,
            ...$values,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
