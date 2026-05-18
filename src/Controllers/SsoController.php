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
     * Finds the pre-provisioned user (via SCIM) and logs them in.
     * Never creates users here — that is SCIM's job.
     */
    public function callback(): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('authentik')->user();
        } catch (\Exception $e) {
            Log::error('QuadSSO: Authentik callback failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route(config('quadsso.sso.redirect_after_failure', '/login'))
                ->withErrors(['email' => 'Authentication failed. Please try again.']);
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $emailField = config('quadsso.field_mappings.email', 'email');
        $statusField = config('quadsso.scim.user_status_field', 'status');
        $blockedValue = config('quadsso.scim.blocked_status_value', 'blocked');

        // Find user by email (primary key) — SCIM should have provisioned them
        $user = $userModel::where($emailField, $socialUser->getEmail())->first();

        if (!$user) {
            if (config('quadsso.logging.sso_events', false)) {
                Log::warning('QuadSSO: No user found for SSO callback', [
                    'email' => $socialUser->getEmail(),
                ]);
            }

            return redirect()
                ->route(config('quadsso.sso.redirect_after_failure', '/login'))
                ->withErrors([
                    'email' => 'No account found for this identity. Please contact an administrator.',
                ]);
        }

        // Check if user is blocked
        if ($user->$statusField === $blockedValue || (method_exists($user, 'isBlocked') && $user->isBlocked())) {
            if (config('quadsso.logging.sso_events', false)) {
                Log::warning('QuadSSO: Blocked user attempted SSO login', [
                    'user_id' => $user->id,
                    'email' => $socialUser->getEmail(),
                ]);
            }

            return redirect()
                ->route(config('quadsso.sso.redirect_after_failure', '/login'))
                ->withErrors([
                    'email' => 'Your account has been suspended. Please contact an administrator.',
                ]);
        }

        // Mark email as verified on first SSO login (authentik handles identity verification)
        if (config('quadsso.sso.auto_verify_email', true)) {
            $verifiedAtField = config('quadsso.field_mappings.email_verified_at', 'email_verified_at');
            if (!$user->$verifiedAtField) {
                $user->$verifiedAtField = now();
                $user->save();
            }
        }

        Auth::login($user, remember: true);

        if (config('quadsso.logging.sso_events', false)) {
            Log::info('QuadSSO: User logged in via SSO', [
                'user_id' => $user->id,
                'email' => $socialUser->getEmail(),
            ]);
        }

        $redirectTo = config('quadsso.sso.redirect_after_login', '/home');
        return redirect($redirectTo);
    }

    /**
     * Handle OIDC Back-Channel Single Logout (SLO) from authentik.
     *
     * authentik POSTs application/x-www-form-urlencoded with a signed
     * logout_token JWT. We verify the token, find the user by the `sub`
     * claim (= scim_external_id), and wipe their sessions.
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

        // Ensure this is actually a back-channel logout event
        $events = (array) ($payload->events ?? []);
        if (!array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)) {
            Log::warning('QuadSSO SLO: logout_token missing backchannel-logout event claim');
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
