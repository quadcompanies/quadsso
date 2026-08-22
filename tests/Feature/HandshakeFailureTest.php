<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Socialite throws InvalidStateException with no message, so the callback used
 * to log `{"error":""}` — accurate and useless. Since a state mismatch is the
 * most common way an SSO login fails, the log line has to carry enough to tell
 * its causes apart without a round trip to the package author.
 */
class HandshakeFailureTest extends TestCase
{
    use MocksSocialite;

    private function callbackWith(array $query = [])
    {
        return $this->get('/auth/sso/callback' . ($query ? '?' . http_build_query($query) : ''));
    }

    public function test_an_invalid_state_is_named_rather_than_logged_blank(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith()->assertRedirect('/login');

        Log::shouldHaveReceived('error')
            ->withArgs(fn(string $message) => str_contains($message, 'state did not survive the round trip'))
            ->once();
    }

    public function test_the_exception_class_is_recorded(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith();

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => ($context['exception'] ?? null)
                === \Laravel\Socialite\Two\InvalidStateException::class)
            ->once();
    }

    /**
     * The host is what distinguishes a www/apex cookie split from every other
     * cause, and it is not otherwise in the log line.
     */
    public function test_the_request_host_is_recorded(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith();

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => array_key_exists('host', $context))
            ->once();
    }

    public function test_it_records_whether_state_and_code_came_back(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith(['state' => 'abc', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => $context['state_returned'] === true
                && $context['code_returned'] === true)
            ->once();
    }

    public function test_a_session_that_never_came_back_is_flagged(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith();

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => $context['session_empty'] === true)
            ->once();
    }

    /**
     * A refusal at the IdP arrives as query parameters, not an exception, and
     * was previously dropped entirely.
     */
    public function test_an_idp_refusal_is_surfaced(): void
    {
        $this->fakeIdpFailure('token request failed');

        Log::spy();
        $this->callbackWith(['error' => 'access_denied', 'error_description' => 'User denied consent']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => ($context['idp_error'] ?? null) === 'access_denied'
                && ($context['idp_error_description'] ?? null) === 'User denied consent')
            ->once();
    }

    public function test_other_failures_keep_the_generic_message_and_their_own_text(): void
    {
        $this->fakeIdpFailure('Connection refused');

        Log::spy();
        $this->callbackWith();

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => str_contains($message, 'identity provider handshake failed')
                && $context['error'] === 'Connection refused')
            ->once();
    }

    /**
     * Diagnostics belong in the log. The visitor gets a generic message —
     * telling them about cookie domains and SameSite policy would be noise at
     * best and a hint about the deployment at worst.
     */
    public function test_the_user_facing_message_stays_generic(): void
    {
        $this->fakeInvalidState();

        $this->get('/auth/sso/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['sso'], null, config('quadsso.ui.error_bag'));

        $shown = session('errors')->getBag(config('quadsso.ui.error_bag'))->first('sso');

        $this->assertSame('Authentication failed. Please try again.', $shown);
        $this->assertStringNotContainsString('SESSION_SAME_SITE', $shown);
        $this->assertGuest();
    }

    public function test_failures_are_logged_even_with_logging_switched_off(): void
    {
        config(['quadsso.logging.enabled' => false, 'quadsso.logging.sso_events' => false]);

        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith();

        Log::shouldHaveReceived('error')->once();
    }
}
