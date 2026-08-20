<?php

namespace QuadCompanies\QuadSSO\Tests\Concerns;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

trait MocksSocialite
{
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

        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    /**
     * Simulate the IdP handshake blowing up (bad state, network error, ...).
     */
    protected function fakeIdpFailure(string $message = 'invalid state'): void
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException($message));

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }
}
