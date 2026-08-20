<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use QuadCompanies\QuadSSO\Tests\Concerns\ForgesLogoutTokens;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The SLO endpoint is unauthenticated by design — no session, no CSRF token —
 * so the logout token itself is the entire access control. Anything that gets
 * a forged token accepted is a remote "log this user out" primitive at best,
 * and a signal that key handling is broken at worst.
 */
class SloTokenTest extends TestCase
{
    use ForgesLogoutTokens;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSigningKeys();

        $this->user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-genuine', 'status' => 'active',
        ]);

        // remember_token is deliberately not mass-assignable.
        $this->user->setRememberToken('original-remember-token');
        $this->user->save();

        $this->seedSession($this->user->id);
    }

    private function slo(string $token)
    {
        return $this->post('/auth/sso/logout', ['logout_token' => $token]);
    }

    // ---------------------------------------------------------------------
    // The genuine article
    // ---------------------------------------------------------------------

    public function test_valid_token_invalidates_sessions_and_cycles_remember_token(): void
    {
        $this->assertSame(1, $this->sessionCountFor($this->user->id));

        $this->slo($this->genuineLogoutToken())->assertOk();

        $this->assertSame(0, $this->sessionCountFor($this->user->id));
        $this->assertNotSame(
            'original-remember-token',
            $this->user->fresh()->remember_token,
            'remember token must be cycled so existing cookies stop working'
        );
    }

    public function test_remember_token_is_left_alone_when_the_option_is_off(): void
    {
        config(['quadsso.sso.invalidate_remember_tokens_on_slo' => false]);

        $this->slo($this->genuineLogoutToken())->assertOk();

        $this->assertSame('original-remember-token', $this->user->fresh()->remember_token);
    }

    // ---------------------------------------------------------------------
    // Key injection
    // ---------------------------------------------------------------------

    /**
     * A token signed with a well-formed RSA key the IdP never published, reusing
     * the genuine `kid`. Accepting this would mean signatures aren't checked
     * against the published key set at all.
     */
    public function test_rejects_token_signed_with_an_unpublished_key(): void
    {
        $this->slo($this->tokenSignedByAttackerKey())->assertStatus(400);

        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    /** `alg: none` — the classic signature-stripping forgery. */
    public function test_rejects_unsigned_token(): void
    {
        $this->slo($this->unsignedToken())->assertStatus(400);

        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    /**
     * RS256 -> HS256 confusion: the attacker signs with HMAC using the IdP's
     * published public key as the shared secret. A verifier that trusts the
     * header's `alg` over the key's own type accepts it.
     */
    public function test_rejects_algorithm_confusion_token(): void
    {
        $this->slo($this->algConfusionToken())->assertStatus(400);

        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_structurally_invalid_token(): void
    {
        $this->slo('not-a-jwt')->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_missing_token(): void
    {
        $this->post('/auth/sso/logout', [])->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_expired_token(): void
    {
        $token = $this->genuineLogoutToken(['exp' => time() - 60, 'iat' => time() - 600]);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    // ---------------------------------------------------------------------
    // Claim injection
    // ---------------------------------------------------------------------

    /**
     * A token minted by a different, possibly attacker-controlled issuer that
     * happens to be reachable. Only our configured Authentik may log our users out.
     */
    public function test_rejects_token_from_a_foreign_issuer(): void
    {
        $token = $this->genuineLogoutToken(['iss' => 'https://evil.example.test/application/o/app/']);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    /**
     * A genuine token issued by the same Authentik instance but for a different
     * OIDC client. Real signature, wrong audience — must not be usable here.
     */
    public function test_rejects_token_minted_for_another_client(): void
    {
        $token = $this->genuineLogoutToken(['aud' => 'some-other-client']);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_accepts_audience_array_containing_our_client_id(): void
    {
        $token = $this->genuineLogoutToken(['aud' => ['another-client', 'test-client-id']]);

        $this->slo($token)->assertOk();
        $this->assertSame(0, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_token_without_the_backchannel_logout_event_claim(): void
    {
        $token = $this->genuineLogoutToken(['events' => []]);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_token_without_a_subject(): void
    {
        $claims = $this->logoutTokenClaims();
        unset($claims['sub']);
        $token = \Firebase\JWT\JWT::encode($claims, $this->genuinePrivateKey, 'RS256', self::KID);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_rejects_token_without_a_jti(): void
    {
        $claims = $this->logoutTokenClaims();
        unset($claims['jti']);
        $token = \Firebase\JWT\JWT::encode($claims, $this->genuinePrivateKey, 'RS256', self::KID);

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    /**
     * Replay: a captured token must not work twice, or an observer can force a
     * logout whenever they like.
     */
    public function test_rejects_a_replayed_token(): void
    {
        $token = $this->genuineLogoutToken();

        $this->slo($token)->assertOk();

        $this->seedSession($this->user->id, 'sess-2');

        $this->slo($token)->assertStatus(400);
        $this->assertSame(1, $this->sessionCountFor($this->user->id), 'replay must not log the user out again');
    }

    // ---------------------------------------------------------------------
    // Endpoint behaviour
    // ---------------------------------------------------------------------

    public function test_returns_403_when_slo_is_disabled(): void
    {
        config(['quadsso.sso.enable_slo' => false]);

        $this->slo($this->genuineLogoutToken())->assertStatus(403);
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    /**
     * An unknown subject is accepted with 200 (nothing to do) rather than
     * confirming or denying whether that identity exists locally.
     */
    public function test_unknown_subject_is_accepted_without_disclosing_anything(): void
    {
        $token = $this->genuineLogoutToken(['sub' => 'sub-nobody']);

        $this->slo($token)->assertOk();
        $this->assertSame(1, $this->sessionCountFor($this->user->id));
    }

    public function test_only_the_named_subjects_sessions_are_deleted(): void
    {
        $other = User::create([
            'name' => 'Other', 'email' => 'other@example.test',
            'scim_external_id' => 'sub-other', 'status' => 'active',
        ]);
        $this->seedSession($other->id, 'sess-other');

        $this->slo($this->genuineLogoutToken())->assertOk();

        $this->assertSame(0, $this->sessionCountFor($this->user->id));
        $this->assertSame(1, $this->sessionCountFor($other->id), 'unrelated sessions must survive');
    }

    /**
     * The `sub` goes straight into a where clause. A tautology there must match
     * nobody rather than logging out every user at once.
     */
    public function test_sql_metacharacters_in_the_subject_do_not_match_any_user(): void
    {
        $other = User::create([
            'name' => 'Other', 'email' => 'other@example.test',
            'scim_external_id' => 'sub-other', 'status' => 'active',
        ]);
        $this->seedSession($other->id, 'sess-other');

        $this->slo($this->genuineLogoutToken(['sub' => "' OR '1'='1"]))->assertOk();

        $this->assertSame(1, $this->sessionCountFor($this->user->id));
        $this->assertSame(1, $this->sessionCountFor($other->id));
    }

    public function test_jwks_is_cached_rather_than_refetched_per_request(): void
    {
        Cache::forget('quadsso_authentik_jwks');

        $this->slo($this->genuineLogoutToken())->assertOk();
        $this->slo($this->genuineLogoutToken())->assertOk();

        Http::assertSentCount(1);
    }
}
