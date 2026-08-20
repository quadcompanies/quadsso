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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

class SsoController extends Controller
{
    /**
     * Redirect the user to the authentik OAuth page.
     */
    public function redirect(): RedirectResponse
    {
        QuadSsoLog::trace(QuadSsoLog::SSO, 'login started, redirecting to the identity provider');

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
     * User resolution flow:
     * 1. Lookup by external_id (authoritative)
     * 2. Legacy email binding (opt-in, requires email_verified=true)
     * 3. JIT provisioning (opt-in, creates user if not found)
     */
    public function callback(): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('authentik')->user();
        } catch (\Exception $e) {
            QuadSsoLog::error('identity provider handshake failed', [
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
            QuadSsoLog::warning('login refused: the identity provider returned no sub claim', [
                'email' => $email,
            ]);
            return $this->redirectAfterFailure(
                'Authentication failed. Please contact an administrator.'
            );
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $emailField = config('quadsso.field_mappings.email', 'email');
        $externalIdField = config('quadsso.field_mappings.external_id', 'scim_external_id');
        $statusField = config('quadsso.provisioning.user_status_field', 'status');
        $blockedValue = config('quadsso.provisioning.blocked_status_value', 'blocked');

        QuadSsoLog::trace(QuadSsoLog::SSO, 'callback received from the identity provider', [
            'external_id'    => $externalId,
            'email'          => $email,
            'email_verified' => $idpEmailVerified,
        ]);

        // 1. Authoritative lookup: stable IdP subject identifier.
        $user = $userModel::where($externalIdField, $externalId)->first();

        if ($user) {
            QuadSsoLog::trace(QuadSsoLog::SSO, 'identity resolved by external_id', [
                'user_id'     => $user->id,
                'external_id' => $externalId,
            ]);
        }

        // 2. Legacy bootstrap (opt-in, single-use): bind the sub onto a row that
        //    matches by email and has no external_id yet. Requires email_verified.
        if (!$user && config('quadsso.sso.allow_legacy_email_binding', false) && $email) {
            if (!$idpEmailVerified) {
                QuadSsoLog::warning('login refused: legacy email binding requires a verified email', [
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

                QuadSsoLog::trace(QuadSsoLog::SSO, 'identity resolved by legacy email binding, external_id now bound', [
                    'user_id'     => $user->id,
                    'email'       => $email,
                    'external_id' => $externalId,
                ]);
            }
        }

        // 3. Just-In-Time (JIT) provisioning: create user on first login if enabled
        if (!$user && config('quadsso.sso.enable_jit_provisioning', false)) {
            if (!$email) {
                QuadSsoLog::warning('JIT provisioning refused: the identity provider returned no email', [
                    'external_id' => $externalId,
                ]);
                return $this->redirectAfterFailure(
                    'Authentication failed. Please contact an administrator.'
                );
            }

            // Require email_verified from IdP for JIT provisioning (same security posture as legacy binding)
            if (!$idpEmailVerified) {
                QuadSsoLog::warning('JIT provisioning refused: email is not verified by the identity provider', [
                    'email'       => $email,
                    'external_id' => $externalId,
                ]);
                return $this->redirectAfterFailure(
                    'Your email address has not been verified by the identity provider.'
                );
            }

            // Check if a user with this email already exists (with a different external_id)
            $existingUser = $userModel::where($emailField, $email)->first();
            if ($existingUser && $existingUser->{$externalIdField}) {
                QuadSsoLog::warning('JIT provisioning refused: email already belongs to another identity', [
                    'email'                => $email,
                    'incoming_external_id' => $externalId,
                    'existing_external_id' => $existingUser->{$externalIdField},
                ]);
                return $this->redirectAfterFailure(
                    'An account with this email already exists. Please contact an administrator.'
                );
            }

            // If user exists but has no external_id, bind it (same as legacy email binding)
            if ($existingUser && !$existingUser->{$externalIdField}) {
                $existingUser->{$externalIdField} = $externalId;
                $existingUser->save();

                $user = $existingUser;

                QuadSsoLog::trace(QuadSsoLog::SSO, 'JIT bound external_id to an existing unbound user', [
                    'user_id'     => $user->id,
                    'email'       => $email,
                    'external_id' => $externalId,
                ]);
            } else {
                // Create new user with JIT provisioning
                $verifiedAtField = config('quadsso.field_mappings.email_verified_at', 'email_verified_at');
                $levelField = config('quadsso.provisioning.user_level_field', 'level');

                $userData = [
                    $emailField       => $email,
                    $externalIdField  => $externalId,
                    $statusField      => config('quadsso.provisioning.default_user_status', 'active'),
                    $verifiedAtField  => now(), // Email is verified by IdP
                ];

                // Add name if the IdP supplied one
                $userData = array_merge($userData, $this->nameAttributesFor($socialUser->getName()));

                // Add default user level if the field exists
                if (Schema::hasColumn((new $userModel)->getTable(), $levelField)) {
                    $userData[$levelField] = config('quadsso.provisioning.default_user_level', 'user');
                }

                try {
                    $user = $userModel::create($userData);

                    QuadSsoLog::trace(QuadSsoLog::SSO, 'JIT provisioned a new user', [
                        'user_id'     => $user->id,
                        'email'       => $email,
                        'external_id' => $externalId,
                    ]);
                } catch (\Exception $e) {
                    QuadSsoLog::error('JIT provisioning failed while creating the user', [
                        'email'       => $email,
                        'external_id' => $externalId,
                        'error'       => $e->getMessage(),
                    ]);
                    return $this->redirectAfterFailure(
                        'Failed to create your account. Please contact an administrator.'
                    );
                }
            }
        }

        if (!$user) {
            QuadSsoLog::warning('login refused: no local account matches this identity', [
                'email'       => $email,
                'external_id' => $externalId,
            ]);

            return $this->redirectAfterFailure(
                'No account found for this identity. Please contact an administrator.'
            );
        }

        // Fail closed if the status column can't be resolved at all. `$user->$statusField`
        // is attribute access, not a query: a column name that doesn't exist reads back
        // as null, null never equals the blocked value, and the block check below would
        // quietly pass — admitting suspended accounts with no error anywhere.
        //
        // Skipped when the application has opted out of status-based blocking
        // (empty blocked_status_value) or brings its own isBlocked() gate.
        $statusBlockingExpected = $blockedValue !== null && $blockedValue !== '';
        $modelHasOwnBlockCheck = method_exists($user, 'isBlocked');

        if ($statusBlockingExpected
            && !$modelHasOwnBlockCheck
            && !$this->statusAttributeIsResolvable($user, $statusField)) {
            QuadSsoLog::error(
                "status column [{$statusField}] does not exist on the user model, so blocked "
                . 'accounts cannot be detected. Refusing login rather than admitting everyone. Point '
                . 'QUADSSO_USER_STATUS_FIELD at a real column, add it via migration, or set '
                . 'QUADSSO_BLOCKED_STATUS_VALUE empty to disable status-based blocking.',
                ['user_id' => $user->getKey()]
            );

            return $this->redirectAfterFailure(
                'Your account status could not be verified. Please contact an administrator.'
            );
        }

        // Block check
        if ($user->$statusField === $blockedValue || ($modelHasOwnBlockCheck && $user->isBlocked())) {
            QuadSsoLog::warning('login refused: account is blocked', [
                'user_id'     => $user->id,
                'external_id' => $externalId,
            ]);

            return $this->redirectAfterFailure(
                'Your account has been suspended. Please contact an administrator.'
            );
        }

        // Refresh profile attributes from the IdP. Without this, whatever the row
        // held at creation time is frozen forever — a rename at the IdP never
        // reaches the application.
        $this->syncAttributesFromIdp($user, $socialUser, $idpEmailVerified);

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

        Auth::login($user, remember: (bool) config('quadsso.sso.remember_login', false));

        QuadSsoLog::trace(QuadSsoLog::SSO, 'login authorised, session established', [
            'user_id'     => $user->id,
            'external_id' => $externalId,
        ]);

        $redirectTo = config('quadsso.sso.redirect_after_login', '/home');
        return redirect($redirectTo);
    }

    /**
     * Can the configured status attribute actually be read off this model?
     *
     * Checks the loaded row attributes first, then the two accessor styles, so a
     * status derived in PHP rather than stored in a column is still recognised.
     * Deliberately avoids Schema::hasColumn() — that is a database round trip on
     * every login to answer a question the loaded model already knows.
     */
    private function statusAttributeIsResolvable($user, string $statusField): bool
    {
        if (array_key_exists($statusField, $user->getAttributes())) {
            return true;
        }

        // Classic accessor: getStatusAttribute()
        if (method_exists($user, 'get' . Str::studly($statusField) . 'Attribute')) {
            return true;
        }

        // Attribute-class accessor (Laravel 9+): protected function status(): Attribute
        if (method_exists($user, Str::camel($statusField))) {
            return true;
        }

        return false;
    }

    /**
     * Build the name column(s) for a display name supplied by the IdP.
     *
     * Which columns come back depends on field_mappings: either the split
     * name_first/name_last pair, or Laravel's single `name` column. Returns an
     * empty array when the IdP sent no name or no name mapping is configured.
     */
    private function nameAttributesFor(?string $name): array
    {
        if (!$name) {
            return [];
        }

        $nameFirstField = config('quadsso.field_mappings.name_first');
        $nameLastField  = config('quadsso.field_mappings.name_last');
        $nameField      = config('quadsso.field_mappings.name');

        if ($nameFirstField && $nameLastField) {
            $parts = explode(' ', $name, 2);

            return [
                $nameFirstField => $parts[0] ?? '',
                $nameLastField  => $parts[1] ?? '',
            ];
        }

        if ($nameField) {
            return [$nameField => $name];
        }

        return [];
    }

    /**
     * Copy profile attributes from the IdP onto an already-resolved user.
     *
     * Identity is pinned to `sub` before this runs, so following an email
     * change at the IdP is safe — email is an attribute here, never a lookup
     * key. Two guards still apply: the IdP must assert the address is verified,
     * and the address must not already belong to another local row (the column
     * is typically unique, so writing a duplicate would throw).
     */
    private function syncAttributesFromIdp($user, $socialUser, bool $idpEmailVerified): void
    {
        if (!config('quadsso.sso.sync_attributes_on_login', true)) {
            return;
        }

        $changes = $this->nameAttributesFor($socialUser->getName());

        $emailField = config('quadsso.field_mappings.email', 'email');
        $email = $socialUser->getEmail();

        if ($email && $idpEmailVerified && $user->{$emailField} !== $email) {
            $userModel = config('quadsso.user_model', \App\Models\User::class);

            $taken = $userModel::where($emailField, $email)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                QuadSsoLog::warning('skipping email sync, address already used by another local user', [
                    'user_id' => $user->id,
                ]);
            } else {
                $changes[$emailField] = $email;
            }
        }

        // Only write columns that actually differ. Empty values are skipped so a
        // single-word display name can't blank out an existing surname.
        $dirty = [];
        foreach ($changes as $column => $value) {
            if ($value !== null && $value !== '' && $user->{$column} !== $value) {
                $dirty[$column] = $value;
            }
        }

        if (!$dirty) {
            return;
        }

        foreach ($dirty as $column => $value) {
            $user->{$column} = $value;
        }

        try {
            $user->save();

            QuadSsoLog::trace(QuadSsoLog::SSO, 'refreshed profile attributes from the identity provider', [
                'user_id' => $user->id,
                'columns' => array_keys($dirty),
            ]);
        } catch (\Exception $e) {
            // A stale local row is better than a failed login.
            QuadSsoLog::error('attribute sync failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
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
            QuadSsoLog::warning('SLO refused: Single Logout is disabled');
            return response('Single Logout is disabled', 403);
        }

        QuadSsoLog::trace(QuadSsoLog::SLO, 'SLO request received', [
            'ip'        => $request->ip(),
            'has_token' => $request->has('logout_token'),
        ]);

        $logoutToken = $request->input('logout_token');

        if (!$logoutToken) {
            QuadSsoLog::warning('SLO refused: no logout_token supplied');
            return response('Missing logout_token', 400);
        }

        try {
            $keySet = $this->fetchJwks();
            $payload = JWT::decode($logoutToken, $keySet);
        } catch (\Exception $e) {
            QuadSsoLog::warning('SLO refused: token signature verification failed', ['error' => $e->getMessage()]);
            return response('Invalid token', 400);
        }

        // Issuer must match the configured Authentik base URL. Authentik issues
        // `https://<base>/application/o/<slug>/`, so a prefix match is correct.
        $expectedIssuerPrefix = rtrim((string) config('quadsso.authentik.base_url'), '/');
        $iss = (string) ($payload->iss ?? '');
        if ($expectedIssuerPrefix === '' || !str_starts_with($iss, $expectedIssuerPrefix)) {
            QuadSsoLog::warning('SLO refused: token issuer does not match the configured provider', ['iss' => $iss]);
            return response('Invalid token', 400);
        }

        // Audience must include our client_id. Tokens minted for other OIDC
        // clients in the same Authentik instance will fail this check.
        $expectedAudience = (string) config('quadsso.authentik.client_id');
        $aud = $payload->aud ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if ($expectedAudience === '' || !in_array($expectedAudience, $audList, true)) {
            QuadSsoLog::warning('SLO refused: token was minted for a different client', ['aud' => $aud]);
            return response('Invalid token', 400);
        }

        // The back-channel-logout event claim must be present per OIDC spec.
        $events = (array) ($payload->events ?? []);
        if (!array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)) {
            QuadSsoLog::warning('SLO refused: token is missing the backchannel-logout event claim');
            return response('Invalid token', 400);
        }

        // Replay protection: cache the jti for the token's natural lifetime so
        // an intercepted/leaked logout_token can't be replayed indefinitely.
        $jti = $payload->jti ?? null;
        if (!$jti) {
            QuadSsoLog::warning('SLO refused: token is missing its jti claim');
            return response('Invalid token', 400);
        }
        $jtiCacheKey = 'quadsso_slo_jti:' . hash('sha256', (string) $jti);
        if (!Cache::add($jtiCacheKey, 1, now()->addMinutes(15))) {
            QuadSsoLog::warning('SLO refused: token replayed', ['jti' => $jti]);
            return response('Invalid token', 400);
        }

        $externalId = $payload->sub ?? null;

        if (!$externalId) {
            QuadSsoLog::warning('SLO refused: token is missing its sub claim');
            return response('Invalid token', 400);
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $externalIdField = config('quadsso.field_mappings.external_id', 'scim_external_id');

        QuadSsoLog::trace(QuadSsoLog::SLO, 'SLO token verified: signature, issuer, audience, event claim and jti all passed', [
            'external_id' => $externalId,
        ]);

        $user = $userModel::where($externalIdField, $externalId)->first();

        if ($user) {
            $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

            // Cycle the remember token so any "remember me" cookies are invalidated
            if (config('quadsso.sso.invalidate_remember_tokens_on_slo', true)) {
                $user->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
            }

            QuadSsoLog::trace(QuadSsoLog::SLO, 'SLO accepted, sessions invalidated', [
                'user_id'     => $user->id,
                'external_id' => $externalId,
                'sessions'    => $deleted,
            ]);
        } else {
            // User not found — could be a user that was never synced; log and accept.
            QuadSsoLog::trace(QuadSsoLog::SLO, 'SLO accepted, but no local user matches that identity', ['external_id' => $externalId]);
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
