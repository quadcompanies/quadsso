# QuadSSO

A Laravel package for SSO integration with Authentik: OIDC login, Just-In-Time user provisioning, and back-channel Single Logout.

- **SSO authentication** — OAuth/OIDC login against Authentik via Socialite.
- **JIT provisioning** — create users on their first login, from the IdP's own claims.
- **Attribute sync** — refresh name and email from the IdP on every login.
- **Single Logout (SLO)** — Authentik posts a signed logout token; sessions and remember-me cookies are invalidated.
- **Management API** — an opt-in endpoint for suspending or deleting accounts out of band, with application hooks around each action.
- **Configurable field mappings** — map IdP claims onto your own column names.

> **Upgrading from 1.x?** SCIM provisioning was removed in 2.0 and several config keys were renamed. See [Upgrading from 1.x](#upgrading-from-1x).

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- An Authentik instance with an OAuth2/OpenID provider
- A **database-backed session table** — see [Session driver](#session-driver)

---

## Installation

### 1. Install

```bash
composer require quadcompanies/quadsso
php artisan vendor:publish --tag=quadsso-config
php artisan migrate
```

The service provider is auto-discovered. See [Database changes](#database-changes) for exactly what `migrate` alters.

### 2. Session driver

SLO invalidates sessions by deleting rows from the `sessions` table:

```php
DB::table('sessions')->where('user_id', $user->id)->delete();
```

**This package does not create that table.** If your app uses the `file`, `cookie`, or `redis` session driver, the table does not exist and SLO fails with a database error, returning HTTP 500 to Authentik. Laravel 11+ defaults to `database`; Laravel 10 defaults to `file`.

```bash
php artisan session:table   # only if you don't already have one
php artisan migrate
```

```env
SESSION_DRIVER=database
```

If you deliberately run a non-database session driver, set `SSO_ENABLE_SLO=false` and handle logout propagation yourself.

### 3. Add Authentik to `config/services.php`

Socialite reads its credentials from here, not from `config/quadsso.php`:

```php
'authentik' => [
    'client_id'     => env('AUTHENTIK_CLIENT_ID'),
    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
    'redirect'      => env('AUTHENTIK_REDIRECT_URI', env('APP_URL') . '/auth/sso/callback'),
    'base_url'      => env('AUTHENTIK_BASE_URL'),
],
```

### 4. Update your User model

Add the columns QuadSSO writes to your `$fillable`:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'scim_external_id',
    'email_verified_at',
    'status',
    'level',
];
```

The package works with Laravel's standard users table as-is: the IdP's display name goes into the single `name` column.

### 5. Choose a provisioning mode

See [Provisioning](#provisioning) — this is the one decision you have to make deliberately.

### 6. Put a login button on your login page

```blade
<x-quadsso::login-button />
```

If your app uses Tailwind, add the package's views to your content sources or the button's classes will be purged — see [The login button](#the-login-button).

Note that installing QuadSSO also **blocks your application's registration and password-reset routes** by default, since password reset would otherwise bypass SSO entirely — see [Locking out local authentication](#locking-out-local-authentication).

---

## Provisioning

Users have to get into your database somehow. There are two supported paths, and **you must pick one** or nobody will be able to log in.

### JIT (recommended for internal apps)

```env
SSO_ENABLE_JIT_PROVISIONING=true
```

The first time someone completes an SSO login, their account is created from the IdP's claims. No pre-provisioning, no admin step.

This is **off by default** because it is an access-control decision: every user who can authenticate at Authentik *and* is assigned to this application gets a local account. Authentik enforces the assignment check during the authorization step, so the effective gate is your application's Authentik policy bindings — make sure those are correct before enabling this.

JIT additionally requires the IdP to assert `email_verified=true`, and refuses when the email already belongs to a different local identity.

### Manual creation + first-login binding

Leave JIT off, create users through your own admin tooling, and let the first login attach the IdP identity:

```env
SSO_ENABLE_JIT_PROVISIONING=false
SSO_ALLOW_LEGACY_EMAIL_BINDING=true
```

The user must already exist locally with a matching email and a NULL `scim_external_id`. On first login, the IdP's `sub` is written onto that row. Use this when access must be granted explicitly inside your application.

---

## How identity is resolved

Identity is resolved by the OIDC `sub` claim, stored in `scim_external_id`. **Email is not an identifier** — treating it as one would let anyone with self-service email change pre-claim another user's account. The callback tries three things in order:

1. **Look up by `scim_external_id`.** Authoritative.
2. **Legacy email binding** (`SSO_ALLOW_LEGACY_EMAIL_BINDING`, on by default). If nothing matched, find a row with that email **and a NULL `scim_external_id`**, then write the `sub` onto it. Requires `email_verified=true`. Happens once; every later login resolves by `sub`.
3. **JIT provisioning** (`SSO_ENABLE_JIT_PROVISIONING`, off by default). Create the user from the OIDC response.

If all three miss, login is refused with "No account found for this identity."

> **When to disable legacy binding.** Step 2 is safe for most deployments: it needs IdP-verified email, fires only against unbound rows, and never runs again for that user. Set `SSO_ALLOW_LEGACY_EMAIL_BINDING=false` only if your application allows users to change their email **without** re-verification.

Once a user is resolved, their name and email are refreshed from the IdP on every login (`SSO_SYNC_ATTRIBUTES_ON_LOGIN`, on by default). Email is only followed when the IdP asserts it is verified and no other local row already holds it.

---

## Revocation: what happens when someone is deprovisioned

This matters more than it looks, and it is the main tradeoff of a JIT-only design. **Nothing in this package runs between logins.** All identity checks happen during the OIDC callback, so deactivating a user in Authentik has no immediate local effect on its own.

What actually stops a deprovisioned user:

| Mechanism | Effect | Timing |
|---|---|---|
| Authentik refuses the authorization request | They cannot start a new session | Immediate, but only on next login attempt |
| Back-channel SLO | Existing sessions deleted, remember token cycled | Immediate — **if** your IdP fires it on deactivation |
| Session expiry | Existing session dies | Bounded by `SESSION_LIFETIME` |

So the exposure window for an already-signed-in user is **`SESSION_LIFETIME`, unless back-channel logout fires on deactivation**. Set `SESSION_LIFETIME` to something you're comfortable with.

**This is why `SSO_REMEMBER_LOGIN` defaults to `false`.** A remember-me cookie lasts five years by default and would survive deprovisioning entirely, converting a bounded window into an unbounded one. Only turn it on once you have confirmed SLO fires.

### Verifying that back-channel logout fires on deactivation

Worth testing directly — Authentik's behaviour here depends on your version and flow configuration.

```bash
# 1. Watch the log
tail -f storage/logs/laravel.log | grep QuadSSO
```

With `QUADSSO_LOG_SLO_EVENTS=true` (the default):

2. Log into your app via SSO in a browser. Confirm a row appears:
   `select id, user_id from sessions where user_id = <id>;`
3. In Authentik, **deactivate** that user (uncheck *Active* on the user — do **not** log out from the browser).
4. Watch for `QuadSSO SLO: sessions invalidated` in the log, and re-run the query.

- **Row gone, log line present** → SLO fires on deactivation. Revocation is immediate; you may enable `SSO_REMEMBER_LOGIN` if you want it.
- **Nothing happens** → deactivation does not notify your app. Keep remember-me off, keep `SESSION_LIFETIME` short, and block the user in your own application as well (see [Blocking users locally](#blocking-users-locally)).

### Blocking users locally

The SSO callback refuses login when the user's status column holds `QUADSSO_BLOCKED_STATUS_VALUE`, or when your model's `isBlocked()` method returns true. **Nothing in this package writes that value** — it is there for your own admin tooling to set. If you need a local kill switch independent of Authentik, set it there.

---

## Locking out local authentication

**This is on by default.** Installing QuadSSO is a statement that the identity provider owns authentication, so the local routes stop being reachable at that point rather than when somebody remembers to set a flag.

The reason is that **password reset is an SSO bypass**. A user provisioned through SSO holds a random, unusable password — but if your app still has Breeze, Fortify, Jetstream, or Laravel UI scaffolding installed, the reset flow will send them a link at their IdP-verified address, let them set a password they know, and from then on they authenticate locally. That path never touches Authentik, so it also survives deactivation there.

The package pushes a middleware onto the `web` group that matches each resolved route against a list of names, falling back to URI patterns for unnamed routes, and either redirects to `/auth/sso` or returns 404.

If your application genuinely serves both — customers with passwords, staff through SSO — switch it off:

```env
QUADSSO_DISABLE_LOCAL_AUTH=false
```

`php artisan quadsso:doctor` then warns if reachable reset routes remain, so the bypass stays visible rather than silent.

Both lists are in `config/quadsso.php` and cover the common scaffolds by default:

```php
'disable_local_auth' => [
    'route_names' => ['register', 'register.store', 'password.request',
                      'password.email', 'password.reset', 'password.store',
                      'password.update'],
    'paths' => ['register', 'register/*', 'password/*',
                'forgot-password', 'reset-password', 'reset-password/*'],
],
```

Two deliberate omissions:

- **`login` is not blocked.** Removing it takes away your break-glass path if SSO itself breaks. Add it only once you're sure you can always reach Authentik.
- **`password.confirm` is not blocked.** It verifies an existing password rather than setting one, so it isn't a bypass, and blocking it breaks Jetstream/Fortify flows that gate sensitive actions behind confirmation.

Note that `password.update` names the reset submission in Laravel UI but the authenticated "change my password" screen in Breeze. Blocking both is intended for an SSO-only app.

### This is defence in depth, not a guarantee

The middleware matches known route names and paths. An application with its own registration controller on an unlisted path is not caught, and no package can promise otherwise — the host app owns its routes.

The durable fix is to make password authentication impossible at the model level, where no route configuration can route around it:

```php
// in your User model — the local password can never satisfy a check
public function getAuthPassword() { return null; }
```

Best is both: the middleware so the routes stop being reachable and stop appearing in your UI, and the model-level change so that even a missed route can't authenticate anyone.

---

## Management API

An opt-in `POST` endpoint for terminating or removing an account from outside the SSO flow — an offboarding job, an admin tool, a webhook from your HR system.

```env
QUADSSO_MGMT_ENABLED=true
QUADSSO_MGMT_API_KEY=a-long-random-string
```

```bash
php artisan tinker --execute="echo \Illuminate\Support\Str::random(64);"
```

While disabled the route is **not registered at all**, so the path 404s rather than answering 401 — an unconfigured install doesn't advertise that an account-deletion endpoint exists.

### Requests

```http
POST /api/quadsso-mgr
X-QuadSSO-Key: a-long-random-string
Content-Type: application/json

{"action": "SUSPEND", "email": "user@example.com"}
```

`Authorization: Bearer <key>` works too, for callers that only speak bearer auth. The path and header name are configurable (`QUADSSO_MGMT_PATH`, `QUADSSO_MGMT_HEADER`).

| Action | Effect |
|---|---|
| `SUSPEND` | Delete the user's session rows, cycle the remember token, set the status column to the blocked value |
| `UNSUSPEND` | Set the status column back to the active value, lifting the block |
| `DELETE` | Everything `SUSPEND` does, then remove the row |

Users are identified by email address. Actions are case-insensitive.

```json
{"status": "ok", "action": "SUSPEND", "user_id": 42, "sessions_cleared": 3, "post_hook_failed": false}
```

| Status | Meaning |
|---|---|
| `200` | Done |
| `401` | Missing or wrong key |
| `404` | No user with that email |
| `409` | A `before` hook vetoed it — nothing was changed |
| `422` | Missing or unknown action, or malformed email |
| `500` | The configured hooks class could not be resolved — nothing was changed |
| `503` | No API key configured; the endpoint refuses to serve unauthenticated |

Cycling the remember token is part of `SUSPEND`, not an extra: a remember-me cookie outlives session rows, so without it "end the session" wouldn't.

`UNSUSPEND` reverses the block, not the sign-out. Sessions were destroyed rather than parked, so they cannot come back — the user signs in again, and `sessions_cleared` is `null`. It restores the status to `QUADSSO_ACTIVE_STATUS_VALUE`, which is deliberately a separate setting from `QUADSSO_DEFAULT_USER_STATUS`: if new accounts start life as `pending`, restoring an established user to `pending` would be a demotion, not a reinstatement. It is idempotent on an account that is already active, and 404s on one that has been deleted.

`DELETE` uses `forceDelete()` when the model soft-deletes, so the row really leaves the table. Set `QUADSSO_MGMT_FORCE_DELETE=false` to keep soft-delete semantics — the account is marked blocked before deletion either way, so a restored row is still refused at login.

The endpoint ships rate-limited at 60 requests/minute. Change or remove that with `management.middleware` in the config.

### Hooks

Point the config at a class extending `NullUserLifecycleHooks` and override only what you need. It is resolved from the container, so constructor injection works.

```php
namespace App\Sso;

use Illuminate\Database\Eloquent\Model;
use QuadCompanies\QuadSSO\Support\NullUserLifecycleHooks;

class OffboardingHooks extends NullUserLifecycleHooks
{
    public function __construct(private BillingClient $billing) {}

    public function beforeDelete(Model $user): void
    {
        if ($this->billing->hasOpenInvoices($user)) {
            throw new \RuntimeException('user has open invoices');   // vetoes the request
        }

        $this->billing->closeAccount($user);
    }

    public function afterDelete(Model $user): void
    {
        Log::info('offboarded', ['email' => $user->email]);
    }
}
```

```env
QUADSSO_MGMT_HOOKS="App\Sso\OffboardingHooks"
```

Six hooks: `beforeSuspend`, `afterSuspend`, `beforeUnsuspend`, `afterUnsuspend`, `beforeDelete`, `afterDelete`.

**A throwing `before` hook vetoes the operation.** Nothing is written and the API responds `409` carrying your exception message. That is the supported way to protect an account from deletion.

**A throwing `after` hook does not roll anything back** — the change is already committed by then. It is caught, logged, and reported as `"post_hook_failed": true` alongside a `200`. Returning an error there would invite the caller to retry an action that already took effect.

The `before` hook sees the account as it was; the `after` hook sees it changed. For `DELETE` specifically, `beforeDelete` runs while the row still exists and `afterDelete` runs once it is gone — the model still carries its attributes in memory, but it is detached, so reloading it finds nothing.

---

## Logging

```env
QUADSSO_LOGGING=true
```

That switches on an info-level trace of what the package is doing: login started, callback received, which route resolved the identity, authorization allowed or refused, attributes synced, SLO token verified, management API hit and action completed.

```
[2026-08-20 09:14:22] production.INFO: QuadSSO: callback received from the identity provider {"external_id":"8f3c...","email":"ada@example.com","email_verified":true}
[2026-08-20 09:14:22] production.INFO: QuadSSO: identity resolved by external_id {"user_id":42,"external_id":"8f3c..."}
[2026-08-20 09:14:22] production.INFO: QuadSSO: login authorised, session established {"user_id":42,"external_id":"8f3c..."}
```

Every message is prefixed `QuadSSO:` so the whole stream is one `grep` away.

### Refusals are logged whether or not the trace is on

Warnings and errors — refused logins, rejected API keys, failed token verification, missing columns — are **always written**, regardless of `QUADSSO_LOGGING`. A security decision that is only visible when debug logging happened to be enabled cannot be audited afterwards, and the moment you need to answer "why couldn't they sign in" is exactly the moment nobody had the flag turned on.

So a quiet log means nothing was refused, not that nothing was watched.

### The trace records personal data

It logs email addresses and IdP subject identifiers, because those are what you need to trace a specific person's login. Treat the output with the same care as the users table: keep it out of third-party log aggregators you would not put user records into, and keep retention short. That is why it is off by default.

### Routing it somewhere of its own

```env
QUADSSO_LOG_CHANNEL=quadsso
```

Define the channel in `config/logging.php` first. This keeps the trace out of your application log and lets you give it its own retention:

```php
'quadsso' => [
    'driver' => 'daily',
    'path' => storage_path('logs/quadsso.log'),
    'level' => 'debug',
    'days' => 14,
],
```

Leave it unset to use the application default channel.

### One category at a time

`QUADSSO_LOGGING=true` turns on everything. For a narrower stream, leave it off and switch on individual categories:

| Variable | Default | Covers |
|---|---|---|
| `QUADSSO_LOG_SSO_EVENTS` | `false` | Login redirect, callback, identity resolution, attribute sync, blocked local-auth routes |
| `QUADSSO_LOG_SLO_EVENTS` | `true` | Back-channel logout: request received, token verified, sessions invalidated |
| `QUADSSO_LOG_API_EVENTS` | `false` | Management API hits, authorization, actions, lifecycle hooks |

The master switch wins: with `QUADSSO_LOGGING=true`, a category set to `false` is still logged.

### Diagnosing a failed handshake

`InvalidStateException` is the most common way an SSO login fails, and the hardest to read: Socialite throws it with no message, and the two halves of a login are separate HTTP requests that share no identifier. Both legs now carry the evidence needed to pair them.

With `QUADSSO_LOG_SSO_EVENTS=true`, a healthy login looks like this:

```
QuadSSO: login started, redirecting to the identity provider {"session_present":true,"session_id":"7EoTh44H...","state_stored_fp":"a41f9c2e","host":"app.example.com","authorize_host":"id.example.com","redirect_uri":"https://app.example.com/auth/sso/callback"}
QuadSSO: verifying the OAuth state returned by the identity provider {"session_present":true,"session_id":"7EoTh44H...","session_empty":false,"state_in_session":true,"state_stored_fp":"a41f9c2e","state_returned_fp":"a41f9c2e","state_matches":true,...}
```

Same `session_id` on both lines, same `state_stored_fp`, `state_matches: true`. When a login fails, read `state_in_session` and `state_matches` together:

| `state_in_session` | Then | Means |
|---|---|---|
| `false` | `session_empty: true` | The cookie never came back and this request minted a new session. Look at a host difference between the legs (www versus apex), a cookie-driver session over the browser's 4KB limit, `SESSION_SAME_SITE=strict`, an untrusted proxy building URLs for the wrong host, or two instances with different `APP_KEY`s so the cookie will not decrypt. |
| `false` | `session_empty: false` | The session came back, but the state was already consumed. A reloaded, bookmarked or replayed callback URL — it can never succeed twice. |
| `true` | `state_matches: false` | A second login started before this one returned and overwrote the state. Look for two `login started` lines and compare `state_stored_fp` against `replaced_state_fp`. |

`replaced_state_fp` appears on the redirect leg only when it found a state already waiting — the signature of a double-click, a prefetching browser, or an impatient reload.

State nonces are never written out, only fingerprinted (first 8 hex of SHA-256). That is enough to compare them across lines without putting a credential in flight into your log aggregator.

Failures are logged at error level, so they appear whether or not `QUADSSO_LOG_SSO_EVENTS` is on — but the two trace lines above are what turn "it failed" into "here is which of the two legs lost the session", so switch the category on before trying to reproduce.

---

## Ending sessions

Deleting a session row only ends a session when the driver keeps rows. **Laravel Cloud defaults to the `cookie` driver**, where the session lives entirely in the client's cookie and the server holds no record of it — there is nothing to delete, and a table-based approach achieves nothing while appearing to succeed.

So QuadSSO revokes the other way round: a timestamp on the user marks the moment every existing session became invalid, and middleware rejects any session established before it.

```bash
php artisan migrate      # adds users.quadsso_sessions_valid_after
```

That is all the setup required. The middleware registers itself on the `web` group, and the check costs no extra queries — Laravel's session guard already loads the user row on every authenticated request, so the timestamp arrives with it.

It applies to `SUSPEND`, `DELETE`, and back-channel `SLO` alike, and works on every session driver.

### What actually happens on revoke

| Step | Applies when |
|---|---|
| Delete session rows | `session.driver` is `database` — resolved through `session.connection` and `session.table`, not assumed |
| Stamp the revocation timestamp | Always, once the migration has run. This is the part that works on `cookie` |
| Cycle the remember token | Always — a remember-me cookie outlives session rows |
| Cycle the password hash | Only with `QUADSSO_CYCLE_PASSWORD_ON_REVOKE=true` |

The management API reports what it managed:

```json
{"status":"ok","action":"SUSPEND","sessions_ended":true,
 "sessions_cleared":null,"session_driver":"cookie"}
```

`sessions_ended: false` means the account is blocked but a live session may still be usable. `sessions_cleared` is `null` rather than `0` when the driver keeps no rows, so a genuine "zero rows" is never confused with "this driver has no rows to count".

### Sessions created before you upgraded

The column is null until the first revocation, so installing this logs nobody out. A session with no recorded establishment time — one predating the upgrade, or created by your own login form — counts as older than any revocation, so it is revoked too once a timestamp is stamped.

### Password-hash cycling

```env
QUADSSO_CYCLE_PASSWORD_ON_REVOKE=true
```

A second, independent revocation path for applications running Laravel's own `auth.session` middleware, which compares the session's stored password hash against the user's current one. Harmless for SSO-provisioned users, who hold a random unusable password already.

Two caveats: it invalidates a real password if the account has one, and `AuthenticateSession` returns early when `getAuthPassword()` is empty — so it does nothing in applications that neutralise the password to block local login. The two hardenings are mutually exclusive.

### Turning it off

`QUADSSO_SESSION_REVOCATION=false` skips the stamp and does not register the middleware. On a server-side driver you still get row deletion; on `cookie` nothing will be able to end a session.

---

## Diagnostics

```bash
php artisan quadsso:doctor
```

Checks the things that fail silently: a session driver that keeps no server-side record, a status column named in config that does not exist, a revocation column that was never migrated, a `sessions` table on the wrong connection, an SLO route that has drifted inside the `web` group.

```
| Area       | Check                          | Status | Detail                                     |
| sessions   | session.driver                 | WARN   | cookie keeps no server-side session record |
| sessions   | revocation column              | PASS   | users.quadsso_sessions_valid_after         |
| sessions   | revocation middleware          | PASS   | active on the web group                    |
| schema     | provisioning.user_status_field | PASS   | users.status                               |
```

Add `--json` for machine-readable output; the exit code is non-zero when any check fails, so it works in a deploy pipeline. It is the fastest way to answer "why does SSO behave differently here" without hand-crafting a tinker one-liner.

---

## Environment variables

### Required

| Variable | Notes |
|---|---|
| `AUTHENTIK_CLIENT_ID` | Also verified as the `aud` claim on logout tokens. |
| `AUTHENTIK_CLIENT_SECRET` | Read via `config/services.php`. |
| `AUTHENTIK_REDIRECT_URI` | Must match the redirect URI registered in Authentik. Read via `config/services.php`. |
| `AUTHENTIK_BASE_URL` | Also verified as the `iss` prefix on logout tokens. |
| `AUTHENTIK_JWKS_URI` | Verifies the SLO logout token signature. Required unless `SSO_ENABLE_SLO=false`. |

### Optional

Every value below is the package default; set the variable only to change it.

**Provisioning mode** — see [Provisioning](#provisioning)

| Variable | Default | Effect |
|---|---|---|
| `SSO_ENABLE_JIT_PROVISIONING` | `false` | Create users on first SSO login. |
| `SSO_ALLOW_LEGACY_EMAIL_BINDING` | `true` | One-time bind of the IdP `sub` onto an existing row matched by email. |

**Session and revocation** — see [Revocation](#revocation-what-happens-when-someone-is-deprovisioned)

| Variable | Default | Effect |
|---|---|---|
| `SSO_REMEMBER_LOGIN` | `false` | Issue a remember-me cookie on login. Outlives deprovisioning — leave off unless SLO is confirmed working. |
| `SSO_ENABLE_SLO` | `true` | Accept back-channel logout. When false, the endpoint returns 403. |
| `QUADSSO_SESSION_REVOCATION` | `true` | Stamp a revocation timestamp so sessions are rejected on their next request — see [Ending sessions](#ending-sessions). |
| `QUADSSO_SESSION_REVOKED_AT_FIELD` | `quadsso_sessions_valid_after` | Column holding the revocation timestamp. |
| `QUADSSO_CYCLE_PASSWORD_ON_REVOKE` | `false` | Also cycle the password hash, for apps using `auth.session`. |

**Locking out local authentication** — see [Locking out local auth](#locking-out-local-authentication)

| Variable | Default | Effect |
|---|---|---|
| `QUADSSO_DISABLE_LOCAL_AUTH` | `true` | Block the app's registration and password-reset routes. Set `false` for mixed password/SSO apps. |
| `QUADSSO_LOCAL_AUTH_RESPONSE` | `redirect` | `redirect` or `404`. |
| `QUADSSO_LOCAL_AUTH_REDIRECT` | `/auth/sso` | Where `redirect` sends the visitor. |

**Login behaviour**

| Variable | Default | Effect |
|---|---|---|
| `SSO_SYNC_ATTRIBUTES_ON_LOGIN` | `true` | Refresh name/email from the IdP on every login. |
| `SSO_AUTO_VERIFY_EMAIL` | `true` | Stamp `email_verified_at` on first login — only when the IdP asserts `email_verified=true`. |
| `SSO_REDIRECT_AFTER_LOGIN` | `/home` | URL **path**, not a route name. |
| `SSO_REDIRECT_AFTER_FAILURE` | `/login` | URL **path**, not a route name. |

**User defaults and column names**

| Variable | Default | Effect |
|---|---|---|
| `QUADSSO_APPLY_DEFAULTS` | `true` | Register the observer that sets password/level/status on create. |
| `QUADSSO_DEFAULT_USER_LEVEL` | `user` | Value written to the level column on create. Empty disables it. |
| `QUADSSO_DEFAULT_USER_STATUS` | `active` | Value written to the status column on create. |
| `QUADSSO_USER_LEVEL_FIELD` | `level` | Column storing the user role/level. The level migration creates whatever you name here. |
| `QUADSSO_USER_STATUS_FIELD` | `status` | Column storing account status. |
| `QUADSSO_ACTIVE_STATUS_VALUE` | `active` | Status value meaning "may sign in". What `UNSUSPEND` restores to. |
| `QUADSSO_BLOCKED_STATUS_VALUE` | `blocked` | Status value that denies login. Written by your app, read by this package. |

**Management API** — see [Management API](#management-api)

| Variable | Default | Effect |
|---|---|---|
| `QUADSSO_MGMT_ENABLED` | `false` | Register the endpoint at all. |
| `QUADSSO_MGMT_API_KEY` | — | Shared secret. Required once enabled; the endpoint 503s without it. |
| `QUADSSO_MGMT_HEADER` | `X-QuadSSO-Key` | Header carrying the key. Bearer tokens also accepted. |
| `QUADSSO_MGMT_PATH` | `api/quadsso-mgr` | Endpoint path. |
| `QUADSSO_MGMT_FORCE_DELETE` | `true` | Bypass soft deletes so `DELETE` really removes the row. |
| `QUADSSO_MGMT_HOOKS` | — | Class extending `NullUserLifecycleHooks`, run around each action. |

**Other**

| Variable | Default | Effect |
|---|---|---|
| `QUADSSO_USER_MODEL` | `App\Models\User` | Custom user model. |
| `QUADSSO_BUTTON_LABEL` | `Login via SSO` | Default label for the login button component. |
| `QUADSSO_ERROR_BAG` | `quadsso` | Error bag carrying SSO failure messages. `default` merges them into your form's errors. |
| `QUADSSO_LOGGING` | `false` | Master switch for the info-level trace — see [Logging](#logging). Refusals are logged regardless. |
| `QUADSSO_LOG_CHANNEL` | — | Route the trace to a dedicated log channel. |
| `QUADSSO_LOG_SSO_EVENTS` | `false` | Trace login redirect, callback, and identity resolution. |
| `QUADSSO_LOG_SLO_EVENTS` | `true` | Trace back-channel logout. |
| `QUADSSO_LOG_API_EVENTS` | `false` | Trace management API hits and actions. |

---

## Database changes

QuadSSO **alters your existing `users` table** and creates no tables of its own. All three migrations are registered by the service provider, so **`php artisan migrate` runs all of them**. Every column is guarded by a `hasColumn` check, so existing columns are left untouched and re-running is safe.

To pick and choose, publish them and delete what you don't want:

```bash
php artisan vendor:publish --tag=quadsso-migrations
```

### `2024_01_01_000001` — core fields

| Column | Type | Purpose |
|---|---|---|
| `scim_external_id` | `string` nullable **unique** | The OIDC `sub` / Authentik UUID. The authoritative identity key. Retains its historical name so 1.x installs need no data migration. |
| `email_verified_at` | `timestamp` nullable | Standard Laravel column; added only if missing. |
| `status` | `string` default `active` | Read by the login block check. |

Rollback drops only `scim_external_id`. `status` and `email_verified_at` are left in place because the host app may depend on them.

### `2024_01_01_000002` — extended fields

Split name and contact columns, all `string` nullable: `name_first`, `name_last`, `name_middle`, `phone_cell`, `email_secondary`.

These columns are **created by default** but **not used by default** — the corresponding `field_mappings` entries ship as `null`. To populate them see [Extended user fields](#extended-user-fields). Rollback drops all five.

### `2024_01_01_000003` — level column

| Column | Type | Purpose |
|---|---|---|
| `level` | `string` nullable default `user` | Role/level written by the provisioning observer. |

The column name follows `QUADSSO_USER_LEVEL_FIELD`, so pointing that at a role column you already have makes this migration a no-op. Rollback is deliberately a no-op: a role column is commonly shared with the host app's own authorization logic, and the migration cannot tell whether it owns the column.

> **Why this column is required.** The observer sets a default level on **every** user it creates — this is a global `creating` model event, so it fires for your own registration flows and seeders too, not just SSO logins. Set `QUADSSO_DEFAULT_USER_LEVEL=` (empty) to switch that behaviour off entirely.

### Columns expected to already exist

From Laravel's standard users table: `id`, `email`, `name`, `password` (the observer writes a random hash for provisioned users), and `remember_token` (cycled on SLO).

---

## Authentik setup

### 1. OAuth provider

**Applications → Providers → Create → OAuth2/OpenID Provider**

- **Client type**: Confidential
- **Redirect URIs**: `https://your-app.com/auth/sso/callback`
- **Signing key**: any configured certificate
- **Back-channel logout URL**: `https://your-app.com/auth/sso/logout`

Note the generated Client ID and Client Secret.

### 2. Application

**Applications → Create** — set a name and slug, and select the provider above.

### 3. Assign users

On the application, **Edit → Assigned permissions**, add the users or groups who should have access. With JIT enabled this binding *is* your access control — see [Provisioning](#provisioning).

---

## Usage

Send users to `/auth/sso`. The package ships a Blade component for the login form:

```blade
<x-quadsso::login-button />
```

That renders an anchor pointing at the SSO route, labelled **Login via SSO**, with Tailwind styling.

### The login button

**Relabel it** per usage, or everywhere at once with `QUADSSO_BUTTON_LABEL`:

```blade
<x-quadsso::login-button text="Sign in with Acme ID" />
```

**Recolour it** by passing a background. The default indigo palette steps aside as soon as you supply one, because Tailwind resolves competing utilities by stylesheet order rather than by the order they appear in the class attribute — emitting both would make the winner depend on how your palette happens to be ordered:

```blade
<x-quadsso::login-button class="w-full bg-emerald-600 text-white hover:bg-emerald-500" />
```

Structural classes (spacing, radius, font, focus ring) always survive, so you keep the shape and change only the colour. If you supply your own background, supply a text colour too.

**Take over completely** with `:unstyled`, which emits no classes at all — useful with a design system of your own:

```blade
<x-quadsso::login-button :unstyled="true" class="btn btn-primary" />
```

**Add an icon** — slot content replaces the label entirely:

```blade
<x-quadsso::login-button class="w-full">
    <svg class="h-4 w-4" aria-hidden="true"><!-- ... --></svg>
    Sign in with Acme ID
</x-quadsso::login-button>
```

Any other attribute (`id`, `data-*`, `aria-*`, `wire:navigate`) passes through to the anchor.

**Directive form**, if you'd rather not write a component tag:

```blade
@ssoLoginButton
@ssoLoginButton('Sign in with Acme ID')
```

It renders exactly the same markup, but takes a label only — anything needing classes, a slot, or other attributes should use the component.

### Failure messages

When a login is refused — suspended account, unverified email, no matching identity — the user is redirected back to your login page with a flashed reason. The component renders it directly above the button, so a refusal reads as a refusal rather than an unexplained page refresh.

```html
<div data-quadsso-login>
    <div data-quadsso-login-error role="alert" aria-live="polite" class="mb-3 rounded-md bg-red-50 ...">
        <p>Your account has been suspended. Please contact an administrator.</p>
    </div>
    <a href="/auth/sso" class="...">Login via SSO</a>
</div>
```

The region is **always in the DOM** and only picks up styling once it has something to show, so an empty one is invisible and your own JavaScript has a stable `[data-quadsso-login-error]` target to write into.

Messages go to a dedicated `quadsso` error bag rather than the default one, so a failed SSO login does not surface under your email or password field. If you would rather they joined your form's normal error display:

```env
QUADSSO_ERROR_BAG=default
```

Restyle or suppress the region as needed:

```blade
<x-quadsso::login-button error-class="alert alert-danger" />
<x-quadsso::login-button :show-errors="false" />
```

`:unstyled` drops the error styling along with the button styling; the message still renders.

#### Tailwind has to be told where the component lives

The component ships inside `vendor/`, which Tailwind does not scan by default. Without this the classes it emits are purged and the button renders as a bare link — the most common reason a packaged Tailwind component "doesn't work".

Tailwind v4, in your CSS entrypoint:

```css
@source "../../vendor/quadcompanies/quadsso/resources/views";
```

Tailwind v3, in `tailwind.config.js`:

```js
export default {
    content: [
        './resources/**/*.blade.php',
        './vendor/quadcompanies/quadsso/resources/views/**/*.blade.php',
    ],
};
```

Publishing the view (below) sidesteps this entirely, since the published copy sits under `resources/views/`, which is already scanned.

**Not using Tailwind?** Use `:unstyled` and bring your own classes — the component then emits nothing but the `href` and whatever you pass:

```blade
<x-quadsso::login-button :unstyled="true" class="btn btn-primary" />
```

**Customise the markup** by publishing the view:

```bash
php artisan vendor:publish --tag=quadsso-views
```

It lands at `resources/views/vendor/quadsso/components/login-button.blade.php`.

### Routes

| Method | URI | Name | Middleware |
|---|---|---|---|
| `GET` | `/auth/sso` | `sso.redirect` | `web`, `guest` |
| `GET` | `/auth/sso/callback` | `sso.callback` | `web` |
| `POST` | `/auth/sso/logout` | `sso.logout` | *(none — see below)* |

The SLO endpoint is intentionally outside the `web` group. Authentik posts a signed JWT server-to-server with no session and no CSRF token; inside `web`, `VerifyCsrfToken` would reject every request with HTTP 419. It authenticates by JWT signature, issuer, audience, and `jti` replay check instead.

### Flows

**Login** — user hits `/auth/sso` → authenticates at Authentik → callback resolves identity → blocked users are refused → attributes synced → `Auth::login()`.

**Single Logout** — user logs out of Authentik → Authentik `POST`s a signed `logout_token` to `/auth/sso/logout` → signature, issuer, audience, `backchannel-logout` event claim, and `jti` replay are all verified → sessions deleted and remember token cycled.

---

## Customization

### Field mappings

Map IdP claims onto your own columns in `config/quadsso.php`. `null` disables a mapping.

```php
'field_mappings' => [
    'email' => 'email',
    'external_id' => 'scim_external_id',
    'email_verified_at' => 'email_verified_at',
    'name' => 'name',
    'name_first' => null,
    'name_last' => null,
    'name_middle' => null,
    'phone_cell' => null,
    'email_secondary' => null,
],
```

The package logs a warning at boot for any mapping that points at a column your `users` table doesn't have.

### Extended user fields

The columns already exist after `migrate`; enable the mappings and turn off the combined `name` field:

```php
'field_mappings' => [
    // ...
    'name' => null,               // was 'name'
    'name_first' => 'name_first',  // was null
    'name_last' => 'name_last',    // was null
],
```

When both `name_first` and `name_last` are set, the IdP's display name is split on the first space. `name_middle`, `phone_cell`, and `email_secondary` have no corresponding OIDC claim and are not populated by login — they exist for your own use.

Then add each one to your User model's `$fillable`.

### Status handling

```php
'provisioning' => [
    'user_status_field'    => 'account_status',
    'blocked_status_value' => 'disabled',
],
```

The callback also honours an `isBlocked()` method on your User model if one exists.

**The status column is required, and the check fails closed.** `$user->$statusField` is attribute access, not a query — a column name that doesn't exist reads back as `null`, `null` never equals the blocked value, and the block check would quietly pass, admitting suspended accounts with no error anywhere. So a status attribute that can't be resolved refuses the login instead. Locking everyone out on a typo is loud and fixed in minutes; admitting terminated staff is neither.

Three things satisfy the check: a real column, a classic `getFooAttribute()` accessor, or an `Attribute`-class accessor. Two things opt out of it: an `isBlocked()` method on your model, or an empty `QUADSSO_BLOCKED_STATUS_VALUE`, which disables status-based blocking as a deliberate choice rather than a typo.

The boot-time schema check also warns about a missing status or level column, so the misconfiguration shows up in your logs before anyone hits it.

### Custom user model

```php
'user_model' => \App\Models\CustomUser::class,
```

### Disable default assignment

To handle user creation entirely yourself, set `'provisioning' => ['apply_defaults' => false]`. This also disables the random-password and default status/level assignment — you must supply those yourself, including a password, or user creation will fail on a non-nullable column.

---

## Upgrading from 1.x

**2.0 removes SCIM provisioning.** If you provision users through Authentik's SCIM provider, do not upgrade until you have switched to JIT.

1. **Enable JIT before upgrading**, so users keep being created: `SSO_ENABLE_JIT_PROVISIONING=true`.
2. **Remove the SCIM provider** from your Authentik application's Backchannel Providers. Left in place, it will start receiving HTTP 404s.
3. **Drop these environment variables** — they no longer do anything: `SCIM_BEARER_TOKEN`, `SCIM_AUTO_PROVISION`, `SCIM_ALLOW_USER_CREATION`, `SCIM_ALLOW_USER_UPDATES`, `SCIM_ALLOW_USER_DELETION`, `SCIM_INVALIDATE_SESSIONS_ON_BLOCK`, `SCIM_ALLOW_LEGACY_EMAIL_MERGE`, `SCIM_ACTIVE_STATUS_VALUE`, `QUADSSO_LOG_SCIM_REQUESTS`.
4. **Rename these**, which kept their meaning:

   | 1.x | 2.0 |
   |---|---|
   | `SCIM_DEFAULT_USER_LEVEL` | `QUADSSO_DEFAULT_USER_LEVEL` |
   | `SCIM_DEFAULT_USER_STATUS` | `QUADSSO_DEFAULT_USER_STATUS` |
   | `SCIM_USER_LEVEL_FIELD` | `QUADSSO_USER_LEVEL_FIELD` |
   | `SCIM_USER_STATUS_FIELD` | `QUADSSO_USER_STATUS_FIELD` |
   | `SCIM_BLOCKED_STATUS_VALUE` | `QUADSSO_BLOCKED_STATUS_VALUE` |

   These are silent if missed: a renamed variable falls back to its default, so a custom `SCIM_USER_STATUS_FIELD=account_status` would quietly revert to `status`. Check yours.

5. **Republish the config** if you published it: `php artisan vendor:publish --tag=quadsso-config --force` (back up your copy first — the `scim` section is now `provisioning`).
6. **Blocking is now yours to drive.** SCIM used to set the blocked status and delete sessions. Nothing writes it now — see [Blocking users locally](#blocking-users-locally).
7. **Check `SSO_REMEMBER_LOGIN`.** 1.x always issued a remember-me cookie; 2.0 defaults to not issuing one. Existing cookies keep working until they expire or SLO cycles the token. Set it to `true` to keep the old behaviour, after reading [Revocation](#revocation-what-happens-when-someone-is-deprovisioned).

No data migration is required. The `scim_external_id` column keeps its name.

---

## Troubleshooting

Enable verbose logging, then check `storage/logs/laravel.log`:

```env
QUADSSO_LOGGING=true
```

| Symptom | Cause |
|---|---|
| `Authentication failed. Please try again.` | A state mismatch. The log carries the explanation and a `session_empty` flag: `true` means the session cookie never came back (host mismatch, oversized cookie session, strict SameSite, untrusted proxy); `false` means a stale or replayed callback URL. |
| `No account found for this identity` | No provisioning path matched. Enable JIT, or create the user locally first. Check they're assigned to the application in Authentik. |
| `Your email address has not been verified...` | The IdP returned `email_verified=false`. The package will not bind or provision on an unverified address. |
| `Your account has been suspended` | The user's status column holds the blocked value. |
| Name/email changes at the IdP don't appear | `SSO_SYNC_ATTRIBUTES_ON_LOGIN=false`, or the email is already held by another local user (logged as a warning). |
| SLO returns 500 | Usually a missing `sessions` table — see [Session driver](#session-driver). |
| SLO returns 400 | Signature, issuer, audience, `jti` replay, or event-claim check failed. Confirm `AUTHENTIK_BASE_URL`, `AUTHENTIK_CLIENT_ID`, and `AUTHENTIK_JWKS_URI`. |
| SLO returns 419 | The route landed inside the `web` middleware group. |
| Users stay logged in after deactivation | Expected unless SLO fires on deactivation — see [Revocation](#revocation-what-happens-when-someone-is-deprovisioned). |
| Login button renders unstyled | Tailwind is purging the package's classes; add its views to your content sources, publish the view, or use `:unstyled`. |
| `Your account status could not be verified` | `QUADSSO_USER_STATUS_FIELD` names a column that doesn't exist. Logins are refused rather than admitted, because a status that can't be read can't be checked. Point it at a real column, or set `QUADSSO_BLOCKED_STATUS_VALUE` empty to disable status-based blocking. |
| Column-not-found on user create | A mapped column is missing. Run `migrate`, or set that mapping to `null`. |

---

## Security

- Identity is resolved by the OIDC `sub` claim, never by email.
- SLO logout tokens are verified for signature (JWKS, cached 1 hour), issuer, audience, the `backchannel-logout` event claim, and `jti` replay within a 15-minute window.
- JIT provisioning and first-login binding both require the IdP to assert `email_verified=true`.
- Provisioned users get a random 32-character password hash, so the local password is unusable.
- Remember-me is off by default, bounding post-deprovisioning access to `SESSION_LIFETIME`.

**Recommended:** serve everything over HTTPS, keep `SESSION_LIFETIME` aligned with how quickly you need deprovisioning to take effect, and rotate your Authentik client secret periodically.

---

## Tests

```bash
composer install
composer test
```

The suite runs against Testbench with an in-memory SQLite database. It is written as a set of security properties rather than coverage of every branch:

| Suite | Guards |
|---|---|
| `IdentityResolutionTest` | Who is allowed to become which local row — sub-over-email precedence, one-time binding, JIT gating, blocked users |
| `SloTokenTest` | Logout tokens are the endpoint's only access control: forged keys, `alg: none`, RS→HS confusion, foreign issuer, wrong audience, replay |
| `ConfigInjectionTest` | IdP-supplied values stay data; config-supplied column names fail closed |
| `SessionRevocationTest` | Revocation works on a stateless driver, honours `session.connection`/`table`, and rejects stale sessions |
| `DoctorCommandTest` | The diagnostic surfaces cookie drivers, missing columns, and unregistered middleware |
| `LoggingTest` | The trace is opt-in, refusals are not, and channel routing works |
| `SchemaValidationTest` | The boot-time warning covers every config key that names a column, not just `field_mappings` |
| `RouteGuardTest` | The middleware stack on each route, including that SLO stays outside `web` |
| `ManagementApiTest` / `Disabled` / `Throttle` | Key enforcement, action semantics, hook ordering and veto, and that the route does not exist while disabled |
| `LoginButtonErrorsTest` | Failure messages reach the login page, are escaped, and stay out of the app's own error bag |
| `LoginButtonTest` | The button's destination, labelling, escaping, and that caller styling wins |
| `LocalAuthLockoutTest` / `LocalAuthPassthroughTest` | The registration/reset lockout blocks what it should and nothing else, and stays off until asked |

---

## License

MIT. See [CHANGELOG.md](CHANGELOG.md) for version history.

Built by Quad Companies on [Socialite](https://github.com/laravel/socialite) and [socialiteproviders/authentik](https://github.com/SocialiteProviders/Authentik).
