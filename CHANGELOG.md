# Changelog

All notable changes to QuadSSO will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-08-20

### Removed — BREAKING

- **SCIM provisioning removed entirely.** `QuadSSOScimConfig`, the `ScimBearerToken`
  middleware, and the `arietimmerman/laravel-scim-server` dependency are gone —
  roughly 43% of the codebase, along with an internet-facing authenticated write
  endpoint into the users table. Provisioning is now JIT (or manual creation plus
  first-login binding). See "Upgrading from 1.x" in the README; enable
  `SSO_ENABLE_JIT_PROVISIONING=true` **before** upgrading, and unbind the SCIM
  provider in Authentik afterwards.
- The `scim` config section is now `provisioning`, and its surviving keys moved
  from `SCIM_*` to `QUADSSO_*`. A missed rename is silent — the value falls back
  to its default — so audit any customised `SCIM_USER_STATUS_FIELD` or
  `SCIM_USER_LEVEL_FIELD`. Full mapping table in the README.
- Dropped with SCIM: `SCIM_BEARER_TOKEN`, `SCIM_AUTO_PROVISION` (now
  `QUADSSO_APPLY_DEFAULTS`), `SCIM_ALLOW_USER_CREATION`, `SCIM_ALLOW_USER_UPDATES`,
  `SCIM_ALLOW_USER_DELETION`, `SCIM_INVALIDATE_SESSIONS_ON_BLOCK`,
  `SCIM_ALLOW_LEGACY_EMAIL_MERGE`, `SCIM_ACTIVE_STATUS_VALUE`, and
  `QUADSSO_LOG_SCIM_REQUESTS`.
- **Blocking is no longer driven by this package.** SCIM was the only writer of
  the blocked status value; the SSO callback still reads it, so wire your own
  admin tooling to set it if you need a local kill switch.
- `SSO_INVALIDATE_REMEMBER_TOKENS_ON_SLO`. Cycling the remember token is no
  longer optional: a remember-me cookie outlives session rows, so leaving it
  intact made "logged out everywhere" untrue.
- Dead configuration keys that nothing read: `authentik.logout_url`
  (`AUTHENTIK_LOGOUT_URL` — no front-channel logout is implemented),
  `scim.enabled` (`SCIM_ENABLED`), `scim.path` (`SCIM_BASE_PATH`), `scim.domain`
  (`SCIM_DOMAIN`), and `scim.pagination`. These were never bridged to
  `laravel-scim-server`'s own `config/scim.php`, so setting them had no effect.
- Duplicate `authentik.client_secret` and `authentik.redirect` keys. Socialite
  reads these from `config/services.php`; the env vars are still required, but
  they now have a single source of truth.

### Changed — BREAKING

- **Remember-me is now opt-in** (`SSO_REMEMBER_LOGIN`, default `false`). 1.x
  called `Auth::login($user, remember: true)` unconditionally, issuing a cookie
  that lasts five years by default and therefore outlives deprovisioning at the
  IdP. With it off, post-deprovisioning exposure is bounded by `SESSION_LIFETIME`
  instead of being effectively unbounded. Set it to `true` to restore the old
  behaviour, but read the README's revocation section first.

### Added

- **Driver-agnostic session revocation.** Deleting session rows only ends a
  session when the driver keeps rows; on the `cookie` driver — Laravel Cloud's
  default — the session lives entirely in the client's cookie and the server
  holds no record of it, so the previous approach achieved nothing while
  appearing to succeed.

  A `quadsso_sessions_valid_after` timestamp on the user now marks the moment
  every existing session became invalid, and `EnforceSessionRevocation`
  middleware rejects any session established before it. This costs no extra
  queries: the session guard already loads the user row on every authenticated
  request. Applied by `SUSPEND`, `DELETE` and back-channel `SLO` alike, via a
  shared `SessionRevoker`.

  Null until the first revocation, so installing it logs nobody out. Disable
  with `QUADSSO_SESSION_REVOCATION=false`.

- **`php artisan quadsso:doctor`**, a diagnostic for the failure modes that are
  otherwise silent: a session driver keeping no server-side record, a status
  column named in config that does not exist, a revocation column never
  migrated, a sessions table on the wrong connection, an SLO route that has
  drifted inside the `web` group. `--json` for machine-readable output, non-zero
  exit on failure.

- **`QUADSSO_CYCLE_PASSWORD_ON_REVOKE`** (default off), a second revocation path
  for applications running Laravel's `auth.session` middleware, which logs out
  any session whose stored password hash no longer matches.

- **`UNSUSPEND` action** on the management API, lifting a block set by
  `SUSPEND`. It restores the status column to a reintroduced
  `QUADSSO_ACTIVE_STATUS_VALUE`, kept separate from `QUADSSO_DEFAULT_USER_STATUS`
  because the two differ whenever new accounts start life pending approval —
  restoring an established user to `pending` would be a demotion. Sessions are
  not restored (they were destroyed, not parked), so the user signs in again.
  Idempotent on an already-active account; 404 on a deleted one.
  `UserLifecycleHooks` gains `beforeUnsuspend` and `afterUnsuspend`, which is a
  breaking change only for anyone implementing the interface directly rather
  than extending `NullUserLifecycleHooks`.

- **`QUADSSO_LOGGING`**, a master switch for an info-level trace of
  authentication steps, authorization decisions and API hits, routed through a
  single `QuadSsoLog` helper and optionally to its own channel
  (`QUADSSO_LOG_CHANNEL`). Per-category switches remain for narrower streams,
  with a new `QUADSSO_LOG_API_EVENTS`.

  Refusals and errors are now written **regardless** of any logging flag.
  Previously a blocked user's login attempt was only recorded when
  `QUADSSO_LOG_SSO_EVENTS` happened to be on, which meant the security decisions
  worth auditing were the ones least likely to have been captured. The trace
  records email addresses and subject identifiers and stays off by default.

- **Management API** (`QUADSSO_MGMT_ENABLED`, default off): `POST /api/quadsso-mgr`
  authenticated by a shared key in a configurable header (or a bearer token),
  accepting `SUSPEND` and `DELETE` for a user identified by email. SUSPEND clears
  session rows, cycles the remember token, and sets the blocked status; DELETE
  additionally removes the row, using `forceDelete()` on soft-deleting models.
  While disabled the route is not registered at all, so the path 404s rather than
  disclosing that the endpoint exists, and it fails closed with 503 when no key
  is configured. Rate-limited to 60 requests/minute by default.
- **`UserLifecycleHooks`** contract with a `NullUserLifecycleHooks` no-op base,
  invoked around management actions. A throwing `before` hook vetoes the
  operation (nothing written, 409); a throwing `after` hook cannot roll back a
  committed change, so it is logged and surfaced as `post_hook_failed` rather
  than returning an error that would invite retrying a completed action.

- **Login button Blade component**, `<x-quadsso::login-button />`, rendering an
  anchor to the SSO route with Tailwind styling. The label defaults to "Login
  via SSO" and is settable per usage (`text` prop or slot) or globally
  (`QUADSSO_BUTTON_LABEL`). Supplying a background class drops the default
  palette rather than emitting both, since Tailwind resolves competing
  utilities by stylesheet order; `:unstyled` removes all classes. Also available
  as `@ssoLoginButton` for the label-only case, and publishable with
  `--tag=quadsso-views`.

- **Optional lockout of local auth routes** (`QUADSSO_DISABLE_LOCAL_AUTH`, default
  off). Blocks the host application's registration and password-reset routes via
  a middleware pushed onto the `web` group, matching resolved route names with a
  URI-pattern fallback for unnamed routes.

  This closes an SSO bypass: users provisioned through SSO hold a random,
  unusable password, but a reachable reset flow lets them set one they know at
  their IdP-verified address and authenticate locally from then on — surviving
  deactivation at the IdP. `login` and `password.confirm` are deliberately not
  blocked by default; see the README. It is defence in depth, not a guarantee,
  since a bespoke registration controller on an unlisted path is not matched.

- **Attribute sync on login** (`SSO_SYNC_ATTRIBUTES_ON_LOGIN`, default `true`).
  Name and email are refreshed from the IdP on every login. Previously a user row
  froze at whatever it held when created, and a rename at the IdP never reached
  the application — SCIM used to cover this. Email is only followed when the IdP
  asserts `email_verified=true` and no other local row holds the address; a
  collision is logged and skipped rather than throwing.
- **JIT (Just-In-Time) provisioning** (`SSO_ENABLE_JIT_PROVISIONING`, default off).
  Creates users on first SSO login, gated on the IdP asserting
  `email_verified=true` and refusing on email collision with an already-bound
  account. Shipped previously but never recorded here.
- Migration `2024_01_01_000003_add_quadsso_level_to_users_table` creates the user
  level/role column that the provisioning observer has always written to. Without
  it, user creation failed with "column not found" on a standard Laravel schema.
  The column name follows `QUADSSO_USER_LEVEL_FIELD`; rollback is a no-op because
  a role column is commonly shared with the host application.

### Fixed

- **Session termination ignored `session.connection` and `session.table`.**
  `DB::table('sessions')` assumed the default connection and a table literally
  named `sessions`, so an application with either configured differently had its
  sessions left intact while the call reported success. Both are now resolved
  the way Laravel's own database session handler resolves them.

- **Session termination reported success it had not achieved.** A driver keeping
  no server-side rows produced `sessions_cleared: 0` and HTTP 200 while the user
  stayed signed in. The management API now reports `sessions_ended`, the session
  driver, and notes explaining anything it could not do; `sessions_cleared` is
  `null` rather than `0` when no rows exist to count, so a genuine zero is never
  confused with an inapplicable one.

- **The login block check failed open on a misconfigured status column.**
  `$user->$statusField` is attribute access, not a query, so a
  `provisioning.user_status_field` naming a column that does not exist read back
  as null, never equalled the blocked value, and admitted suspended accounts
  with no error anywhere. `validateSchemaConfiguration()` did not cover that key
  either, so nothing warned. Now the callback refuses the login when the status
  attribute cannot be resolved, and the boot-time check warns about every config
  key that names a column. A model with its own `isBlocked()` method, or an empty
  `QUADSSO_BLOCKED_STATUS_VALUE`, opts out deliberately.
- **The local-auth lockout never ran.** It registered its middleware with
  `Router::pushMiddlewareToGroup()` from the provider's `boot()`, but the HTTP
  kernel owns the canonical group definitions and syncs them onto the router
  when it is constructed — which can happen after providers boot, silently
  discarding the push. The `web` group then resolved to the application's
  default stack with the middleware absent, so registration and password-reset
  routes stayed reachable with `QUADSSO_DISABLE_LOCAL_AUTH=true`. Now appended
  through the kernel, which survives the sync. Caught by the new test suite.
- `.env.example` shipped `SSO_ALLOW_LEGACY_EMAIL_BINDING=false`, contradicting the
  `true` default set in 1.3.1. Copying the example file reintroduced the exact
  "No account found for this identity" breakage that release fixed, since an
  explicit env value overrides the config default.
- `.env.example` was missing `SSO_ENABLE_JIT_PROVISIONING`, which shipped with JIT
  provisioning but was never reflected in the example file.

### Testing

- Added a test suite (71 tests) built around security properties rather than
  line coverage: identity resolution and approval boundaries, logout-token
  forgery (unpublished signing key, `alg: none`, RS→HS confusion, foreign
  issuer, wrong audience, replay), SQL-injection surfaces for both IdP-supplied
  values and config-supplied column names, route middleware composition, and the
  local-auth lockout.
- The suite surfaced two defects during development, both fixed here: the
  local-auth lockout never running, and the block check failing open on a
  misconfigured status column.

### Documentation

- `QUICK_START.md` and `PACKAGE_SUMMARY.md` folded into `README.md` and removed.
  `PACKAGE_SUMMARY.md` had drifted badly — it still described identity resolution
  as "finds user by email", the behaviour removed in 1.3.0.
- README now separates required from optional environment variables, documents
  every variable the package actually reads, and describes each migration's
  effect on the `users` table.
- Documented the `sessions` table requirement. SLO deletes from it directly; on a
  non-database session driver it fails, and a failed SLO returns HTTP 500 to
  Authentik. This was never stated.
- Documented the revocation model explicitly, including a test procedure for
  determining whether your Authentik deployment fires back-channel logout on user
  deactivation — the answer decides whether revocation is immediate or bounded by
  session lifetime.
- Corrected the claim that the extended-fields migration is opt-in via `--path`.
  The service provider loads the whole migration directory, so `php artisan
  migrate` has always run it.

## [1.3.1] - 2026-05-18

### Fixed

- **CRITICAL: SSO login broken for users without scim_external_id**
  - Changed `sso.allow_legacy_email_binding` default from `false` to `true`
  - v1.3.0 broke sign-in for: fresh installs, manual users, users not yet synced via SCIM
  - Legacy email binding is SAFE by default (requires email_verified=true from IdP + one-time bind)
  - After binding, identity is always resolved by sub (not email)
  - Only unsafe if app allows self-service email change without re-verification

### Security Note

Legacy email binding remains secure because:
- Requires IdP to assert `email_verified=true` (not self-claimed)
- Only binds when `scim_external_id` IS NULL (one-time operation)
- After binding, all future logins use `sub` claim (not email)

Disable it (`SSO_ALLOW_LEGACY_EMAIL_BINDING=false`) only if your app allows
unverified email changes and you can guarantee all users are SCIM-provisioned
before first login.

## [1.3.0] - 2026-05-18

### 🔒 Security — Critical / High

This release fixes a chain of issues identified in an SSO/SCIM security audit.
Please read the upgrade notes — two changes are technically breaking but
default-safe.

#### Fixed

- **CRITICAL: account takeover via email-based identity matching.**
  - `SsoController::callback` now resolves the local user by the OIDC `sub`
    claim (`scim_external_id`), not by email. Email is no longer authoritative.
  - `QuadSSOScimConfig`'s SCIM POST factory now refuses to silently merge a new
    SCIM user into an existing local row that already belongs to a different
    externalId — it returns RFC 7644 §3.12 `409 uniqueness` instead.
  - In combination, this closes the path where a user with self-service email
    change in the host app could pre-claim a victim's email, then be matched to
    the victim's identity on the victim's next SSO login.
- **HIGH: SLO endpoint was unreachable due to CSRF.** `auth/sso/logout` is now
  registered outside the `web` middleware group so Authentik's back-channel
  POSTs no longer get rejected with HTTP 419. Authentication on this endpoint
  is performed entirely by JWT validation.
- **HIGH: SSO failure paths returned HTTP 500.** `sso.redirect_after_failure`
  is a URL path, not a route name — `redirect()->route()` against it threw
  `RouteNotFoundException`. Switched to `redirect()` (path-aware).

#### Added

- **`logout_token` JWT validation now checks `iss`, `aud`, and `jti`.** Without
  these, any token signed with a key in the configured JWKS could be replayed
  to force a per-user logout. `jti` is cached for 15 minutes to prevent replay.
- **IdP `email_verified` claim is now respected.** The auto-verify-on-first-SSO
  flow only sets `email_verified_at` when the IdP asserts the email is verified.
- New config `sso.allow_legacy_email_binding` (default `false`). When enabled,
  on first SSO login a user with no `scim_external_id` and a matching email
  may be bound to the incoming `sub`. Requires `email_verified=true`.
  Intended for one-time migration of pre-SSO users.
- New config `scim.allow_legacy_email_merge` (default `false`). When enabled,
  SCIM POST may merge into an existing local row that matches by email AND
  has no `scim_external_id`. Same migration intent as above.
- SCIM request-body logging now redacts PII keys (`userName`, `emails`,
  `phoneNumbers`, `name.*`, `externalId`, `password`, ...) before writing
  to the log. Non-JSON bodies are logged as `[non-json body]`.

### Breaking Changes

- **`SsoController::callback` no longer matches by email.** Existing deployments
  must have `scim_external_id` populated for all users before deploying, OR set
  `SSO_ALLOW_LEGACY_EMAIL_BINDING=true` for the migration window and ensure the
  host app does **not** allow self-service email change while it's on.
- **SCIM POST with a colliding email returns 409 instead of merging.** If you
  rely on email-based merge for legacy bootstrapping, set
  `SCIM_ALLOW_LEGACY_EMAIL_MERGE=true` temporarily.

### Migration Guide

For an existing install:

1. Verify every active user has a `scim_external_id`.
   `select count(*) from users where scim_external_id is null and status='active'`
2. If you have legacy users without one:
   - Disable self-service email change in your host app, **then**
   - Set `SSO_ALLOW_LEGACY_EMAIL_BINDING=true` and
     `SCIM_ALLOW_LEGACY_EMAIL_MERGE=true`.
   - Let users log in once to bind, or have Authentik run a SCIM sync.
   - Turn both flags back off.
3. Confirm `auth/sso/logout` returns 200 (not 419) when Authentik posts a valid
   `logout_token`.

## [1.2.2] - 2026-05-18

### Fixed

- PHP syntax error: "Cannot use positional argument after argument unpacking" in QuadSSOScimConfig.php:171
- Refactored attribute building to use array collection instead of mixed spread/positional arguments
- SCIM configuration now loads without PHP errors

## [1.2.1] - 2026-05-18

### 🔒 CRITICAL FIX: SSO Routes Now Have Session Support

**Issue:** SSO authentication failed with "Session store not set on request" error

**Root Cause:** SSO routes were loaded without the 'web' middleware group, missing:
- Session middleware (required for OAuth state management)
- CSRF protection
- Cookie encryption
- Session tracking

**Fix:** Wrapped route loading in `Route::middleware('web')->group()` in `QuadSSOServiceProvider`

**Impact:** SSO login/callback/logout now work correctly with proper session handling

### Fixed

- SSO routes now include 'web' middleware group for session support
- OAuth state verification works properly (no more session errors)
- CSRF tokens properly handled on SSO routes

## [1.2.0] - 2026-05-18

### 🎉 MAJOR FIX: Works with Laravel's Default Schema

**BREAKING CHANGE (Fix):** Default field mappings now work with Laravel's standard `users` table schema.

**Previous Issue:**
- Default config mapped to non-existent columns (`name_first`, `name_last`, `phone_cell`, etc.)
- SCIM provisioning failed with "column does not exist" SQL errors on fresh Laravel installs
- Config claimed "set to null to disable" but null values fell through to column names

**What's Fixed:**
- ✅ Default config now maps to Laravel's standard `name` column (works out-of-the-box)
- ✅ SCIM `givenName` and `familyName` automatically combine into single `name` field
- ✅ Extended fields (`name_first`, `name_last`, etc.) are now **opt-in** via optional migration
- ✅ Null mappings are properly honored - attributes are skipped when mapping is null
- ✅ Schema validation added: warns about missing columns before SCIM requests fail

### Added

- Optional extended fields migration (`2024_01_01_000002_add_extended_quadsso_fields_to_users_table.php`)
- Automatic schema validation on boot (logs warnings for missing columns)
- Smart name field handling: works with single `name` or separate `name_first`/`name_last` columns
- Helper methods for conditional SCIM attribute inclusion
- Documentation for extended fields setup

### Changed

- **BREAKING:** Default `field_mappings` config changed to work with Laravel's standard schema
  - `name_first`, `name_last`, `name_middle` now default to `null` (disabled)
  - Added `'name' => 'name'` mapping for Laravel's standard field
  - `phone_cell`, `email_secondary` now default to `null` (disabled)
- Updated `QuadSSOScimConfig` to properly handle null mappings
- Refactored name attribute building to support both single and split name fields

### Fixed

- SCIM provisioning no longer fails on fresh Laravel installs
- Null field mappings are now properly skipped (no SQL errors)
- Schema mismatches are caught early with clear error messages

### Migration Guide

If you published the config before v1.2.0 and have custom columns:
1. Your existing config with custom mappings will continue to work
2. If you see schema validation warnings, either:
   - Run the extended migration to add the columns, OR
   - Set unused mappings to `null` in your config

For fresh installs:
- No action needed - works with Laravel's default schema out-of-the-box

## [1.1.0] - 2026-05-18

### 🔒 CRITICAL SECURITY FIX

- **Fixed:** SCIM endpoints are now automatically secured with bearer token authentication by default
- **Added:** Auto-configuration of `ScimBearerToken` middleware in `QuadSSOServiceProvider`
- **Impact:** Previously, SCIM endpoints were publicly accessible until manually configured. Users should upgrade immediately.

### Added

- `status` column now automatically created by migration with default value 'active'
- Security verification steps added to QUICK_START.md
- Enhanced security documentation in README.md with verification commands
- Command to generate secure SCIM bearer tokens in documentation
- Laravel 13.x support
- PHP 8.1, 8.2, 8.3 support

### Changed

- Updated QUICK_START.md to emphasize security best practices
- Improved migration to include all required fields (scim_external_id, email_verified_at, status)
- Updated documentation to clarify that migrations handle all field creation automatically
- Broadened Laravel support to 10.x, 11.x, 12.x, and 13.x
- Changed to use `illuminate/contracts` instead of individual illuminate packages

### Fixed

- Users no longer show `"active": false` by default (status field now has proper default)
- Removed need for manual SCIM middleware configuration
- Removed need for manual status field migration
- Resolved composer installation conflicts with Laravel framework

## [1.0.3] - 2026-05-18

### Added
- Laravel 13.x support

## [1.0.2] - 2026-05-18

### Changed
- Broadened PHP support to 8.1, 8.2, and 8.3
- Broadened Laravel support to 10.x, 11.x, and 12.x
- Relaxed dependency version constraints

## [1.0.1] - 2026-05-18

### Fixed
- Changed to use `illuminate/contracts` instead of individual illuminate packages
- Resolved composer installation conflicts

## [1.0.0] - 2024-01-01

### Added
- Initial release
- SCIM user provisioning with Authentik
- SSO authentication via OAuth/OIDC
- Single Logout (SLO) support with back-channel logout
- Configurable user creation, updates, and deletion via SCIM
- Automatic session invalidation when users are blocked
- Flexible field mappings for User model
- Comprehensive configuration options
- Detailed logging for debugging
- Support for Laravel 11.0 and 12.0
- Migration for adding SCIM fields to users table
- Bearer token authentication for SCIM endpoints
- Email verification on first SSO login
- Remember token invalidation on SLO

### Security
- Secure bearer token validation for SCIM requests
- JWT signature verification for SLO tokens
- Constant-time comparison for bearer tokens
- Session invalidation on user block/logout
