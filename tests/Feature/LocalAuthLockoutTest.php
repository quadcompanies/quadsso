<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Exercises the lockout with the feature ON.
 *
 * The routes defined below stand in for the scaffolding a host application
 * would have published (Breeze naming), including one unnamed route that only
 * the URI patterns can catch.
 */
class LocalAuthLockoutTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router) {
            $router->get('register', fn() => 'register form')->name('register');
            $router->post('register', fn() => 'registered')->name('register.store');
            $router->get('forgot-password', fn() => 'forgot form')->name('password.request');
            $router->post('forgot-password', fn() => 'link sent')->name('password.email');
            $router->get('reset-password/{token}', fn() => 'reset form')->name('password.reset');
            $router->post('reset-password', fn() => 'password stored')->name('password.store');
            $router->get('login', fn() => 'login form')->name('login');
            $router->get('dashboard', fn() => 'dashboard')->name('dashboard');

            // No name — only the path patterns can catch this one.
            $router->get('password/legacy-reset', fn() => 'legacy reset');
        });
    }

    /**
     * Nothing switches this on: installing the package is the opt-in. A
     * developer who forgets a flag would otherwise leave the password-reset
     * bypass open, which is exactly the failure this default exists to prevent.
     */
    public function test_the_lockout_is_on_by_default(): void
    {
        $this->assertTrue(config('quadsso.disable_local_auth.enabled'));
    }

    public function test_blocks_the_registration_form(): void
    {
        $this->get('/register')->assertRedirect('/auth/sso');
    }

    public function test_blocks_registration_submission(): void
    {
        $this->post('/register')->assertRedirect('/auth/sso');
    }

    public function test_blocks_the_forgot_password_form(): void
    {
        $this->get('/forgot-password')->assertRedirect('/auth/sso');
    }

    public function test_blocks_the_password_reset_link_submission(): void
    {
        $this->post('/forgot-password')->assertRedirect('/auth/sso');
    }

    /**
     * The route that actually closes the bypass: without it a provisioned user
     * can set a password they know and log in locally forever after.
     */
    public function test_blocks_the_reset_form(): void
    {
        $this->get('/reset-password/some-token')->assertRedirect('/auth/sso');
    }

    public function test_blocks_the_reset_submission(): void
    {
        $this->post('/reset-password')->assertRedirect('/auth/sso');
    }

    public function test_blocks_unnamed_routes_by_path_pattern(): void
    {
        $this->get('/password/legacy-reset')->assertRedirect('/auth/sso');
    }

    /**
     * Deliberately left reachable: blocking it removes the break-glass path if
     * SSO itself is broken.
     */
    public function test_leaves_the_login_route_alone_by_default(): void
    {
        $this->get('/login')->assertOk()->assertSee('login form');
    }

    public function test_leaves_unrelated_routes_alone(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    public function test_login_can_be_blocked_by_configuration(): void
    {
        config(['quadsso.disable_local_auth.route_names' => ['login']]);

        $this->get('/login')->assertRedirect('/auth/sso');
    }

    public function test_can_respond_with_404_instead_of_redirecting(): void
    {
        config(['quadsso.disable_local_auth.response' => '404']);

        $this->get('/register')->assertNotFound();
    }

    /**
     * If the redirect target is itself blocked, redirecting would bounce until
     * the browser gives up.
     */
    public function test_does_not_redirect_into_a_loop(): void
    {
        config(['quadsso.disable_local_auth.redirect_to' => '/register']);

        $this->get('/register')->assertNotFound();
    }

    public function test_does_not_block_the_packages_own_sso_routes(): void
    {
        $this->assertNotContains('auth/sso', config('quadsso.disable_local_auth.paths'));

        // The SLO endpoint is outside the web group entirely, so the lockout
        // middleware cannot reach it in any configuration.
        $this->post('/auth/sso/logout', [])->assertStatus(400);
    }
}
