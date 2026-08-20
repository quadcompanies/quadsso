<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The same routes with the feature OFF, which is the default. A package that
 * silently 404s its host application's routes would be hostile, so the opt-in
 * needs a test of its own.
 */
class LocalAuthPassthroughTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router) {
            $router->get('register', fn() => 'register form')->name('register');
            $router->get('forgot-password', fn() => 'forgot form')->name('password.request');
            $router->get('password/legacy-reset', fn() => 'legacy reset');
        });
    }

    public function test_lockout_is_off_by_default(): void
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
