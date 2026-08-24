<?php

namespace QuadCompanies\QuadSSO\Tests\Concerns;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

trait MocksSocialite
{
    /** The state nonce the fake provider stores and echoes back. */
    public const FAKE_STATE = 'fake-state-nonce';

    /**
     * Stand in for a completed authorization at the IdP.
     *
     * $raw becomes $socialUser->user, which is where the callback reads the
     * `email_verified` claim from.
     */
    protected function fakeIdpUser(
        ?string $sub,
        ?string $email = 'ada@example.test',
        ?string $name = 'Ada Lovelace',
        bool $emailVerified = true,
        array $raw = []
    ): void {
        $socialUser = new SocialiteUser();
        $socialUser->id = $sub;
        $socialUser->email = $email;
        $socialUser->name = $name;
        $socialUser->user = array_merge(['email_verified' => $emailVerified], $raw);

        $provider = $this->fakeProvider();
        $provider->shouldReceive('user')->andReturnUsing(function () use ($socialUser) {
            $this->consumeState();

            return $socialUser;
        });

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    /**
     * A provider whose redirect() behaves like the real one: it writes the state
     * nonce to the session and returns the authorization redirect. Without the
     * session write, nothing downstream can tell a working redirect leg from one
     * that silently stored nothing.
     */
    protected function fakeProvider(): Mockery\MockInterface
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);

        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            session()->put('state', self::FAKE_STATE);

            return new RedirectResponse(
                'https://idp.example.test/application/o/authorize/?client_id=test-client'
                . '&redirect_uri=' . urlencode('http://localhost/auth/sso/callback')
                . '&state=' . self::FAKE_STATE
            );
        });

        return $provider;
    }

    /**
     * What Socialite does first inside user(): read the stored state with pull(),
     * which removes it — including on the path that throws InvalidStateException.
     * The mock has to do this too, or a test cannot tell code that inspects the
     * session before the handshake from code that inspects it afterwards, which
     * is the whole point of the handshake snapshot.
     */
    protected function consumeState(): void
    {
        if (app()->bound('session') && app('session')->isStarted()) {
            app('session')->pull('state');
        }
    }

    /**
     * Simulate the IdP handshake blowing up (bad state, network error, ...).
     */
    protected function fakeIdpFailure(string $message = 'invalid state'): void
    {
        $this->fakeIdpException(new \RuntimeException($message));
    }

    /**
     * The specific failure Socialite raises when the OAuth state does not match
     * — thrown with no message at all, which is what made it hard to diagnose.
     */
    protected function fakeInvalidState(): void
    {
        $this->fakeIdpException(new \Laravel\Socialite\Two\InvalidStateException());
    }

    protected function fakeIdpException(\Throwable $e): void
    {
        $provider = $this->fakeProvider();
        $provider->shouldReceive('user')->andReturnUsing(function () use ($e) {
            $this->consumeState();

            throw $e;
        });

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }
}
