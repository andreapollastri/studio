<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folders of the drive, nested. A folder tied to a project opens to the project's
        // members by role, to read or to write (project_access: {"client": "read", …}),
        // and so does everything below it until another folder says otherwise.
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('drive_folders')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->json('project_access')->nullable();
            $table->string('name', 120);
            $table->timestamps();
        });

        // A file of the drive: its bytes on the local disk under drive/, by a random name.
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drive_folder_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('disk_path', 120);
            $table->unsignedBigInteger('size');
            $table->string('mime', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
