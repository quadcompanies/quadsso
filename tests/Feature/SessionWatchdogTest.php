<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The watchdog exists for one situation: the OAuth state reaches the session
 * store and is gone again before the callback reads it. At that point the login
 * flow has done everything right and cannot see what went wrong, because the
 * state was removed by a different request entirely.
 */
class SessionWatchdogTest extends TestCase
{
    use MocksSocialite;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Middleware is registered during boot, so the switch has to be set
        // before the provider runs — not from the body of a test.
        $app['config']->set('quadsso.logging.session_watchdog', true);
        $app['config']->set('quadsso.logging.sso_events', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router) {
            $router->get('login', fn() => 'login form')->name('login');
            $router->post('idle-write', fn() => 'wrote nothing of interest');
        });
    }

    public function test_it_names_the_request_that_consumed_the_state(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'the-nonce'])
            ->get('/auth/sso/callback?state=the-nonce&code=xyz');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'session state observed')
                && $context['state_consumed'] === true
                && $context['path'] === '/auth/sso/callback'
                && $context['method'] === 'GET'
                && $context['state_on_entry'] === substr(hash('sha256', 'the-nonce'), 0, 8)
                && $context['state_on_exit'] === null)
            ->once();
    }

    public function test_the_redirect_leg_shows_the_state_being_introduced(): void
    {
        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'session state observed')
                && $context['state_introduced'] === true
                && $context['state_consumed'] === false
                && $context['path'] === '/auth/sso')
            ->once();
    }

    /**
     * The point of watching every request rather than only the SSO ones: the
     * request that destroys the state is by definition not an SSO request.
     */
    public function test_it_reports_unrelated_requests_too(): void
    {
        Log::spy();
        $this->withSession(['state' => 'the-nonce'])->post('/idle-write');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'session state observed')
                && $context['path'] === '/idle-write'
                && $context['method'] === 'POST'
                && $context['state_consumed'] === false
                && $context['state_on_exit'] === substr(hash('sha256', 'the-nonce'), 0, 8))
            ->once();
    }

    public function test_requests_with_no_state_are_still_accounted_for(): void
    {
        Log::spy();
        $this->get('/login');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'session state observed')
                && $context['state_on_entry'] === null
                && $context['state_on_exit'] === null
                && $context['state_consumed'] === false)
            ->once();
    }
}
