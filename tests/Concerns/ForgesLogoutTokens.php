<?php

namespace QuadCompanies\QuadSSO\Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Builds real RSA-signed back-channel logout tokens, plus the forged variants
 * an attacker would actually try: a token signed with a key the IdP never
 * published, an unsigned `alg: none` token, and an HMAC token that abuses the
 * published public key as a shared secret.
 */
trait ForgesLogoutTokens
{
    protected string $genuinePrivateKey;
    protected string $genuinePublicKey;
    protected string $attackerPrivateKey;
    protected array $jwks;

    protected const KID = 'genuine-key';
    protected const BACKCHANNEL_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    protected function setUpSigningKeys(): void
    {
        [$this->genuinePrivateKey, $this->genuinePublicKey, $jwk] = $this->makeRsaKey(self::KID);
        [$this->attackerPrivateKey] = $this->makeRsaKey('attacker-key');

        // Only the genuine key is ever published, so anything signed by the
        // attacker key has no matching entry in the key set.
        $this->jwks = ['keys' => [$jwk]];

        Cache::forget('quadsso_authentik_jwks');
        Http::fake([
            'https://idp.example.test/jwks' => Http::response($this->jwks, 200),
        ]);
    }

    /**
     * @return array{0:string,1:string,2:array} private PEM, public PEM, JWK
     */
    private function makeRsaKey(string $kid): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return [
            $privateKey,
            $details['key'],
            [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n'   => $this->base64Url($details['rsa']['n']),
                'e'   => $this->base64Url($details['rsa']['e']),
            ],
        ];
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    protected function logoutTokenClaims(array $overrides = []): array
    {
        return array_merge([
            'iss'    => 'https://idp.example.test/application/o/test-app/',
            'aud'    => 'test-client-id',
            'sub'    => 'sub-genuine',
            'iat'    => time(),
            'exp'    => time() + 300,
            'jti'    => 'jti-' . bin2hex(random_bytes(8)),
            'events' => [self::BACKCHANNEL_EVENT => new \stdClass()],
        ], $overrides);
    }

    /** A token the IdP would genuinely have issued. */
    protected function genuineLogoutToken(array $overrides = []): string
    {
        return JWT::encode($this->logoutTokenClaims($overrides), $this->genuinePrivateKey, 'RS256', self::KID);
    }

    /** Correctly formed, but signed with a key that was never published. */
    protected function tokenSignedByAttackerKey(array $overrides = []): string
    {
        return JWT::encode($this->logoutTokenClaims($overrides), $this->attackerPrivateKey, 'RS256', self::KID);
    }

    /** `alg: none` — signature stripped entirely. */
    protected function unsignedToken(array $overrides = []): string
    {
        $header = $this->base64Url(json_encode(['typ' => 'JWT', 'alg' => 'none', 'kid' => self::KID]));
        $payload = $this->base64Url(json_encode($this->logoutTokenClaims($overrides)));

        return "$header.$payload.";
    }

    /**
     * RS256 -> HS256 confusion: sign with HMAC using the IdP's *public* key as
     * the shared secret. Succeeds against verifiers that trust the header's alg.
     */
    protected function algConfusionToken(array $overrides = []): string
    {
        return JWT::encode($this->logoutTokenClaims($overrides), $this->genuinePublicKey, 'HS256', self::KID);
    }
}
