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

    // ---------------------------------------------------------------------
    // State evidence — captured before Socialite consumes it
    // ---------------------------------------------------------------------

    /**
     * The regression that made the diagnostics lie.
     *
     * Socialite reads the stored state with session()->pull('state'), which
     * removes it. Inspecting the session from the catch block therefore always
     * found a session holding nothing but framework bookkeeping, and reported a
     * session that had round-tripped perfectly as one that never came back —
     * sending at least one investigation after a cookie problem that did not
     * exist. The snapshot is now taken before the handshake runs.
     */
    public function test_a_session_that_carried_state_is_not_reported_as_empty(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'stored-nonce'])
            ->callbackWith(['state' => 'returned-nonce', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => $context['session_empty'] === false
                && $context['state_in_session'] === true)
            ->once();
    }

    /**
     * The distinction the operator actually needs: state was stored, but it
     * belongs to a different login attempt.
     */
    public function test_a_state_mismatch_is_reported_as_a_mismatch(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'stored-nonce'])
            ->callbackWith(['state' => 'returned-nonce', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => $context['state_matches'] === false
                && $context['state_stored_fp'] !== $context['state_returned_fp'])
            ->once();
    }

    public function test_matching_state_values_are_reported_as_matching(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'same-nonce'])
            ->callbackWith(['state' => 'same-nonce', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => $context['state_matches'] === true)
            ->once();
    }

    /**
     * Nonces are one-time values, but they are still credentials in flight, and
     * logs travel further than session stores do.
     */
    public function test_state_values_are_fingerprinted_rather_than_written_out(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'stored-nonce'])
            ->callbackWith(['state' => 'stored-nonce', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                $encoded = json_encode($context);

                return !str_contains($encoded, 'stored-nonce')
                    && $context['state_stored_fp'] === substr(hash('sha256', 'stored-nonce'), 0, 8);
            })
            ->once();
    }

    /**
     * Without this the two legs of a login share no identifier, and pairing them
     * means guessing which `sessions` row belongs to which attempt — which is
     * exactly the manual correlation that goes wrong under pressure.
     */
    public function test_the_session_id_is_recorded_so_the_two_legs_can_be_paired(): void
    {
        $this->fakeInvalidState();

        Log::spy();
        $this->callbackWith(['state' => 'abc', 'code' => 'xyz']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn($message, $context) => ($context['session_id'] ?? null) !== null
                && $context['session_present'] === true)
            ->once();
    }

    /**
     * The trace runs whether or not the handshake succeeds, so a failure that
     * never reaches the catch block still leaves the state evidence behind.
     */
    public function test_the_state_check_is_traced_before_the_handshake_runs(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        $this->fakeInvalidState();

        Log::spy();
        $this->withSession(['state' => 'stored-nonce'])->callbackWith(['state' => 'stored-nonce']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'verifying the OAuth state')
                && $context['state_in_session'] === true)
            ->once();
    }

    // ---------------------------------------------------------------------
    // The redirect leg
    // ---------------------------------------------------------------------

    public function test_the_redirect_leg_records_the_session_and_the_state_it_stored(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'redirecting to the identity provider')
                && ($context['session_id'] ?? null) !== null
                && $context['state_stored_fp'] === substr(hash('sha256', self::FAKE_STATE), 0, 8))
            ->once();
    }

    /**
     * A redirect_uri the IdP does not recognise fails in a way that is otherwise
     * indistinguishable from every other handshake failure once the browser has
     * bounced, and it is never visible in the callback.
     */
    public function test_the_redirect_leg_records_where_it_is_sending_the_browser(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => ($context['authorize_host'] ?? null) === 'idp.example.test'
                && ($context['redirect_uri'] ?? null) === 'http://localhost/auth/sso/callback')
            ->once();
    }

    /**
     * A second login started before the first came back silently overwrites the
     * stored state, and the first callback then fails with nothing to show for
     * it. This is the only place that collision is visible.
     */
    public function test_overwriting_a_pending_state_is_recorded(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->withSession(['state' => 'the-first-attempt'])->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => ($context['replaced_state_fp'] ?? null)
                === substr(hash('sha256', 'the-first-attempt'), 0, 8))
            ->once();
    }

    public function test_a_first_login_records_no_replaced_state(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn($message, $context) => str_contains($message, 'redirecting to the identity provider')
                && !array_key_exists('replaced_state_fp', $context))
            ->once();
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
