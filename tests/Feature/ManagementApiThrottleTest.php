<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The shipped default middleware includes throttling. This asserts the alias
 * actually resolves in a host application — a bad default here would take the
 * endpoint down with a 500 on first use.
 */
class ManagementApiThrottleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('quadsso.management.enabled', true);
        $app['config']->set('quadsso.management.api_key', 'k');
        // Deliberately not overriding management.middleware: use the shipped default.
    }

    public function test_default_middleware_is_throttled(): void
    {
        $this->assertSame(['throttle:60,1'], config('quadsso.management.middleware'));
    }

    public function test_the_throttle_alias_resolves(): void
    {
        $response = $this->postJson('/api/quadsso-mgr', ['action' => 'SUSPEND', 'email' => 'nobody@example.test'], ['X-QuadSSO-Key' => 'k']);

        // 404 (no such user) proves the request reached the controller, so the
        // throttle middleware resolved and ran rather than erroring.
        $response->assertNotFound();
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_rate_limit_headers_are_present(): void
    {
        $this->postJson('/api/quadsso-mgr', ['action' => 'SUSPEND', 'email' => 'nobody@example.test'], ['X-QuadSSO-Key' => 'k'])
            ->assertHeader('X-RateLimit-Limit', 60);
    }
}
