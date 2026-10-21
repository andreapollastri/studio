<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's own address, the team's: its site, previews and sandboxes under Studio's domain,
 * always behind the Studio login. Besides it, optionally, a public address for the site on a
 * domain of Administration → Domains, online or offline per project (settings.access.public.site).
 *
 * So previews no longer live on a client's domain (preview_domain_id goes; the workspaces move
 * back with studio:configure-projects, run by installer/upgrades/2.4.9.sh), nothing is public at
 * Studio's address, and a domain is no longer online as a whole: a project whose site was public,
 * or on a domain that was online, is online at its public address, when it has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $online = Schema::hasColumn('domains', 'online')
            ? DB::table('domains')->where('online', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        foreach (DB::table('projects')->get(['id', 'site_host', 'site_domain_id', 'settings']) as $project) {
            $settings = json_decode((string) $project->settings, true) ?: [];
            $public = (array) ($settings['access']['public'] ?? []);
            $wasOpen = ($public['site'] ?? false) === true || ($project->site_domain_id !== null && in_array((int) $project->site_domain_id, $online, true));

            $settings['access']['public'] = ['site' => $project->site_host !== null && $wasOpen, 'previews' => false];
            DB::table('projects')->where('id', $project->id)->update(['settings' => json_encode($settings)]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preview_domain_id');
        });

        if (Schema::hasColumn('domains', 'online')) {
            Schema::table('domains', function (Blueprint $table) {
                $table->dropColumn('online');
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('preview_domain_id')->nullable()->after('site_domain_id')->constrained('domains')->restrictOnDelete();
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('online')->default(false);
        });
    }
};
