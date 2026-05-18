<?php

namespace QuadCompanies\QuadSSO;

use ArieTimmerman\Laravel\SCIMServer\SCIMConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
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
        // Load routes
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

        // Register the authentik Socialite provider
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event) {
            $event->extendSocialite('authentik', \SocialiteProviders\Authentik\Provider::class);
        });

        // Set up User model observer for SCIM provisioning
        $this->registerUserObserver();
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
}
