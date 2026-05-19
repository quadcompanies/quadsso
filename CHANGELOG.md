# Changelog

All notable changes to QuadSSO will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
