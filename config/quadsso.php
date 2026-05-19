<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The User model class that will be used for authentication and SCIM provisioning.
    |
    */

    'user_model' => env('QUADSSO_USER_MODEL', \App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Authentik Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Authentik OAuth/OIDC provider.
    |
    */

    'authentik' => [
        'client_id'     => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect'      => env('AUTHENTIK_REDIRECT_URI', env('APP_URL') . '/auth/sso/callback'),
        'base_url'      => env('AUTHENTIK_BASE_URL'),
        'jwks_uri'      => env('AUTHENTIK_JWKS_URI'),
        'logout_url'    => env('AUTHENTIK_LOGOUT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SCIM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SCIM server integration.
    |
    */

    'scim' => [
        // Enable/disable SCIM endpoints
        'enabled' => env('SCIM_ENABLED', true),

        // SCIM base path (e.g., /scim)
        'path' => env('SCIM_BASE_PATH', '/scim'),

        // SCIM domain (optional)
        'domain' => env('SCIM_DOMAIN', null),

        // Bearer token for SCIM API authentication
        'bearer_token' => env('SCIM_BEARER_TOKEN'),

        // Auto-provision users when they don't exist
        'auto_provision' => env('SCIM_AUTO_PROVISION', true),

        // Allow SCIM to create new users
        'allow_user_creation' => env('SCIM_ALLOW_USER_CREATION', true),

        // Allow SCIM to delete/block users
        'allow_user_deletion' => env('SCIM_ALLOW_USER_DELETION', true),

        // Allow SCIM to update user information
        'allow_user_updates' => env('SCIM_ALLOW_USER_UPDATES', true),

        // Invalidate user sessions when they are blocked via SCIM
        'invalidate_sessions_on_block' => env('SCIM_INVALIDATE_SESSIONS_ON_BLOCK', true),

        /*
        | Legacy bootstrap: allow SCIM POST to bind to an existing user row by email
        | when that row has no scim_external_id yet.
        |
        | Default: false. SCIM POST creates a new user. If a row already exists with
        | the same userName/email and a DIFFERENT externalId (or any externalId), SCIM
        | responds 409 (uniqueness) per RFC 7644.
        |
        | When true: if no row matches by externalId, fall back to matching by email —
        | but ONLY when that row has scim_external_id IS NULL. Lets you onboard
        | pre-SSO users into the IdP once. Re-enables the email-based identity flow
        | that earlier versions used unconditionally; only enable during migration.
        */
        'allow_legacy_email_merge' => env('SCIM_ALLOW_LEGACY_EMAIL_MERGE', false),

        // Default user level/role for new SCIM-provisioned users
        'default_user_level' => env('SCIM_DEFAULT_USER_LEVEL', 'user'),

        // Default user status for new SCIM-provisioned users
        'default_user_status' => env('SCIM_DEFAULT_USER_STATUS', 'active'),

        // User model field that stores the user level/role
        'user_level_field' => env('SCIM_USER_LEVEL_FIELD', 'level'),

        // User model field that stores the user status
        'user_status_field' => env('SCIM_USER_STATUS_FIELD', 'status'),

        // Active status value (what value indicates user is active)
        'active_status_value' => env('SCIM_ACTIVE_STATUS_VALUE', 'active'),

        // Blocked status value (what value indicates user is blocked)
        'blocked_status_value' => env('SCIM_BLOCKED_STATUS_VALUE', 'blocked'),

        // SCIM pagination settings
        'pagination' => [
            'defaultPageSize' => 10,
            'maxPageSize' => 100,
            'cursorPaginationEnabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Field Mappings
    |--------------------------------------------------------------------------
    |
    | Map SCIM/Authentik fields to your User model fields.
    | Set to null to disable mapping for that field (the SCIM attribute will be ignored).
    |
    | Default mappings work with Laravel's standard users table schema.
    | If you have custom columns (name_first, name_last, phone_cell, etc.),
    | uncomment and configure those mappings below.
    |
    */

    'field_mappings' => [
        // Core fields (required)
        'email' => 'email',
        'external_id' => 'scim_external_id',
        'email_verified_at' => 'email_verified_at',

        // Name fields - Laravel's default users table only has a single 'name' column
        // To use these, add the columns to your users table first:
        //   php artisan make:migration add_name_fields_to_users_table
        'name_first' => null,  // Set to 'name_first' if you have this column
        'name_last' => null,   // Set to 'name_last' if you have this column
        'name_middle' => null, // Set to 'name_middle' if you have this column

        // Full name mapping (uses Laravel's default 'name' column)
        // This is a computed field that combines firstName + lastName from SCIM
        'name' => 'name',

        // Contact fields - Not in Laravel's default schema
        // To use these, add the columns to your users table first
        'phone_cell' => null,      // Set to 'phone_cell' if you have this column
        'email_secondary' => null, // Set to 'email_secondary' if you have this column
    ],

    /*
    |--------------------------------------------------------------------------
    | SSO Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Single Sign-On behavior.
    |
    */

    'sso' => [
        // Auto-verify email on first SSO login
        'auto_verify_email' => env('SSO_AUTO_VERIFY_EMAIL', true),

        // Route to redirect to after successful login
        'redirect_after_login' => env('SSO_REDIRECT_AFTER_LOGIN', '/home'),

        // Route to redirect to after failed login
        'redirect_after_failure' => env('SSO_REDIRECT_AFTER_FAILURE', '/login'),

        // Enable Single Logout (SLO)
        'enable_slo' => env('SSO_ENABLE_SLO', true),

        // Invalidate remember tokens on SLO
        'invalidate_remember_tokens_on_slo' => env('SSO_INVALIDATE_REMEMBER_TOKENS_ON_SLO', true),

        /*
        | Legacy bootstrap: bind a user to their IdP identity by email on first login.
        |
        | Default: false. Identity is resolved by the OIDC `sub` claim, stored in
        | scim_external_id. Email is NEVER used to resolve identity once a binding
        | exists.
        |
        | When true: if no row matches the incoming `sub`, fall back to a row that
        | matches by email AND has scim_external_id IS NULL — then bind the sub onto
        | that row. The IdP must assert `email_verified=true` for this fallback to fire.
        |
        | This enables one-time migration of pre-SSO users, but exposes the host
        | application to email-based account-takeover IF self-service email change is
        | not also disabled. Only enable during migration, then turn it back off.
        */
        'allow_legacy_email_binding' => env('SSO_ALLOW_LEGACY_EMAIL_BINDING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for debugging.
    |
    */

    'logging' => [
        'scim_requests' => env('QUADSSO_LOG_SCIM_REQUESTS', false),
        'sso_events' => env('QUADSSO_LOG_SSO_EVENTS', false),
        'slo_events' => env('QUADSSO_LOG_SLO_EVENTS', true),
    ],

];
