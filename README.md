# QuadSSO

A Laravel package for SSO integration with Authentik using SCIM provisioning.

## Features

- **SCIM User Provisioning**: Automatically sync users from Authentik to your Laravel application
- **JIT (Just-In-Time) Provisioning**: Optionally create users on their first SSO login without SCIM (opt-in)
- **SSO Authentication**: OAuth/OIDC-based single sign-on with Authentik
- **Single Logout (SLO)**: Back-channel logout support to invalidate sessions when users log out from Authentik
- **Configurable**: Control user creation, updates, deletion, and field mappings via configuration
- **Session Management**: Automatically invalidate sessions when users are blocked
- **Flexible Field Mappings**: Map Authentik/SCIM fields to your custom User model fields

## Requirements

- PHP 8.1 or higher
- Laravel 10.0, 11.0, 12.0, or 13.0
- Authentik instance with SCIM and OAuth configured

## Installation

### 1. Install via Composer

```bash
composer require quadcompanies/quadsso
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=quadsso-config
```

This will create `config/quadsso.php` where you can customize all settings.

### 3. Run Migrations

The package includes a migration to add required fields to your `users` table:

```bash
php artisan migrate
```

This adds:
- `scim_external_id` - Stores the Authentik user UUID
- `email_verified_at` - Standard Laravel email verification field (if not already present)
- `status` - User status field (default: 'active') for SCIM user blocking

**The package works out-of-the-box with Laravel's standard users table** (single `name` field). SCIM's `givenName` and `familyName` are automatically combined into the `name` column.

### 4. Update Your User Model

Ensure your User model includes the necessary fields in `$fillable`:

```php
protected $fillable = [
    'email',
    'password',
    'scim_external_id',
    'email_verified_at',
    'status', // or whatever field you use for user status
    'name_first',
    'name_last',
    'name_middle',
    'phone_cell',
    'email_secondary',
    // ... other fields
];
```

### 5. Add Authentik to Services Config

Add the following to your `config/services.php`:

```php
'authentik' => [
    'client_id'     => env('AUTHENTIK_CLIENT_ID'),
    'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
    'redirect'      => env('AUTHENTIK_REDIRECT_URI'),
    'base_url'      => env('AUTHENTIK_BASE_URL'),
    'jwks_uri'      => env('AUTHENTIK_JWKS_URI'),
    'logout_url'    => env('AUTHENTIK_LOGOUT_URL'),
],
```

### 6. Configure Environment Variables

Add these to your `.env` file:

```env
# Authentik OAuth/OIDC Configuration
AUTHENTIK_CLIENT_ID=your-client-id
AUTHENTIK_CLIENT_SECRET=your-client-secret
AUTHENTIK_REDIRECT_URI=https://your-app.com/auth/sso/callback
AUTHENTIK_BASE_URL=https://authentik.your-domain.com
AUTHENTIK_JWKS_URI=https://authentik.your-domain.com/application/o/your-app/jwks/
AUTHENTIK_LOGOUT_URL=https://authentik.your-domain.com/application/o/your-app/end-session/

# SCIM Configuration
SCIM_ENABLED=true
SCIM_BASE_PATH=/scim
SCIM_BEARER_TOKEN=your-secure-random-token

# SCIM Feature Flags
SCIM_AUTO_PROVISION=true
SCIM_ALLOW_USER_CREATION=true
SCIM_ALLOW_USER_UPDATES=true
SCIM_ALLOW_USER_DELETION=true
SCIM_INVALIDATE_SESSIONS_ON_BLOCK=true

# SCIM User Defaults
SCIM_DEFAULT_USER_LEVEL=user
SCIM_DEFAULT_USER_STATUS=active

# SSO Configuration
SSO_AUTO_VERIFY_EMAIL=true
SSO_REDIRECT_AFTER_LOGIN=/home
SSO_REDIRECT_AFTER_FAILURE=/login
SSO_ENABLE_SLO=true
SSO_INVALIDATE_REMEMBER_TOKENS_ON_SLO=true
SSO_ENABLE_JIT_PROVISIONING=false

# Logging (optional, for debugging)
QUADSSO_LOG_SCIM_REQUESTS=false
QUADSSO_LOG_SSO_EVENTS=false
QUADSSO_LOG_SLO_EVENTS=true
```

### 7. Publish SCIM Configuration (Optional)

If you want to customize the SCIM server configuration:

```bash
php artisan vendor:publish --provider="ArieTimmerman\Laravel\SCIMServer\SCIMServerServiceProvider" --tag=scim
```

Then update `config/scim.php`:

```php
return [
    'publish_routes' => true,
    'omit_main_schema_in_return' => false,
    'omit_null_values' => true,
    
    'path' => env('SCIM_BASE_PATH', '/scim'),
    'domain' => env('SCIM_DOMAIN', null),
    
    'middleware' => [\QuadCompanies\QuadSSO\Middleware\ScimBearerToken::class],
    'public_middleware' => [],
    
    'bearer_token' => env('SCIM_BEARER_TOKEN'),
    
    'pagination' => [
        'defaultPageSize' => 10,
        'maxPageSize' => 100,
        'cursorPaginationEnabled' => true,
    ],
    
    'authenticationSchemes' => [
        'oauthbearertoken',
    ],
];
```

## Configuration

### Field Mappings

Customize how SCIM/Authentik fields map to your User model in `config/quadsso.php`:

```php
'field_mappings' => [
    'email' => 'email',
    'external_id' => 'scim_external_id',
    'email_verified_at' => 'email_verified_at',
    'name_first' => 'name_first',
    'name_last' => 'name_last',
    'name_middle' => 'name_middle',
    'phone_cell' => 'phone_cell',
    'email_secondary' => 'email_secondary',
],
```

### User Status Management

Configure how user status is handled:

```php
'scim' => [
    'user_status_field' => 'status', // Your User model's status field
    'active_status_value' => 'active', // Value that means "active"
    'blocked_status_value' => 'blocked', // Value that means "blocked"
    'invalidate_sessions_on_block' => true, // Kill sessions when user is blocked
],
```

### Feature Flags

Control what SCIM operations are allowed:

```php
'scim' => [
    'allow_user_creation' => true,  // Allow creating new users via SCIM
    'allow_user_updates' => true,   // Allow updating existing users via SCIM
    'allow_user_deletion' => true,  // Allow blocking users via SCIM (active=false)
],
```

### JIT (Just-In-Time) Provisioning

Enable automatic user creation on first SSO login without requiring SCIM:

```php
'sso' => [
    'enable_jit_provisioning' => true,  // Automatically create users on first SSO login
],
```

Or via environment variable:

```env
SSO_ENABLE_JIT_PROVISIONING=true
```

**When JIT provisioning is enabled:**
- Users are automatically created during their first SSO login
- Requires the IdP to assert `email_verified=true` for security
- User data (email, name, external_id) is populated from the OAuth response
- If a user with the same email exists but has no `scim_external_id`, they will be bound to that account

**Use cases:**
- Internal company applications where all IdP users should have access
- Environments where you trust your IdP's authentication and want seamless onboarding
- Migration scenarios where you're transitioning from manual user management to IdP-based auth

**Note:** You can use JIT provisioning alongside SCIM. SCIM will handle bulk provisioning and updates, while JIT acts as a fallback for new users who haven't been synced yet.

## Authentik Setup

### 1. Create an OAuth Provider

In Authentik:
1. Go to **Applications** → **Providers** → **Create**
2. Select **OAuth2/OpenID Provider**
3. Configure:
   - **Name**: Your App Name
   - **Client Type**: Confidential
   - **Redirect URIs**: `https://your-app.com/auth/sso/callback`
   - **Signing Key**: Choose an appropriate certificate
   - Enable **Back-Channel Logout URL**: `https://your-app.com/auth/sso/logout`

### 2. Create an Application

1. Go to **Applications** → **Create**
2. Configure:
   - **Name**: Your App Name
   - **Slug**: your-app
   - **Provider**: Select the provider created above

### 3. Set Up SCIM

1. Go to **Applications** → **Providers** → **Create**
2. Select **SCIM Provider**
3. Configure:
   - **Name**: Your App SCIM
   - **URL**: `https://your-app.com/scim/v2`
   - **Token**: Your `SCIM_BEARER_TOKEN` value
   - **Exclude service accounts**: Checked

### 4. Bind SCIM Provider to Application

1. Edit your application
2. In the **Backchannel Providers** section, add your SCIM provider

### 5. Configure Property Mappings (Optional)

Map additional Authentik user fields to SCIM attributes as needed.

## Usage

### Login via SSO

Users can initiate SSO login by visiting:

```
https://your-app.com/auth/sso
```

Or add a login button to your login page:

```blade
<a href="{{ route('sso.redirect') }}" class="btn btn-primary">
    Login with SSO
</a>
```

### Routes

The package automatically registers these routes:

- `GET /auth/sso` - Initiate SSO login (named `sso.redirect`)
- `GET /auth/sso/callback` - OAuth callback (named `sso.callback`)
- `POST /auth/sso/logout` - Back-channel logout endpoint (named `sso.logout`)

SCIM routes are automatically registered by the `laravel-scim-server` package:
- `GET /scim/v2/Users` - List users
- `GET /scim/v2/Users/{id}` - Get user
- `POST /scim/v2/Users` - Create user
- `PUT /scim/v2/Users/{id}` - Update user
- `PATCH /scim/v2/Users/{id}` - Patch user
- `DELETE /scim/v2/Users/{id}` - Delete user (sets active=false)

## How It Works

### User Provisioning Flow

1. **User created in Authentik** → SCIM creates user in Laravel
2. **User updated in Authentik** → SCIM updates user in Laravel
3. **User blocked in Authentik** → SCIM sets user status to "blocked" and kills sessions
4. **User logs in** → OAuth redirects to Authentik → User authenticates → Callback creates session

### Single Logout Flow

1. **User logs out from Authentik** → Authentik sends back-channel logout JWT
2. **Laravel verifies JWT** → Finds user by `scim_external_id`
3. **Sessions deleted** → User is logged out from all devices
4. **Remember tokens cycled** → "Remember me" cookies are invalidated

### JIT (Just-In-Time) Provisioning Flow (Optional)

If you enable JIT provisioning with `SSO_ENABLE_JIT_PROVISIONING=true`, users will be automatically created on their first SSO login without needing SCIM:

1. **User logs in via SSO** → Doesn't exist in Laravel yet
2. **IdP verifies user** → Returns verified email and profile data
3. **Laravel creates user** → Automatically provisions user with data from IdP
4. **Session created** → User is logged in immediately

**Security considerations:**
- Requires `email_verified=true` from the IdP (prevents unverified email attacks)
- Checks for email collisions before creating users
- Can bind to existing users that have no `scim_external_id` yet
- Best suited for environments where you trust all IdP-authenticated users

**When to use JIT vs SCIM:**
- **Use JIT** when you want open access for any authenticated IdP user (e.g., internal company apps)
- **Use SCIM** when you need explicit control over who can access your app (e.g., customer-facing SaaS)
- You can use both together: SCIM for bulk provisioning, JIT as a fallback for new users

## Customization

### Extended User Fields (Optional)

By default, QuadSSO maps SCIM name fields to Laravel's standard single `name` column. If you want separate fields for first/last/middle names and additional contact fields:

**1. Run the optional extended fields migration:**

```bash
php artisan migrate --path=vendor/quadcompanies/quadsso/database/migrations/2024_01_01_000002_add_extended_quadsso_fields_to_users_table.php
```

This adds: `name_first`, `name_last`, `name_middle`, `phone_cell`, `email_secondary`

**2. Update `config/quadsso.php` to enable these mappings:**

```php
'field_mappings' => [
    'email' => 'email',
    'external_id' => 'scim_external_id',
    'email_verified_at' => 'email_verified_at',
    
    // Enable extended name fields
    'name_first' => 'name_first',   // Changed from null
    'name_last' => 'name_last',     // Changed from null
    'name_middle' => 'name_middle', // Changed from null
    'name' => null,                 // Disable single name field
    
    // Enable contact fields
    'phone_cell' => 'phone_cell',           // Changed from null
    'email_secondary' => 'email_secondary', // Changed from null
],
```

**3. Add to User model's `$fillable`:**

```php
protected $fillable = [
    'email',
    'password',
    'scim_external_id',
    'email_verified_at',
    'status',
    'name_first',
    'name_last',
    'name_middle',
    'phone_cell',
    'email_secondary',
];
```

> **⚠️ Schema Validation:** The package automatically checks if configured field mappings exist in your database schema. If you see warnings in your logs about missing columns, either run the extended migration or set those mappings to `null` in the config.

### Custom User Model

If you use a custom user model, update `config/quadsso.php`:

```php
'user_model' => \App\Models\CustomUser::class,
```

### Disable Auto-Provisioning

If you want to manually handle user creation instead of the automatic observer:

```php
'scim' => [
    'auto_provision' => false,
],
```

### Custom Redirect Routes

Change where users are redirected after login/logout:

```php
'sso' => [
    'redirect_after_login' => '/dashboard',
    'redirect_after_failure' => '/login',
],
```

### Additional Field Mappings

If your User model has custom fields, add them to the SCIM configuration by extending `QuadSSOScimConfig`:

```php
namespace App\Scim;

use QuadCompanies\QuadSSO\Scim\QuadSSOScimConfig;

class CustomScimConfig extends QuadSSOScimConfig
{
    // Override methods to add custom field mappings
}
```

Then bind your custom config in `AppServiceProvider`:

```php
use ArieTimmerman\Laravel\SCIMServer\SCIMConfig;
use App\Scim\CustomScimConfig;

public function register(): void
{
    $this->app->bind(SCIMConfig::class, CustomScimConfig::class);
}
```

## Troubleshooting

### Enable Debug Logging

Set these in your `.env`:

```env
QUADSSO_LOG_SCIM_REQUESTS=true
QUADSSO_LOG_SSO_EVENTS=true
QUADSSO_LOG_SLO_EVENTS=true
```

Then check `storage/logs/laravel.log` for detailed logs.

### Common Issues

#### "SCIM bearer token not configured"

Make sure `SCIM_BEARER_TOKEN` is set in your `.env` file.

#### "No account found for this identity"

The user hasn't been provisioned via SCIM yet. Make sure:
1. SCIM provider is configured in Authentik
2. SCIM provider is bound to your application
3. User exists in Authentik and is assigned to the application

#### "Your account has been suspended"

The user's status field is set to the blocked value. Check:
1. User's status in the database
2. `SCIM_ACTIVE_STATUS_VALUE` and `SCIM_BLOCKED_STATUS_VALUE` settings

#### Sessions not being invalidated on logout

Make sure:
1. `SSO_ENABLE_SLO=true` in your `.env`
2. Back-channel logout URL is configured in Authentik
3. JWKS URI is correct and accessible

## Security

### 🔒 SCIM Endpoint Protection

**The package automatically secures SCIM endpoints** with bearer token authentication. The `ScimBearerToken` middleware is auto-configured to protect all `/scim/v2/*` routes.

**To verify security is working:**

```bash
# Should return 401 Unauthorized
curl http://your-app.local/scim/v2/Users

# Should return user data (with valid token)
curl -H "Authorization: Bearer your-token-here" http://your-app.local/scim/v2/Users
```

### Best Practices

- ✅ Always use HTTPS in production
- ✅ Generate a strong random token for `SCIM_BEARER_TOKEN`:
  ```bash
  php artisan tinker --execute="echo \Illuminate\Support\Str::random(64);"
  ```
- ✅ Keep your `SCIM_BEARER_TOKEN` secure - treat it like a password
- ✅ Regularly rotate your Authentik client secrets and SCIM tokens
- ✅ Monitor your logs for unauthorized SCIM access attempts (enable `QUADSSO_LOG_SCIM_REQUESTS=true`)
- ✅ Use firewall rules to restrict SCIM endpoint access to Authentik's IP addresses if possible

## License

MIT

## Support

For issues and questions, please open an issue on GitHub.

## Credits

Built by Quad Companies using:
- [laravel-scim-server](https://github.com/limosa-io/laravel-scim-server) by Arie Timmerman
- [socialite](https://github.com/laravel/socialite) by Laravel
- [socialiteproviders/authentik](https://github.com/SocialiteProviders/Authentik) by SocialiteProviders
