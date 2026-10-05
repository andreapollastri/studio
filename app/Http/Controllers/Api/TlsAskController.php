<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy asks before issuing a certificate on demand: is this host one of
 * ours? Studio itself, a project site, or a workspace preview.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower(trim((string) $request->query('domain', '')));
        $domain = strtolower((string) config('studio.domain'));

        if ($host === '' || ! str_ends_with($host, '.'.$domain)) {
            return response('', 404);
        }

        $label = substr($host, 0, -strlen('.'.$domain));

        $known = $label === 'studio'
            || Project::query()->where('slug', $label)->exists()
            || Workspace::query()->where('app_url', 'https://'.$host)->exists();

        return response('', $known ? 200 : 404);
    }
}
