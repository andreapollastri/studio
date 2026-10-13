<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups (Tools → Backups, and a project's Backups tab): where data lives (an S3 bucket or a
 * folder over SSH), jobs that copy one to the other on as many schedules as wanted, every run
 * they made, and which snapshots the retention keeps (so many daily, weekly, monthly…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // s3 or ssh
            $table->string('kind', 10);
            // the bucket and its keys, or the host, its user, the system key and the pinned host key: encrypted
            $table->text('config');
            $table->string('status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('source_id')->constrained('backup_targets')->restrictOnDelete();
            $table->string('source_path', 1024)->default('');
            $table->foreignId('destination_id')->constrained('backup_targets')->restrictOnDelete();
            $table->string('destination_path', 1024)->default('');
            // archive (tar.gz over SSH), snapshot (a dated copy of the files) or mirror (sync)
            $table->string('mode', 16)->default('archive');
            // pre_command, excludes
            $table->json('options')->nullable();
            // last, daily, weekly, monthly, yearly
            $table->json('retention')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            // hourly, daily, weekly, monthly or cron
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('minute')->default(0);
            $table->unsignedTinyInteger('hour')->default(3);
            // weekly: the days, 0 = Sunday
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('monthday')->default(1);
            $table->string('cron', 120)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_schedule_id')->nullable()->constrained()->nullOnDelete();
            // schedule, manual or restore
            $table->string('trigger', 16);
            // queued, running, success, failed or skipped
            $table->string('status', 16)->default('queued');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // a restore: the run it puts back, and where
            $table->foreignId('restore_of_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->json('restore_to')->nullable();
            // the snapshot: where it went (the job's destination may change later) and its path there
            $table->foreignId('destination_id')->nullable()->constrained('backup_targets')->nullOnDelete();
            $table->string('path', 1024)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('files')->nullable();
            // the retention rules that keep it (daily, weekly…)
            $table->json('kept_by')->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->index(['backup_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_schedules');
        Schema::dropIfExists('backup_jobs');
        Schema::dropIfExists('backup_targets');
    }
};
