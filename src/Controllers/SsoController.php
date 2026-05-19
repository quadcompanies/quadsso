<?php

namespace QuadCompanies\QuadSSO\Controllers;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SsoController extends Controller
{
    /**
     * Redirect the user to the authentik OAuth page.
     */
    public function redirect(): RedirectResponse
    {
        if (config('quadsso.logging.sso_events', false)) {
            Log::debug('QuadSSO: Redirecting to Authentik for SSO');
        }

        return Socialite::driver('authentik')->redirect();
    }

    /**
     * Handle the callback from authentik.
     *
     * Identity is resolved by the OIDC `sub` claim (stored in scim_external_id).
     * Email is NOT a primary identifier — using it as one would let a user with
     * self-service email-change access pre-claim someone else's identity and be
     * matched to that row on the victim's next SSO login.
     *
     * The only fallback to email matching is the legacy bootstrap path
     * (`sso.allow_legacy_email_binding`), which requires email_verified=true
     * from the IdP AND that the local row has no scim_external_id yet.
     */
    public function callback(): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('authentik')->user();
        } catch (\Exception $e) {
            Log::error('QuadSSO: Authentik callback failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->redirectAfterFailure(
                'Authentication failed. Please try again.'
            );
        }

        $externalId = $socialUser->getId();
        $email = $socialUser->getEmail();
        $idpEmailVerified = (bool) data_get($socialUser->user, 'email_verified', false);

        if (!$externalId) {
            Log::warning('QuadSSO: IdP did not return a sub claim — refusing login', [
                'email' => $email,
            ]);
            return $this->redirectAfterFailure(
                'Authentication failed. Please contact an administrator.'
            );
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $emailField = config('quadsso.field_mappings.email', 'email');
        $externalIdField = config('quadsso.field_mappings.external_id', 'scim_external_id');
        $statusField = config('quadsso.scim.user_status_field', 'status');
        $blockedValue = config('quadsso.scim.blocked_status_value', 'blocked');

        // 1. Authoritative lookup: stable IdP subject identifier.
        $user = $userModel::where($externalIdField, $externalId)->first();

        // 2. Legacy bootstrap (opt-in, single-use): bind the sub onto a row that
        //    matches by email and has no external_id yet. Requires email_verified.
        if (!$user && config('quadsso.sso.allow_legacy_email_binding', false) && $email) {
            if (!$idpEmailVerified) {
                Log::warning('QuadSSO: refusing legacy email binding (email_verified=false)', [
                    'email'       => $email,
                    'external_id' => $externalId,
                ]);
                return $this->redirectAfterFailure(
                    'Your email address has not been verified by the identity provider.'
                );
            }

            $user = $userModel::where($emailField, $email)
                ->whereNull($externalIdField)
                ->first();

            if ($user) {
                $user->{$externalIdField} = $externalId;
                $user->save();

                if (config('quadsso.logging.sso_events', false)) {
                    Log::info('QuadSSO: bound external_id onto legacy user', [
                        'user_id'     => $user->id,
                        'email'       => $email,
                        'external_id' => $externalId,
                    ]);
                }
            }
        }

        if (!$user) {
            if (config('quadsso.logging.sso_events', false)) {
                Log::warning('QuadSSO: No user found for SSO callback', [
                    'email'       => $email,
                    'external_id' => $externalId,
                ]);
            }

            return $this->redirectAfterFailure(
                'No account found for this identity. Please contact an administrator.'
            );
        }

        // Block check
        if ($user->$statusField === $blockedValue || (method_exists($user, 'isBlocked') && $user->isBlocked())) {
            if (config('quadsso.logging.sso_events', false)) {
                Log::warning('QuadSSO: Blocked user attempted SSO login', [
                    'user_id'     => $user->id,
                    'external_id' => $externalId,
                ]);
            }

            return $this->redirectAfterFailure(
                'Your account has been suspended. Please contact an administrator.'
            );
        }

        // Mark email as verified on first SSO login. Only do this when the IdP
        // actually claims the email is verified — otherwise we'd be laundering
        // an unverified email through SSO.
        if (config('quadsso.sso.auto_verify_email', true) && $idpEmailVerified) {
            $verifiedAtField = config('quadsso.field_mappings.email_verified_at', 'email_verified_at');
            if (!$user->$verifiedAtField) {
                $user->$verifiedAtField = now();
                $user->save();
            }
        }

        Auth::login($user, remember: true);

        if (config('quadsso.logging.sso_events', false)) {
            Log::info('QuadSSO: User logged in via SSO', [
                'user_id'     => $user->id,
                'external_id' => $externalId,
            ]);
        }

        $redirectTo = config('quadsso.sso.redirect_after_login', '/home');
        return redirect($redirectTo);
    }

    /**
     * Send the user back to the configured failure URL.
     *
     * `redirect_after_failure` is a URL path (e.g. '/login'), not a route name.
     * Using redirect()->route() against a path throws RouteNotFoundException,
     * which Laravel renders as HTTP 500 — masking the actual auth failure with
     * a server-error page. redirect() takes a path directly.
     */
    private function redirectAfterFailure(string $message): RedirectResponse
    {
        return redirect(config('quadsso.sso.redirect_after_failure', '/login'))
            ->withErrors(['email' => $message]);
    }

    /**
     * Handle OIDC Back-Channel Single Logout (SLO) from authentik.
     *
     * authentik POSTs application/x-www-form-urlencoded with a signed
     * logout_token JWT. We verify signature, issuer, audience, replay, and
     * the back-channel-logout event claim, then wipe sessions for the user
     * identified by `sub` (= scim_external_id).
     */
    public function slo(Request $request): Response
    {
        if (!config('quadsso.sso.enable_slo', true)) {
            Log::warning('QuadSSO SLO: Single Logout is disabled');
            return response('Single Logout is disabled', 403);
        }

        if (config('quadsso.logging.slo_events', true)) {
            Log::debug('QuadSSO SLO: incoming request', [
                'ip'        => $request->ip(),
                'has_token' => $request->has('logout_token'),
            ]);
        }

        $logoutToken = $request->input('logout_token');

        if (!$logoutToken) {
            Log::warning('QuadSSO SLO: missing logout_token');
            return response('Missing logout_token', 400);
        }

        try {
            $keySet = $this->fetchJwks();
            $payload = JWT::decode($logoutToken, $keySet);
        } catch (\Exception $e) {
            Log::warning('QuadSSO SLO: token verification failed', ['error' => $e->getMessage()]);
            return response('Invalid token', 400);
        }

        // Issuer must match the configured Authentik base URL. Authentik issues
        // `https://<base>/application/o/<slug>/`, so a prefix match is correct.
        $expectedIssuerPrefix = rtrim((string) config('quadsso.authentik.base_url'), '/');
        $iss = (string) ($payload->iss ?? '');
        if ($expectedIssuerPrefix === '' || !str_starts_with($iss, $expectedIssuerPrefix)) {
            Log::warning('QuadSSO SLO: bad issuer', ['iss' => $iss]);
            return response('Invalid token', 400);
        }

        // Audience must include our client_id. Tokens minted for other OIDC
        // clients in the same Authentik instance will fail this check.
        $expectedAudience = (string) config('quadsso.authentik.client_id');
        $aud = $payload->aud ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if ($expectedAudience === '' || !in_array($expectedAudience, $audList, true)) {
            Log::warning('QuadSSO SLO: bad audience', ['aud' => $aud]);
            return response('Invalid token', 400);
        }

        // The back-channel-logout event claim must be present per OIDC spec.
        $events = (array) ($payload->events ?? []);
        if (!array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)) {
            Log::warning('QuadSSO SLO: logout_token missing backchannel-logout event claim');
            return response('Invalid token', 400);
        }

        // Replay protection: cache the jti for the token's natural lifetime so
        // an intercepted/leaked logout_token can't be replayed indefinitely.
        $jti = $payload->jti ?? null;
        if (!$jti) {
            Log::warning('QuadSSO SLO: logout_token missing jti claim');
            return response('Invalid token', 400);
        }
        $jtiCacheKey = 'quadsso_slo_jti:' . hash('sha256', (string) $jti);
        if (!Cache::add($jtiCacheKey, 1, now()->addMinutes(15))) {
            Log::warning('QuadSSO SLO: replayed jti', ['jti' => $jti]);
            return response('Invalid token', 400);
        }

        $externalId = $payload->sub ?? null;

        if (!$externalId) {
            Log::warning('QuadSSO SLO: logout_token missing sub claim');
            return response('Invalid token', 400);
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $externalIdField = config('quadsso.field_mappings.external_id', 'scim_external_id');

        $user = $userModel::where($externalIdField, $externalId)->first();

        if ($user) {
            $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

            // Cycle the remember token so any "remember me" cookies are invalidated
            if (config('quadsso.sso.invalidate_remember_tokens_on_slo', true)) {
                $user->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
            }

            Log::info('QuadSSO SLO: sessions invalidated', [
                'user_id'     => $user->id,
                'external_id' => $externalId,
                'sessions'    => $deleted,
            ]);
        } else {
            // User not found — could be a user that was never synced; log and accept.
            Log::info('QuadSSO SLO: no local user found for external_id', ['external_id' => $externalId]);
        }

        return response('', 200);
    }

    /**
     * Fetch and cache the JWKS key set from authentik.
     */
    private function fetchJwks(): array
    {
        $jwksUri = config('quadsso.authentik.jwks_uri');

        if (!$jwksUri) {
            throw new \RuntimeException('AUTHENTIK_JWKS_URI is not configured.');
        }

        $keysArray = Cache::remember('quadsso_authentik_jwks', 3600, function () use ($jwksUri) {
            $response = Http::timeout(5)->get($jwksUri);

            if (!$response->successful()) {
                throw new \RuntimeException('Failed to fetch JWKS from authentik: ' . $response->status());
            }

            return $response->json();
        });

        return JWK::parseKeySet($keysArray);
    }
}
