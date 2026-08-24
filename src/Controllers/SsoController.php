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
use Laravel\Socialite\Two\InvalidStateException;
use QuadCompanies\QuadSSO\Middleware\EnforceSessionRevocation;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;
use QuadCompanies\QuadSSO\Support\SessionRevoker;

class SsoController extends Controller
{
    /**
     * Redirect the user to the authentik OAuth page.
     *
     * Socialite generates the `state` nonce and writes it to the session inside
     * redirect(); this method only records what it did. The session id and the
     * state fingerprint are the two values needed to pair this leg with the
     * callback leg in the log, which is otherwise guesswork across two requests
     * that share no identifier.
     */
    public function redirect(): RedirectResponse
    {
        $request = request();
        $hasSession = $request->hasSession();

        if (!$hasSession) {
            QuadSsoLog::error(
                'login cannot start: this request has no session, so Socialite has nowhere to '
                . 'store the OAuth state and the callback can never match it. The auth/sso route '
                . 'must run inside the web middleware group.'
            );
        }

        // Read before Socialite overwrites it. A state already sitting here means
        // a login was started and never finished — a double-click, a prefetch, or
        // a reload — and the value about to replace it is what the in-flight
        // authorization request will come back with.
        $priorState = $hasSession ? $request->session()->get('state') : null;

        $response = Socialite::driver('authentik')->redirect();

        $context = [
            'session_present' => $hasSession,
            'session_id'      => $hasSession ? $request->session()->getId() : null,
            'state_stored_fp' => $this->fingerprint($hasSession ? $request->session()->get('state') : null),
            'session_driver'  => config('session.driver'),
            'previous_url'    => $hasSession ? $request->session()->previousUrl() : null,
            'host'            => $request->getHost(),
        ];

        if ($priorState !== null) {
            $context['replaced_state_fp'] = $this->fingerprint($priorState);
        }

        $context += $this->authorizeTargetContext($response);

        QuadSsoLog::trace(QuadSsoLog::SSO, 'login started, redirecting to the identity provider', $context);

        if ($hasSession) {
            $this->verifySessionWrite($request->session()->getId(), $context['state_stored_fp']);
        }

        return $response;
    }

    /**
     * Confirm, after the request has ended, that the state actually reached the
     * session store.
     *
     * Everything up to this point only proves the state was in the session
     * *object*. Laravel commits that object in StartSession::terminate(), after
     * the response has been sent, so a store that silently refuses writes — a
     * table that vanished, a read-only or full disk, a driver pointed somewhere
     * that no longer exists — produces a redirect leg that looks perfect and a
     * callback with nothing to match against.
     *
     * Registered as a terminating callback, which the framework runs after the
     * session middleware has saved. Its absence from the log is itself the
     * finding: it means terminate() never ran, and nothing was ever going to be
     * written.
     */
    private function verifySessionWrite(string $sessionId, ?string $expectedFp): void
    {
        app()->terminating(function () use ($sessionId, $expectedFp) {
            if (!QuadSsoLog::enabled(QuadSsoLog::SSO)) {
                return;
            }

            try {
                $raw = app('session')->driver()->getHandler()->read($sessionId);
            } catch (\Throwable $e) {
                QuadSsoLog::error('could not read the session back after the redirect leg', [
                    'session_id' => $sessionId,
                    'error'      => $e->getMessage(),
                ]);

                return;
            }

            $bytes = is_string($raw) ? strlen($raw) : 0;
            $attributes = $bytes > 0 ? @unserialize($raw) : null;

            $context = [
                'session_id'      => $sessionId,
                'expected_fp'     => $expectedFp,
                'payload_bytes'   => $bytes,
                // False on an encrypted store, where the handler hands back
                // ciphertext. Distinguishes "unreadable here" from "not written".
                'payload_readable' => is_array($attributes),
            ];

            if (is_array($attributes)) {
                $persisted = $attributes['state'] ?? null;

                $context['state_persisted']    = $persisted !== null;
                $context['state_persisted_fp'] = $this->fingerprint(is_string($persisted) ? $persisted : null);
                $context['persisted_keys']     = array_keys($attributes);
            }

            QuadSsoLog::trace(QuadSsoLog::SSO, 'session store checked after the redirect leg', $context);
        });
    }

    /**
     * The parts of the authorization URL worth recording.
     *
     * `redirect_uri` is the value authentik will compare against its own
     * registration, and a mismatch there is indistinguishable from every other
     * handshake failure once the browser has bounced. The full URL is not logged
     * because it carries the state nonce.
     */
    private function authorizeTargetContext(RedirectResponse $response): array
    {
        $target = $response->getTargetUrl();

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        return [
            'authorize_host' => parse_url($target, PHP_URL_HOST),
            'redirect_uri'   => $query['redirect_uri'] ?? null,
        ];
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
        // Taken before Socialite runs, because Socialite::user() calls
        // session()->pull('state') and destroys the evidence on its way to
        // throwing. A catch block is too late to ask whether state was there.
        $handshake = $this->handshakeSnapshot(request());

        QuadSsoLog::trace(QuadSsoLog::SSO, 'verifying the OAuth state returned by the identity provider', $handshake);

        try {
            $socialUser = Socialite::driver('authentik')->user();
        } catch (\Exception $e) {
            $this->logHandshakeFailure($e, request(), $handshake);

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

        // Record when this session was established so revocation can tell it
        // apart from one issued after the account was reinstated. Set after
        // Auth::login(), which regenerates the session id.
        request()->session()->put(EnforceSessionRevocation::SESSION_KEY, now()->getTimestamp());

        QuadSsoLog::trace(QuadSsoLog::SSO, 'login authorised, session established', [
            'user_id'     => $user->id,
            'external_id' => $externalId,
            // Auth::login() regenerates the id, so this is deliberately not the
            // one the callback arrived on.
            'session_id'  => request()->session()->getId(),
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
     * Explain why the handshake failed, in terms an operator can act on.
     *
     * Socialite throws InvalidStateException with no message at all, so the
     * previous log line read `{"error":""}` — technically accurate and
     * completely useless. Since that is also the single most common failure,
     * it gets a specific explanation and the context needed to tell its causes
     * apart, rather than leaving someone to reason about it from a blank string.
     */
    private function logHandshakeFailure(\Throwable $e, Request $request, array $snapshot = []): void
    {
        $context = array_merge(
            [
                'exception'      => get_class($e),
                'error'          => $e->getMessage(),
                'host'           => $request->getHost(),
                'state_returned' => $request->filled('state'),
                'code_returned'  => $request->filled('code'),
            ],
            // Captured before Socialite consumed the state; overrides the
            // defaults above with the same keys carrying the same values.
            $snapshot
        );

        // Authentik reports a refusal as query parameters rather than an
        // exception, so surface those instead of losing them.
        if ($request->filled('error')) {
            $context['idp_error'] = $request->query('error');
            $context['idp_error_description'] = $request->query('error_description');
        }

        if (!$e instanceof InvalidStateException) {
            QuadSsoLog::error('identity provider handshake failed', $context);

            return;
        }

        QuadSsoLog::error(
            'login failed: the OAuth state did not survive the round trip, so the state '
            . 'parameter could not be matched. Read state_in_session together with '
            . 'state_matches. state_in_session=false with session_empty=true: the session '
            . 'cookie never came back, so this request minted a new session — the two legs ran '
            . 'on different hosts (www versus apex), or a cookie-driver session grew past the '
            . 'browser 4KB limit, or SESSION_SAME_SITE is strict, or a proxy the application '
            . 'does not trust made it build URLs for the wrong scheme or host, or the two legs '
            . 'were served by instances with different APP_KEYs so the cookie would not '
            . 'decrypt. state_in_session=false with session_empty=false: the session did come '
            . 'back, but the state had already been consumed — a reloaded, bookmarked or '
            . 'replayed callback URL, which can never succeed twice. state_in_session=true with '
            . 'state_matches=false: a second login was started before this one returned, so the '
            . 'stored state belongs to the newer attempt; look for two "login started" lines and '
            . 'compare their state_stored_fp and replaced_state_fp. Pair the two legs of any '
            . 'login by session_id.',
            $context
        );
    }

    /**
     * What the returning request actually carries, recorded before anything
     * consumes it.
     *
     * This must run ahead of Socialite::user(). Socialite reads the stored state
     * with session()->pull('state'), which removes it, so by the time a catch
     * block asks "was the state there?" the answer is always no — and a previous
     * version of this class inferred a missing cookie from exactly that, which
     * reported a session that had round-tripped perfectly as a session that never
     * came back. Everything below is an observation; nothing is inferred.
     */
    private function handshakeSnapshot(Request $request): array
    {
        $hasSession = $request->hasSession();

        if (!$hasSession) {
            return [
                'session_present'  => false,
                'session_id'       => null,
                'session_empty'    => true,
                'state_in_session' => false,
                'state_returned'   => $request->filled('state'),
                'code_returned'    => $request->filled('code'),
                'host'             => $request->getHost(),
            ];
        }

        $session = $request->session();
        $keys = array_keys($session->all());
        $stored = $session->get('state');
        $returned = $request->query('state');

        return [
            'session_present'   => true,
            'session_id'        => $session->getId(),
            // Nothing but framework bookkeeping: a session minted by this very
            // request, so the original cookie did not come back. Correct only
            // because this runs before Socialite pulls state — a session holding
            // state is never freshly minted, since only the redirect leg could
            // have put it there.
            'session_empty'     => array_diff($keys, ['_token', '_previous', '_flash']) === [],
            'session_keys'      => $keys,
            'state_in_session'  => $stored !== null,
            'state_stored_fp'   => $this->fingerprint(is_string($stored) ? $stored : null),
            'state_returned'    => $request->filled('state'),
            'state_returned_fp' => $this->fingerprint(is_string($returned) ? $returned : null),
            'state_matches'     => (is_string($stored) && is_string($returned))
                ? hash_equals($stored, $returned)
                : null,
            'code_returned'     => $request->filled('code'),
            // The last GET this session handled. If it is not the auth/sso route
            // then the redirect leg's write never reached the row, whatever the
            // redirect leg's own log line claimed to hold in memory.
            'previous_url'      => $session->previousUrl(),
            'session_driver'    => config('session.driver'),
            'host'              => $request->getHost(),
        ];
    }

    /**
     * A short, stable fingerprint of a state nonce.
     *
     * Enough to tell two states apart across log lines and across the two legs of
     * a login, without writing the nonce itself into a log that is likely to be
     * shipped somewhere less trusted than the session store it came from.
     */
    private function fingerprint(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', $value), 0, 8);
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
     *
     * Flashed to a named error bag so the message belongs to the SSO button
     * rather than appearing under the application's email field. Without
     * somewhere to render it, a failed login is indistinguishable from a page
     * refresh — which is what <x-quadsso::login-button /> exists to fix.
     */
    private function redirectAfterFailure(string $message): RedirectResponse
    {
        return redirect(config('quadsso.sso.redirect_after_failure', '/login'))
            ->withErrors(['sso' => $message], (string) config('quadsso.ui.error_bag', 'quadsso'));
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
            // Same revocation path as the management API, so back-channel
            // logout works on a stateless session driver too — previously this
            // deleted rows that never existed and reported success.
            $result = app(SessionRevoker::class)->revoke($user);

            if (!$result['effective']) {
                QuadSsoLog::warning('SLO could not terminate the session', [
                    'user_id'     => $user->getKey(),
                    'external_id' => $externalId,
                    'driver'      => $result['driver'],
                    'notes'       => $result['notes'],
                ]);
            }

            QuadSsoLog::trace(QuadSsoLog::SLO, 'SLO accepted, sessions invalidated', [
                'user_id'     => $user->getKey(),
                'external_id' => $externalId,
                'result'      => $result,
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
