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
    | Set to null to disable mapping for that field.
    |
    */

    'field_mappings' => [
        // Core fields
        'email' => 'email',
        'external_id' => 'scim_external_id',
        'email_verified_at' => 'email_verified_at',

        // Name fields
        'name_first' => 'name_first',
        'name_last' => 'name_last',
        'name_middle' => 'name_middle',

        // Contact fields
        'phone_cell' => 'phone_cell',
        'email_secondary' => 'email_secondary',
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
