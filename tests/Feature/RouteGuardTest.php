<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Structural guards on the routes this package registers.
 *
 * These assert the middleware stack itself rather than request behaviour,
 * because the failure mode being guarded against is somebody "tidying up" the
 * route file — wrapping everything in a single web group, or adding auth to the
 * callback — which breaks SSO in ways that only show up against a live IdP.
 */
class RouteGuardTest extends TestCase
{
    /**
     * Testbench ships an empty 'web' group. Define the real Laravel stack so
     * that resolving a route's middleware says something meaningful about what
     * would actually run in a host application.
     *
     * Applied after boot, not in defineEnvironment: appending middleware via
     * the HTTP kernel calls syncMiddlewareToRouter(), which overwrites
     * router-level group definitions with the kernel's own. In a real
     * application the kernel is the source of truth so that is correct, but a
     * group registered straight onto the router beforehand would be discarded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->middlewareGroup('web', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    }

    /**
     * Expand middleware group names into the concrete middleware they contain.
     */
    private function resolvedMiddleware(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "route [$routeName] is not registered");

        $groups = $this->app['router']->getMiddlewareGroups();
        $resolved = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (isset($groups[$middleware])) {
                $resolved = array_merge($resolved, $groups[$middleware]);
                continue;
            }

            $resolved[] = $middleware;
        }

        return $resolved;
    }

    public function test_registers_exactly_the_three_expected_routes(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn($route) => $route->methods()[0] . ' ' . $route->uri())
            ->filter(fn($uri) => str_contains($uri, 'auth/sso'))
            ->values()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'GET auth/sso',
            'GET auth/sso/callback',
            'POST auth/sso/logout',
        ], $uris);
    }

    public function test_no_scim_routes_survive(): void
    {
        $scimRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn($route) => str_starts_with($route->uri(), 'scim'))
            ->map(fn($route) => $route->uri())
            ->all();

        $this->assertSame([], $scimRoutes, 'SCIM support was removed; no scim routes should exist');
    }

    // ---------------------------------------------------------------------
    // The interactive routes need session state
    // ---------------------------------------------------------------------

    public function test_redirect_route_is_session_backed_and_guest_only(): void
    {
        $declared = Route::getRoutes()->getByName('sso.redirect')->gatherMiddleware();

        $this->assertContains('web', $declared, 'the OAuth state parameter needs session state');
        $this->assertContains('guest', $declared);

        $this->assertContains(
            \Illuminate\Session\Middleware\StartSession::class,
            $this->resolvedMiddleware('sso.redirect')
        );
    }

    public function test_callback_route_keeps_session_and_csrf(): void
    {
        $this->assertContains('web', Route::getRoutes()->getByName('sso.callback')->gatherMiddleware());

        $middleware = $this->resolvedMiddleware('sso.callback');

        $this->assertContains(
            \Illuminate\Session\Middleware\StartSession::class,
            $middleware,
            'the OAuth state parameter lives in the session'
        );
        $this->assertContains(VerifyCsrfToken::class, $middleware);
    }

    public function test_callback_route_is_not_behind_auth(): void
    {
        $middleware = $this->resolvedMiddleware('sso.callback');

        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains(\Illuminate\Auth\Middleware\Authenticate::class, $middleware);
    }

    // ---------------------------------------------------------------------
    // The back-channel route must NOT be
    // ---------------------------------------------------------------------

    /**
     * Authentik posts server-to-server with no browser session and no CSRF
     * token. Inside the web group, VerifyCsrfToken rejects every one of those
     * with HTTP 419 and Single Logout silently stops working.
     */
    public function test_slo_route_is_not_behind_csrf(): void
    {
        $this->assertNotContains(
            'web',
            Route::getRoutes()->getByName('sso.logout')->gatherMiddleware(),
            'putting SLO in the web group reintroduces CSRF and breaks it with HTTP 419'
        );

        $this->assertNotContains(VerifyCsrfToken::class, $this->resolvedMiddleware('sso.logout'));
    }

    public function test_slo_route_is_not_behind_session_or_auth(): void
    {
        $middleware = $this->resolvedMiddleware('sso.logout');

        $this->assertNotContains(\Illuminate\Session\Middleware\StartSession::class, $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('guest', $middleware);
    }

    public function test_slo_route_carries_no_middleware_at_all(): void
    {
        $this->assertSame(
            [],
            Route::getRoutes()->getByName('sso.logout')->gatherMiddleware(),
            'the logout token is the only access control on this endpoint'
        );
    }

    public function test_slo_endpoint_rejects_get(): void
    {
        $this->get('/auth/sso/logout')->assertStatus(405);
    }
}
