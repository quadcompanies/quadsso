<?php

use Illuminate\Support\Facades\Route;
use QuadCompanies\QuadSSO\Controllers\SsoController;

/*
|--------------------------------------------------------------------------
| QuadSSO Routes
|--------------------------------------------------------------------------
|
| These routes handle SSO authentication and Single Logout.
| SCIM routes are automatically registered by the laravel-scim-server package.
|
*/

// SSO — redirect is guest-only; callback has no middleware (OAuth state may arrive when session differs)
Route::middleware('guest')->get('auth/sso', [SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');

// OIDC Back-Channel Single Logout — no auth middleware, called server-to-server by authentik
Route::post('auth/sso/logout', [SsoController::class, 'slo'])->name('sso.logout');
