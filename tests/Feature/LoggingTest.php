<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;
use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The trace is opt-in; refusals are not.
 *
 * The distinction is the point of these tests: you can run with logging off and
 * still have a record of every login this package refused, because the moment
 * you need to answer "why couldn't they sign in" is exactly the moment nobody
 * had the flag turned on.
 */
class LoggingTest extends TestCase
{
    use MocksSocialite;

    private function activeUser(): User
    {
        return User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------------
    // Switch semantics
    // ---------------------------------------------------------------------

    public function test_logging_is_off_by_default(): void
    {
        $this->assertFalse(config('quadsso.logging.enabled'));
        $this->assertFalse(QuadSsoLog::enabled(QuadSsoLog::SSO));
        $this->assertFalse(QuadSsoLog::enabled(QuadSsoLog::API));
    }

    public function test_master_switch_turns_on_every_category(): void
    {
        config(['quadsso.logging.enabled' => true]);

        $this->assertTrue(QuadSsoLog::enabled(QuadSsoLog::SSO));
        $this->assertTrue(QuadSsoLog::enabled(QuadSsoLog::SLO));
        $this->assertTrue(QuadSsoLog::enabled(QuadSsoLog::API));
    }

    public function test_a_category_can_be_switched_on_alone(): void
    {
        config([
            'quadsso.logging.enabled' => false,
            'quadsso.logging.sso_events' => true,
            'quadsso.logging.api_events' => false,
        ]);

        $this->assertTrue(QuadSsoLog::enabled(QuadSsoLog::SSO));
        $this->assertFalse(QuadSsoLog::enabled(QuadSsoLog::API));
    }

    public function test_the_master_switch_overrides_a_category_left_off(): void
    {
        config([
            'quadsso.logging.enabled' => true,
            'quadsso.logging.sso_events' => false,
        ]);

        $this->assertTrue(QuadSsoLog::enabled(QuadSsoLog::SSO));
    }

    // ---------------------------------------------------------------------
    // Authentication steps
    // ---------------------------------------------------------------------

    public function test_a_successful_login_is_silent_when_logging_is_off(): void
    {
        config(['quadsso.logging.enabled' => false, 'quadsso.logging.sso_events' => false]);

        $this->activeUser();
        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso/callback');

        Log::shouldNotHaveReceived('info');
    }

    public function test_a_successful_login_traces_each_step_when_enabled(): void
    {
        config(['quadsso.logging.enabled' => true]);

        $this->activeUser();
        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso/callback');

        foreach ([
            'callback received from the identity provider',
            'identity resolved by external_id',
            'login authorised, session established',
        ] as $step) {
            Log::shouldHaveReceived('info')
                ->withArgs(fn(string $message) => str_contains($message, $step))
                ->once();
        }
    }

    public function test_the_redirect_step_is_traced(): void
    {
        config(['quadsso.logging.enabled' => true]);
        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso');

        Log::shouldHaveReceived('info')
            ->withArgs(fn(string $m) => str_contains($m, 'redirecting to the identity provider'))
            ->once();
    }

    // ---------------------------------------------------------------------
    // Authorization decisions — always recorded
    // ---------------------------------------------------------------------

    public function test_a_blocked_login_is_logged_even_with_logging_off(): void
    {
        config(['quadsso.logging.enabled' => false, 'quadsso.logging.sso_events' => false]);

        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'blocked',
        ]);
        $this->fakeIdpUser(sub: 'sub-ada');

        Log::spy();
        $this->get('/auth/sso/callback');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $m) => str_contains($m, 'login refused: account is blocked'))
            ->once();
    }

    public function test_an_unmatched_identity_is_logged_even_with_logging_off(): void
    {
        config([
            'quadsso.logging.enabled' => false,
            'quadsso.sso.allow_legacy_email_binding' => false,
            'quadsso.sso.enable_jit_provisioning' => false,
        ]);

        $this->fakeIdpUser(sub: 'sub-nobody');

        Log::spy();
        $this->get('/auth/sso/callback');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $m) => str_contains($m, 'no local account matches this identity'))
            ->once();
    }

    public function test_an_unverified_email_refusal_is_logged_even_with_logging_off(): void
    {
        config([
            'quadsso.logging.enabled' => false,
            'quadsso.sso.allow_legacy_email_binding' => true,
        ]);

        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => null, 'status' => 'active',
        ]);
        $this->fakeIdpUser(sub: 'sub-ada', emailVerified: false);

        Log::spy();
        $this->get('/auth/sso/callback');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $m) => str_contains($m, 'requires a verified email'))
            ->once();
    }

    // ---------------------------------------------------------------------
    // Channel routing
    // ---------------------------------------------------------------------

    public function test_a_dedicated_channel_is_used_when_configured(): void
    {
        config(['quadsso.logging.enabled' => true, 'quadsso.logging.channel' => 'quadsso-audit']);

        Log::spy();
        QuadSsoLog::trace(QuadSsoLog::SSO, 'anything');

        Log::shouldHaveReceived('channel')->with('quadsso-audit');
    }

    public function test_the_default_channel_is_used_when_none_is_configured(): void
    {
        config(['quadsso.logging.enabled' => true, 'quadsso.logging.channel' => null]);

        Log::spy();
        QuadSsoLog::trace(QuadSsoLog::SSO, 'anything');

        Log::shouldNotHaveReceived('channel');
        Log::shouldHaveReceived('info')->once();
    }

    public function test_every_message_is_prefixed_for_grepping(): void
    {
        config(['quadsso.logging.enabled' => true]);

        Log::spy();
        QuadSsoLog::trace(QuadSsoLog::SSO, 'something happened');

        Log::shouldHaveReceived('info')->with('QuadSSO: something happened', [])->once();
    }
}
