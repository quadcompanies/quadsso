<?php

namespace QuadCompanies\QuadSSO;

use ArieTimmerman\Laravel\SCIMServer\SCIMConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use QuadCompanies\QuadSSO\Scim\QuadSSOScimConfig;
use SocialiteProviders\Manager\SocialiteWasCalled;

class QuadSSOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__ . '/../config/quadsso.php', 'quadsso');

        // Register custom SCIM config
        $this->app->bind(SCIMConfig::class, QuadSSOScimConfig::class);
    }

    public function boot(): void
    {
        // Route file applies its own middleware per route — see routes/quadsso.php
        // for why SLO must stay outside the 'web' group (CSRF would reject every
        // back-channel logout from Authentik with HTTP 419).
        $this->loadRoutesFrom(__DIR__ . '/../routes/quadsso.php');

        // Register migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/quadsso.php' => config_path('quadsso.php'),
        ], 'quadsso-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'quadsso-migrations');

        // CRITICAL SECURITY: Auto-configure SCIM middleware if not already set
        // This prevents SCIM endpoints from being publicly accessible
        $this->configureScimSecurity();

        // Register the authentik Socialite provider
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event) {
            $event->extendSocialite('authentik', \SocialiteProviders\Authentik\Provider::class);
        });

        // Set up User model observer for SCIM provisioning
        $this->registerUserObserver();

        // Validate schema configuration
        $this->validateSchemaConfiguration();
    }

    /**
     * Configure SCIM security middleware automatically
     * This prevents SCIM endpoints from being publicly accessible by default
     */
    protected function configureScimSecurity(): void
    {
        // Only auto-configure if SCIM config hasn't been published yet
        // or if the middleware is still set to default (empty array or not set)
        $currentMiddleware = config('scim.middleware', []);

        // If middleware is empty or default, set our security middleware
        if (empty($currentMiddleware)) {
            config([
                'scim.middleware' => [
                    \QuadCompanies\QuadSSO\Middleware\ScimBearerToken::class
                ]
            ]);
        }
    }

    /**
     * Register User model observer to handle SCIM-provisioned users
     */
    protected function registerUserObserver(): void
    {
        if (!config('quadsso.scim.auto_provision', true)) {
            return;
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);

        if (!class_exists($userModel)) {
            return;
        }

        $userModel::creating(function ($user) {
            // Ensure SCIM-provisioned users (which arrive without a password) get a random one
            if (empty($user->password)) {
                $user->password = Hash::make(Str::random(32));
            }

            // Set default user level/role if configured
            if ($defaultLevel = config('quadsso.scim.default_user_level')) {
                if (empty($user->{config('quadsso.scim.user_level_field', 'level')})) {
                    $user->{config('quadsso.scim.user_level_field', 'level')} = $defaultLevel;
                }
            }

            // Set default status if configured
            if ($defaultStatus = config('quadsso.scim.default_user_status')) {
                if (empty($user->{config('quadsso.scim.user_status_field', 'status')})) {
                    $user->{config('quadsso.scim.user_status_field', 'status')} = $defaultStatus;
                }
            }
        });
    }

    /**
     * Validate that configured field mappings match the database schema.
     * Logs warnings for missing columns instead of throwing exceptions to allow
     * developers to publish config first before running migrations.
     */
    protected function validateSchemaConfiguration(): void
    {
        // Skip validation in console commands (migrations, etc.) to avoid chicken-egg issues
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return;
        }

        // Skip if database isn't available yet
        try {
            if (!Schema::hasTable('users')) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $fieldMappings = config('quadsso.field_mappings', []);
        $missingColumns = [];

        // Check each non-null mapping to ensure the column exists
        foreach ($fieldMappings as $scimField => $dbColumn) {
            if ($dbColumn !== null && !Schema::hasColumn('users', $dbColumn)) {
                $missingColumns[] = [
                    'scim_field' => $scimField,
                    'column' => $dbColumn,
                ];
            }
        }

        if (!empty($missingColumns)) {
            $columnList = collect($missingColumns)
                ->map(fn($item) => "'{$item['column']}' (mapped from SCIM '{$item['scim_field']}')")
                ->join(', ');

            Log::warning(
                "QuadSSO: Missing database columns in 'users' table: $columnList. " .
                "SCIM provisioning may fail. Either add these columns via migration, " .
                "or set their mappings to null in config/quadsso.php to disable them."
            );
        }
    }
}
