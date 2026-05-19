<?php

use Illuminate\Support\Facades\Route;
use QuadCompanies\QuadSSO\Controllers\SsoController;

/*
|--------------------------------------------------------------------------
| QuadSSO Routes
|--------------------------------------------------------------------------
|
| Two distinct middleware stacks:
|
|   - SSO redirect + callback need session state (OAuth `state` parameter and
|     CSRF token cookie), so they run inside the 'web' middleware group.
|
|   - Back-channel SLO is server-to-server. Authentik POSTs a signed JWT with
|     no browser session and no CSRF token; the route MUST be outside 'web'
|     or VerifyCsrfToken will reject every request with HTTP 419.
|
| SCIM routes are registered by arietimmerman/laravel-scim-server with their
| own middleware stack (config/scim.php).
|
*/

Route::middleware(['web', 'guest'])
    ->get('auth/sso', [SsoController::class, 'redirect'])
    ->name('sso.redirect');

Route::middleware('web')
    ->get('auth/sso/callback', [SsoController::class, 'callback'])
    ->name('sso.callback');

// Back-channel Single Logout — server-to-server, intentionally NOT under 'web'.
// No session, no CSRF. Authentication is JWT signature + issuer/audience/jti.
Route::post('auth/sso/logout', [SsoController::class, 'slo'])->name('sso.logout');
