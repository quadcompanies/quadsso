<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The User model class used for authentication and provisioning.
    |
    */

    'user_model' => env('QUADSSO_USER_MODEL', \App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Authentik Configuration
    |--------------------------------------------------------------------------
    |
    | Only the values this package reads directly live here.
    |
    | `client_secret` and `redirect` are NOT duplicated in this file — Socialite
    | reads them from config/services.php ('authentik' key). Defining them here
    | too would create two sources of truth for the same credential.
    |
    |   client_id  — SLO audience check (logout_token `aud` must contain it)
    |   base_url   — SLO issuer check (logout_token `iss` must start with it)
    |   jwks_uri   — public keys used to verify the SLO logout_token signature
    |
    */

    'authentik' => [
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'base_url'  => env('AUTHENTIK_BASE_URL'),
        'jwks_uri'  => env('AUTHENTIK_JWKS_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Provisioning
    |--------------------------------------------------------------------------
    |
    | Defaults applied to users created through SSO. These are written by a
    | global `creating` model event, so they apply to every user the host
    | application creates — not only those arriving through SSO.
    |
    */

    'provisioning' => [
        // Register the model observer that fills in password/level/status
        // defaults on creation. Disable to handle all of that yourself.
        'apply_defaults' => env('QUADSSO_APPLY_DEFAULTS', true),

        // Default role/level for newly created users.
        // Set to an empty value to leave the level column untouched.
        'default_user_level' => env('QUADSSO_DEFAULT_USER_LEVEL', 'user'),

        // Default status for newly created users
        'default_user_status' => env('QUADSSO_DEFAULT_USER_STATUS', 'active'),

        // User model column storing the role/level.
        // The level migration creates whatever column is named here.
        'user_level_field' => env('QUADSSO_USER_LEVEL_FIELD', 'level'),

        // User model column storing the account status
        'user_status_field' => env('QUADSSO_USER_STATUS_FIELD', 'status'),

        // Status value that denies login. Within the package only the management
        // API writes it; otherwise set it from your own admin tooling.
        //
        // The status attribute must be resolvable (a column, or an accessor) or
        // logins are REFUSED: an unreadable status reads back as null, which
        // would never match this value and would silently admit blocked users.
        // Set this empty to opt out of status-based blocking altogether.
        'blocked_status_value' => env('QUADSSO_BLOCKED_STATUS_VALUE', 'blocked'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Field Mappings
    |--------------------------------------------------------------------------
    |
    | Map identity-provider claims to your User model columns.
    | Set to null to disable a mapping (the claim is then ignored).
    |
    | Defaults work with Laravel's standard users table. The extended-fields
    | migration adds name_first/name_last/name_middle/phone_cell/email_secondary
    | if you prefer split columns — enable those mappings after running it.
    |
    */

    'field_mappings' => [
        // Core fields (required)
        'email' => 'email',
        'external_id' => 'scim_external_id',
        'email_verified_at' => 'email_verified_at',

        // Split name columns — null unless you ran the extended-fields migration
        'name_first' => null,
        'name_last' => null,
        'name_middle' => null,

        // Laravel's default single 'name' column. The IdP display name is written
        // to it as-is. Set to null if using the split columns above.
        'name' => 'name',

        // Contact fields — null unless you ran the extended-fields migration
        'phone_cell' => null,
        'email_secondary' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | SSO Configuration
    |--------------------------------------------------------------------------
    */

    'sso' => [
        // Mark email as verified on first SSO login. Only applies when the IdP
        // itself asserts email_verified=true.
        'auto_verify_email' => env('SSO_AUTO_VERIFY_EMAIL', true),

        // URL path to redirect to after successful login
        'redirect_after_login' => env('SSO_REDIRECT_AFTER_LOGIN', '/home'),

        // URL path to redirect to after failed login
        'redirect_after_failure' => env('SSO_REDIRECT_AFTER_FAILURE', '/login'),

        // Enable back-channel Single Logout (SLO)
        'enable_slo' => env('SSO_ENABLE_SLO', true),

        // Cycle the remember token on SLO so "remember me" cookies stop working
        'invalidate_remember_tokens_on_slo' => env('SSO_INVALIDATE_REMEMBER_TOKENS_ON_SLO', true),

        /*
        | Issue a "remember me" cookie on SSO login.
        |
        | Default: false. A remember cookie outlives the session — five years in
        | Laravel's default configuration — which keeps someone signed in long
        | after the IdP has deprovisioned them. With it off, the exposure after
        | deprovisioning is bounded by SESSION_LIFETIME, because the next login
        | attempt has to go back through the IdP.
        |
        | Enable only if you have a working revocation path (back-channel SLO
        | that your IdP actually fires on deactivation) and accept the tradeoff.
        */
        'remember_login' => env('SSO_REMEMBER_LOGIN', false),

        /*
        | Refresh name and email from the IdP on every login.
        |
        | Default: true. Without this, a user row keeps whatever values it was
        | created with and a rename at the IdP never reaches the application.
        | Email is only followed when the IdP asserts email_verified=true and no
        | other local user already holds the address.
        */
        'sync_attributes_on_login' => env('SSO_SYNC_ATTRIBUTES_ON_LOGIN', true),

        /*
        | Just-In-Time (JIT) Provisioning: automatically create users on first SSO login
        |
        | Default: false. When enabled, users are created in the local database
        | during their first SSO login if they don't already exist, using data
        | from the OIDC response. The IdP must assert `email_verified=true`.
        |
        | SECURITY: this is an access-control decision. Every user who authenticates
        | at the IdP and is assigned to this application gets a local account, so the
        | effective gate becomes your Authentik policy bindings. For access granted
        | explicitly inside this application, leave it off and create users yourself,
        | letting allow_legacy_email_binding attach them on first login.
        */
        'enable_jit_provisioning' => env('SSO_ENABLE_JIT_PROVISIONING', false),

        /*
        | Legacy bootstrap: bind a user to their IdP identity by email on first login.
        |
        | Default: true. Allows users to sign in even if scim_external_id is not yet
        | populated — first-time SSO, or users created through your own admin tooling.
        | This is what makes manual provisioning work, so leave it on unless you rely
        | solely on JIT.
        |
        | When true: if no row matches the incoming `sub`, fall back to a row that
        | matches by email AND has scim_external_id IS NULL — then bind the sub onto
        | that row. The IdP must assert `email_verified=true` for this fallback to fire.
        |
        | SECURITY: This is safe for most deployments because:
        | - Requires email_verified=true from the IdP (not self-asserted)
        | - Only binds once (when scim_external_id is NULL)
        | - After binding, identity is ALWAYS resolved by sub (not email)
        |
        | IMPORTANT: If your application allows self-service email change WITHOUT
        | re-verification, set this to false — and note that doing so leaves JIT as
        | the only way a user can ever be attached to their IdP identity.
        */
        'allow_legacy_email_binding' => env('SSO_ALLOW_LEGACY_EMAIL_BINDING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Management API
    |--------------------------------------------------------------------------
    |
    | Opt-in POST endpoint for out-of-band account actions, authenticated by a
    | shared key. Two actions, both identifying the user by email:
    |
    |   {"action": "SUSPEND", "email": "user@example.com"}
    |   {"action": "DELETE",  "email": "user@example.com"}
    |
    | SUSPEND clears sessions, cycles the remember token, and sets the status
    | column to blocked_status_value. DELETE does that and then removes the row.
    |
    | The route is not registered at all while this is disabled, so the path
    | 404s rather than advertising itself.
    |
    */

    'management' => [
        'enabled' => env('QUADSSO_MGMT_ENABLED', false),

        // Shared secret. The endpoint answers 503 until this is set — it is
        // never served unauthenticated.
        'api_key' => env('QUADSSO_MGMT_API_KEY'),

        // Header carrying the key. An Authorization: Bearer token is accepted
        // as well, so callers that only speak bearer auth need no special client.
        'header' => env('QUADSSO_MGMT_HEADER', 'X-QuadSSO-Key'),

        'path' => env('QUADSSO_MGMT_PATH', 'api/quadsso-mgr'),

        /*
        | Optional application code run around each action. Point this at a class
        | extending QuadCompanies\QuadSSO\Support\NullUserLifecycleHooks and
        | override only the hooks you need. Resolved from the container, so
        | constructor injection works.
        |
        | Throwing from a `before` hook vetoes the operation (nothing is written,
        | the API responds 409). Throwing from an `after` hook cannot roll the
        | change back; it is logged and flagged as post_hook_failed in the
        | response.
        */
        'hooks' => env('QUADSSO_MGMT_HOOKS'),

        // Bypass soft deletes on DELETE so the row really leaves the table.
        'force_delete' => env('QUADSSO_MGMT_FORCE_DELETE', true),

        // Extra middleware for the endpoint. Rate limiting is worthwhile on a
        // destructive route; set to [] to opt out.
        'middleware' => ['throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    |
    | Default label for the <x-quadsso::login-button /> component. Overridable
    | per usage by passing a :text prop or slot content; set here to relabel it
    | everywhere at once ("Sign in with Acme ID").
    |
    */

    'ui' => [
        'button_label' => env('QUADSSO_BUTTON_LABEL', 'Login via SSO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disable Local Authentication Routes
    |--------------------------------------------------------------------------
    |
    | Opt-in. Blocks the host application's registration and password-reset
    | routes so the identity provider stays the only way in.
    |
    | This matters most for password reset. Users provisioned through SSO get a
    | random, unusable password; if the reset flow stays reachable they can set
    | one they know at their IdP-verified address and authenticate locally from
    | then on — bypassing the IdP, including any deactivation there.
    |
    | Matching happens at request time against the resolved route name, then
    | against URI patterns for routes that have no name. An application with a
    | bespoke registration controller on an unlisted path will not be caught, so
    | treat this as defence in depth rather than a guarantee. The durable fix is
    | to disable password authentication in the application itself.
    |
    */

    'disable_local_auth' => [
        'enabled' => env('QUADSSO_DISABLE_LOCAL_AUTH', false),

        // 'redirect' sends the visitor to redirect_to; '404' pretends the route
        // was never there.
        'response' => env('QUADSSO_LOCAL_AUTH_RESPONSE', 'redirect'),

        'redirect_to' => env('QUADSSO_LOCAL_AUTH_REDIRECT', '/auth/sso'),

        /*
        | Route names, covering the Breeze, Fortify, Jetstream and Laravel UI
        | conventions. Note 'password.update' means the reset submission in
        | Laravel UI but the authenticated "change my password" screen in
        | Breeze — blocking both is intended for an SSO-only application.
        |
        | 'login' is deliberately absent: blocking it removes your break-glass
        | path if SSO itself breaks. Add it only if you are certain.
        | 'password.confirm' is also absent — it verifies an existing password
        | rather than setting one, so it is not a bypass, and blocking it breaks
        | Jetstream/Fortify flows that gate sensitive actions behind it.
        */
        'route_names' => [
            'register',
            'register.store',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'password.update',
        ],

        // URI patterns (Request::is syntax) for routes registered without names.
        'paths' => [
            'register',
            'register/*',
            'password/*',
            'forgot-password',
            'reset-password',
            'reset-password/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'sso_events' => env('QUADSSO_LOG_SSO_EVENTS', false),
        'slo_events' => env('QUADSSO_LOG_SLO_EVENTS', true),
    ],

];
