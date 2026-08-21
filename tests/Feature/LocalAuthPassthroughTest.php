<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The same routes with the lockout explicitly switched off.
 *
 * The default is on, so an application that genuinely serves both password auth
 * and SSO — customers with passwords, staff through the IdP — needs a supported
 * way back to its own routes. This is that escape hatch.
 */
class LocalAuthPassthroughTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Read at boot to decide whether to register the middleware.
        $app['config']->set('quadsso.disable_local_auth.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router) {
            $router->get('register', fn() => 'register form')->name('register');
            $router->get('forgot-password', fn() => 'forgot form')->name('password.request');
            $router->get('password/legacy-reset', fn() => 'legacy reset');
        });
    }

    public function test_the_lockout_can_be_switched_off(): void
    {
        $this->assertFalse(config('quadsso.disable_local_auth.enabled'));
    }

    public function test_registration_stays_reachable(): void
    {
        $this->get('/register')->assertOk()->assertSee('register form');
    }

    public function test_password_reset_stays_reachable(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('forgot form');
    }

    public function test_path_matched_routes_stay_reachable(): void
    {
        $this->get('/password/legacy-reset')->assertOk()->assertSee('legacy reset');
    }
}
