<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Server\SiteAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Caddy asks before issuing a certificate on demand: is this host one of
 * ours? Studio itself, a project site, a workspace preview or a sandbox's, on Studio's
 * domain or on a verified one of Administration → Domains. Any other
 * name under the domain gets Studio's welcome page, so it may have one too,
 * within a daily budget: a stranger typing random names must not use up the
 * CA's weekly limit that the real hosts need.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request, SiteAccess $access): Response
    {
        $host = strtolower(trim((string) $request->query('domain', '')));
        $domain = strtolower((string) config('studio.domain'));

        if ($host === '' || $host === 'studio.'.$domain) {
            return response('', $host === '' ? 404 : 200);
        }

        $ours = str_ends_with($host, '.'.$domain);

        if ($access->resolveHost($host) !== null) {
            // A host on another domain: only while that domain still points here
            return response('', $ours || Domain::covering($host)?->isVerified() ? 200 : 404);
        }

        return response('', $ours && $this->welcomes(substr($host, 0, -strlen('.'.$domain))) ? 200 : 404);
    }

    /** One DNS label, once per host (Caddy asks again on a retry or a renewal), at most so many new ones a day. */
    private function welcomes(string $label): bool
    {
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            return false;
        }

        $key = 'tls-welcome:'.$label;

        if (Cache::has($key)) {
            return true;
        }

        if (! RateLimiter::attempt('tls-welcome', (int) config('studio.welcome_certificates_per_day'), fn () => true, 86400)) {
            return false;
        }

        Cache::put($key, true, now()->addDays(100));

        return true;
    }
}
