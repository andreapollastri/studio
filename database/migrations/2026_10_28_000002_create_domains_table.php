<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains other than Studio's own, accepted by an administrator once their DNS points at this
 * server: a project's site may live at one of them (its usual `<key>.<domain>` stays as a
 * second address), and its previews under a wildcard one. Caddy gets a certificate for a host
 * of theirs only while the domain is verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name')->unique();
            // *.name too: sites under it and the previews of the projects that pick it
            $table->boolean('wildcard')->default(false);
            $table->string('status')->default('pending');
            // what the DNS answered at the last check: {name: [ips], wildcard: [ips], expected: [ips]}
            $table->json('records')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            // the site's own address on one of those domains; null = `<key>.<domain>`
            $table->string('site_host')->nullable()->unique()->after('slug');
            $table->foreignId('site_domain_id')->nullable()->after('site_host')->constrained('domains')->restrictOnDelete();
            // where the previews and sandboxes live; null = Studio's domain
            $table->foreignId('preview_domain_id')->nullable()->after('site_domain_id')->constrained('domains')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preview_domain_id');
            $table->dropConstrainedForeignId('site_domain_id');
            $table->dropUnique(['site_host']);
            $table->dropColumn('site_host');
        });

        Schema::dropIfExists('domains');
    }
};
