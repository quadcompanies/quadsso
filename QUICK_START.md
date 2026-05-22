# QuadSSO Quick Start Guide

This guide will help you get QuadSSO up and running in 10 minutes.

## Prerequisites

- Laravel 11 or 12 application
- Authentik instance with admin access
- User model with basic authentication

## Step 1: Install the Package (2 minutes)

```bash
composer require quadcompanies/quadsso
php artisan vendor:publish --tag=quadsso-config
php artisan migrate
```

> **🔒 SECURITY NOTE:** The package automatically secures SCIM endpoints with bearer token authentication. Make sure to set a strong `SCIM_BEARER_TOKEN` in the next step.

## Step 2: Configure Environment (3 minutes)

Add to your `.env`:

```env
# Get these from Authentik after creating the OAuth provider
AUTHENTIK_CLIENT_ID=your-client-id
AUTHENTIK_CLIENT_SECRET=your-client-secret
AUTHENTIK_REDIRECT_URI=https://your-app.com/auth/sso/callback
AUTHENTIK_BASE_URL=https://authentik.your-domain.com
AUTHENTIK_JWKS_URI=https://authentik.your-domain.com/application/o/your-app/jwks/
AUTHENTIK_LOGOUT_URL=https://authentik.your-domain.com/application/o/your-app/end-session/

# Generate a secure random token for SCIM (REQUIRED FOR SECURITY)
# Use: php artisan tinker --execute="echo \Illuminate\Support\Str::random(64);"
SCIM_BEARER_TOKEN=your-secure-64-character-random-token
```

Add to `config/services.php`:

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

## Step 3: Update User Model (1 minute)

Add to your User model's `$fillable` array:

```php
protected $fillable = [
    'name',              // Laravel's default (works out of the box)
    'email',
    'password',
    'scim_external_id',  // Added by migration
    'email_verified_at', // Added by migration
    'status',            // Added by migration
    // ... your other fields
];
```

> **✅ NOTE:** The package works with Laravel's standard users table schema (single `name` column). SCIM's `givenName` and `familyName` are automatically combined into the `name` field.
>
> **Optional:** If you want separate `name_first`, `name_last`, `name_middle`, `phone_cell`, and `email_secondary` columns, see the "Extended Fields" section in the full README.

## Step 4: Set Up Authentik (4 minutes)

### Create OAuth Provider

1. Go to **Applications** → **Providers** → **Create**
2. Select **OAuth2/OpenID Provider**
3. Set:
   - Name: `Your App OAuth`
   - Client Type: `Confidential`
   - Redirect URIs: `https://your-app.com/auth/sso/callback`
   - Enable Back-Channel Logout URL: `https://your-app.com/auth/sso/logout`
4. Save and note the **Client ID** and **Client Secret**

### Create Application

1. Go to **Applications** → **Create**
2. Set:
   - Name: `Your App`
   - Slug: `your-app`
   - Provider: Select the provider you just created
3. Save

### Create SCIM Provider

1. Go to **Applications** → **Providers** → **Create**
2. Select **SCIM Provider**
3. Set:
   - Name: `Your App SCIM`
   - URL: `https://your-app.com/scim/v2`
   - Token: Your `SCIM_BEARER_TOKEN` value
   - Exclude service accounts: ✓ Checked
4. Save

### Bind SCIM to Application

1. Edit your application
2. Under **Backchannel Providers**, add your SCIM provider
3. Save

### Assign Users

1. Go to your application
2. Click **Edit** → **Assigned permissions**
3. Add users or groups who should have access

## Step 5: Test! (optional)

### Verify SCIM Security (IMPORTANT)

Test that SCIM endpoints are protected:

```bash
# Should return 401 Unauthorized
curl http://your-app.local/scim/v2/Users

# Should return user data (with valid token)
curl -H "Authorization: Bearer your-token-here" http://your-app.local/scim/v2/Users
```

### Test SSO Login

Visit: `https://your-app.com/auth/sso`

You should be redirected to Authentik, log in, and then be redirected back to your app.

### Test SCIM Provisioning

1. Create a new user in Authentik and assign them to your application
2. Check your Laravel database - the user should appear with `scim_external_id` populated
3. Block the user in Authentik - they should be blocked in Laravel and their sessions deleted

### Test Single Logout

1. Log in to your Laravel app via SSO
2. Log out from Authentik
3. Your Laravel session should be automatically terminated

## Common Configurations

### Custom User Model

In `config/quadsso.php`:

```php
'user_model' => \App\Models\CustomUser::class,
```

### Different Status Field

In `config/quadsso.php`:

```php
'scim' => [
    'user_status_field' => 'account_status',
    'active_status_value' => 'enabled',
    'blocked_status_value' => 'disabled',
],
```

### Disable User Creation

In `.env`:

```env
SCIM_ALLOW_USER_CREATION=false
```

### Custom Redirect After Login

In `.env`:

```env
SSO_REDIRECT_AFTER_LOGIN=/dashboard
```

### Enable JIT (Just-In-Time) Provisioning

Skip SCIM setup and create users automatically on first SSO login:

In `.env`:

```env
SSO_ENABLE_JIT_PROVISIONING=true
```

**Benefits:**
- No need to configure SCIM provider in Authentik
- Users are created automatically on first login
- Simpler setup for internal apps where all IdP users should have access

**Note:** With JIT enabled, you can skip Steps 4.3-4.5 (SCIM provider setup). Users will be created when they first log in via SSO.

## Troubleshooting

### "SCIM bearer token not configured"
- Make sure `SCIM_BEARER_TOKEN` is in your `.env`

### "No account found for this identity"
- User hasn't been synced via SCIM yet
- Check that SCIM provider is bound to the application
- Check that user is assigned to the application in Authentik
- **Alternative:** Enable JIT provisioning with `SSO_ENABLE_JIT_PROVISIONING=true` to create users automatically

### "Your account has been suspended"
- User's status field is set to blocked value
- Check user's status in database: `SELECT status FROM users WHERE email = '...';`

### Enable Debug Logging

In `.env`:

```env
QUADSSO_LOG_SCIM_REQUESTS=true
QUADSSO_LOG_SSO_EVENTS=true
QUADSSO_LOG_SLO_EVENTS=true
```

Check `storage/logs/laravel.log` for details.

## Next Steps

- Configure field mappings in `config/quadsso.php`
- Set up additional SCIM property mappings in Authentik
- Add SSO button to your login page
- Configure session timeout policies
- Set up monitoring for SCIM sync failures

## Need Help?

- Check the [full README](README.md) for detailed documentation
- Review the [configuration file](config/quadsso.php) for all options
- Open an issue on GitHub if you encounter problems
