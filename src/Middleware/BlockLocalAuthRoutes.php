<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

/**
 * Block the host application's local registration and password-reset routes so
 * that the identity provider stays the only way in.
 *
 * This runs at request time rather than trying to prevent route registration.
 * A package has no hook to un-register routes that Breeze, Fortify, or Laravel
 * UI publish into the application itself, route precedence between package and
 * app providers is version-dependent, and Laravel's RouteCollection has no
 * public removal API that survives `route:cache`. Matching the resolved route
 * on the way in is the only approach that holds in all of those cases.
 *
 * Why password reset in particular matters: users provisioned through SSO get a
 * random, unusable password hash. If the reset flow stays reachable, such a user
 * can set a password they know at their (IdP-verified) address and from then on
 * authenticate locally — bypassing the IdP entirely, including any deactivation
 * there. Blocking the route closes that path.
 *
 * This is a convenience control, not a guarantee. It matches known route names
 * and URI patterns; an application with its own bespoke registration controller
 * on an unlisted path will not be caught. Treat it as defence in depth on top of
 * disabling password authentication in the application itself.
 */
class BlockLocalAuthRoutes
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!config('quadsso.disable_local_auth.enabled', false)) {
            return $next($request);
        }

        if (!$this->shouldBlock($request)) {
            return $next($request);
        }

        QuadSsoLog::trace(QuadSsoLog::SSO, 'blocked a local authentication route', [
            'route' => $request->route()?->getName(),
            'path'  => $request->path(),
            'ip'    => $request->ip(),
        ]);

        return $this->deny($request);
    }

    /**
     * Match on the resolved route name first, then fall back to URI patterns so
     * that unnamed routes are still covered.
     */
    private function shouldBlock(Request $request): bool
    {
        $name = $request->route()?->getName();
        $names = (array) config('quadsso.disable_local_auth.route_names', []);

        if ($name !== null && in_array($name, $names, true)) {
            return true;
        }

        $paths = (array) config('quadsso.disable_local_auth.paths', []);

        return $paths !== [] && $request->is(...$paths);
    }

    private function deny(Request $request): mixed
    {
        $response = config('quadsso.disable_local_auth.response', 'redirect');

        if ($response === 'redirect') {
            $target = (string) config('quadsso.disable_local_auth.redirect_to', '/auth/sso');

            // Never redirect a blocked request at a target that is itself
            // blocked — that loops until the browser gives up.
            if ($target !== '' && !$request->is(ltrim($target, '/'))) {
                return redirect($target);
            }
        }

        abort(404);
    }
}
