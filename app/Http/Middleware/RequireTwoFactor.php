<?php

namespace App\Http\Middleware;

use App\Server\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the installation requires two-factor authentication of this person
 * (SecurityPolicy) and they have not set it up, every page leads to Settings →
 * Security until they do: that page, the password confirmation in front of it,
 * the two-factor endpoints it uses, and the way out. A Livewire call is let
 * through only for the components of that page.
 */
class RequireTwoFactor
{
    private const ROUTES = [
        'security.edit', 'password.confirm', 'password.confirmation', 'password.confirm.store', 'logout',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
        'verification.notice', 'verification.verify', 'verification.send',
    ];

    private const COMPONENTS = [
        'pages::settings.security', 'pages::settings.two-factor-setup-modal', 'pages::settings.two-factor.recovery-codes',
    ];

    public function __construct(private readonly SecurityPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->policy->mustSetUpTwoFactor($user) || $this->allowed($request)) {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            abort(403, __('Set up two-factor authentication first: this Studio requires it of your account.'));
        }

        return redirect()->route('security.edit')->with('status', 'two-factor-required');
    }

    private function allowed(Request $request): bool
    {
        if ($request->routeIs(...self::ROUTES)) {
            return true;
        }

        // Livewire's update route, whatever it is named (livewire.update, or default-livewire.update)
        if (! $request->routeIs('*livewire.update')) {
            return false;
        }

        $components = (array) $request->input('components', []);

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;

            if (! in_array($snapshot['memo']['name'] ?? null, self::COMPONENTS, true)) {
                return false;
            }
        }

        return $components !== [];
    }
}
